<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\Command;

use Smichaelsen\MelonImages\Configuration\Registry;
use Smichaelsen\MelonImages\Domain\Dto\Dimensions;
use Smichaelsen\MelonImages\TcaUtility;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class CreateNeededCroppings extends Command
{
    /**
     * @var FlashMessageService
     */
    protected $flashMessageService;

    protected function configure()
    {
        $this->setDescription('Creates default cropping configuration where it is missing for image fields configured for MelonImages');
    }

    public function __construct(string $name = null)
    {
        parent::__construct($name);
        $this->flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $configurationRegistry = GeneralUtility::makeInstance(Registry::class);
        $configuration = $configurationRegistry->getParsedConfiguration();
        foreach ($configuration['croppingConfiguration'] as $tableName => $tableConfiguration) {
            foreach ($tableConfiguration as $type => $fields) {
                foreach ($fields as $fieldName => $fieldConfig) {
                    $tcaPath = [$tableName, (string)$type, $fieldName];
                    $this->createCroppingsForVariantsWithNestedRecords($tcaPath, $fieldConfig);
                }
            }
        }
        return 0;
    }

    protected function createCroppingsForVariantsWithNestedRecords(array $tcaPath, array $fieldConfig)
    {
        if (isset($fieldConfig['variants'])) {
            $this->createCroppingForVariants($tcaPath, $fieldConfig['variants'], implode('__', $tcaPath));
        } else {
            foreach ($fieldConfig as $subType => $subFields) {
                foreach ($subFields as $subFieldName => $subFieldConfig) {
                    $subFieldTcaPath = $tcaPath;
                    $subFieldTcaPath[] = $subType;
                    $subFieldTcaPath[] = $subFieldName;
                    $this->createCroppingsForVariantsWithNestedRecords($subFieldTcaPath, $subFieldConfig);
                }
            }
        }
    }

    protected function createCroppingForVariants(array $tcaPath, array $variants, string $variantIdPrefix, array $localUids = null)
    {
        $localTableName = array_shift($tcaPath);
        $type = array_shift($tcaPath);
        $fieldName = array_shift($tcaPath);
        $fieldTca = $this->getFieldTca($localTableName, $type, $fieldName);
        $foreignTableName = $this->getForeignTableName($fieldTca['config']);
        if ($foreignTableName === null) {
            return;
        }
        $foreignUids = $this->queryForeignUids($localTableName, $foreignTableName, $fieldTca['config'], $type, $localUids);
        if (count($foreignUids) === 0) {
            return;
        }
        if (count($tcaPath) > 0) {
            array_unshift($tcaPath, $foreignTableName);
            $this->createCroppingForVariants($tcaPath, $variants, $variantIdPrefix, $foreignUids);
            return;
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder
            ->select('ref.uid', 'ref.crop', 'file.extension', 'file.uid as fileUid', 'metadata.width', 'metadata.height')
            ->from('sys_file_reference', 'ref')
            ->join(
                'ref',
                'sys_file_metadata',
                'metadata',
                'metadata.file = ref.uid_local'
            )
            ->join(
                'ref',
                'sys_file',
                'file',
                'ref.uid_local = file.uid'
            )
            ->where(
                $queryBuilder->expr()->in('ref.uid', $foreignUids)
            );
        $croppingsCreated = 0;
        $fileReferenceRecords = $queryBuilder->execute()->fetchAll();
        foreach ($fileReferenceRecords as $fileReferenceRecord) {
            if ($fileReferenceRecord['extension'] === 'pdf') {
                $fileReferenceRecord = $this->handlePdfDimensions($fileReferenceRecord);
            }
            if ((int)$fileReferenceRecord['width'] === 0) {
                continue;
            }
            $cropConfiguration = json_decode($fileReferenceRecord['crop'], true) ?? [];
            foreach ($variants as $variant => $variantConfiguration) {
                $aspectRatioConfigs = TcaUtility::getAspectRatiosFromSizes($variantConfiguration['sizes']);
                foreach ($aspectRatioConfigs as $aspectRatioIdentifier => $aspectRatioConfig) {
                    $variantId = $variantIdPrefix . '__' . $variant . '__' . $aspectRatioIdentifier;
                    if (!isset($cropConfiguration[$variantId])) {
                        if (isset($aspectRatioConfig['allowedRatios'])) {
                            $ratioKeys = array_keys($aspectRatioConfig['allowedRatios']);
                            $defaultRatio = array_shift($ratioKeys);
                            $allowedRatioConfig = $aspectRatioConfig['allowedRatios'][$defaultRatio];
                            $dimensions = new Dimensions($allowedRatioConfig['width'], $allowedRatioConfig['height'], $allowedRatioConfig['ratio']);
                            $cropConfiguration[$variantId] = [
                                'cropArea' => $this->calculateCropArea(
                                    (int)$fileReferenceRecord['width'],
                                    (int)$fileReferenceRecord['height'],
                                    $dimensions->getRatio()
                                ),
                                'selectedRatio' => $defaultRatio,
                                'focusArea' => null,
                            ];
                        } else {
                            $cropConfiguration[$variantId] = [
                                'cropArea' => [
                                    'width' => 1,
                                    'height' => 1,
                                    'x' => 0,
                                    'y' => 0,
                                ],
                                'selectedRatio' => $fileReferenceRecord['width'] . ' x ' . $fileReferenceRecord['height'],
                                'focusArea' => null,
                            ];
                        }
                        $croppingsCreated++;
                    }
                }
            }
            $newCropValue = json_encode($cropConfiguration);
            if ($newCropValue === $fileReferenceRecord['crop']) {
                continue;
            }
            $queryBuilder
                ->resetQueryParts()
                ->update('sys_file_reference')
                ->set('crop', $newCropValue)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($fileReferenceRecord['uid'], \PDO::PARAM_INT))
                )->execute();
        }
        if ($croppingsCreated > 0) {
            $this->addFlashMessage($croppingsCreated . ' croppings created for ' . $variantIdPrefix, FlashMessage::OK);
        }
    }

    protected function getForeignTableName(array $fieldConfig): ?string
    {
        if ($fieldConfig['type'] === 'inline' && $fieldConfig['MM']) {
            // todo: Implement inline with MM
            return null;
        } elseif ($fieldConfig['type'] === 'inline') {
            return $fieldConfig['foreign_table'];
        } elseif ($fieldConfig['type'] === 'select') {
            // todo implement select
            return null;
        }
        return null;
    }

    protected function queryForeignUids(string $localTableName, string $foreignTableName, array $fieldConfig, string $localType, array $localUids = null): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($foreignTableName);
        if ($fieldConfig['type'] === 'inline' && $fieldConfig['MM']) {
            // todo: Implement inline with MM
            return [];
        } elseif ($fieldConfig['type'] === 'inline') {
            $queryBuilder
                ->select($foreignTableName . '.uid')
                ->from($foreignTableName);
            if (isset($fieldConfig['foreign_match_fields'])) {
                foreach ($fieldConfig['foreign_match_fields'] as $field => $value) {
                    $queryBuilder->andWhere($queryBuilder->expr()->eq($foreignTableName . '.' . $field, $queryBuilder->createNamedParameter($value)));
                }
            }
            if (isset($fieldConfig['foreign_table_field'])) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq($foreignTableName . '.' . $fieldConfig['foreign_table_field'], $queryBuilder->createNamedParameter($localTableName)));
            }
            if (is_array($localUids)) {
                $queryBuilder->andWhere($queryBuilder->expr()->in($foreignTableName . '.' . $fieldConfig['foreign_field'], $localUids));
            }

            $typeField = $GLOBALS['TCA'][$localTableName]['ctrl']['type'];
            $queryBuilder->join(
                $foreignTableName,
                $localTableName,
                $localTableName,
                $foreignTableName . '.' . $fieldConfig['foreign_field'] . ' = ' . $localTableName . '.uid'
            );
            if ($localType !== '_all') {
                $queryBuilder->andWhere($queryBuilder->expr()->eq($localTableName . '.' . $typeField, $queryBuilder->createNamedParameter($localType)));
            }
            return array_map(function (array $record) {
                return $record['uid'];
            }, $queryBuilder->execute()->fetchAll());
        } elseif ($fieldConfig['type'] === 'select') {
            // todo implement select
            return [];
        }
        return [];
    }

    protected function calculateCropArea(int $fileWidth, int $fileHeight, float $croppingRatio): array
    {
        $fileRatio = $fileWidth / $fileHeight;
        $croppedHeightValue = min(1, $fileRatio / $croppingRatio);
        $croppedWidthValue = min(1, $croppingRatio / $fileRatio);
        return [
            'width' => $croppedWidthValue,
            'height' => $croppedHeightValue,
            'x' => (1 - $croppedWidthValue) / 2,
            'y' => (1 - $croppedHeightValue) / 2,
        ];
    }

    protected function getFieldTca(string $table, string $type, string $fieldName)
    {
        $tableTca = $GLOBALS['TCA'][$table];
        $fieldTca = $tableTca['columns'][$fieldName];
        if ($tableTca['ctrl']['type'] && $type !== '_all') {
            $fieldTca = array_replace_recursive($fieldTca, $tableTca['types'][$type]['columnsOverrides'][$fieldName] ?? []);
        }
        return $fieldTca;
    }

    protected function addFlashMessage(string $message, int $severity)
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            (string)$message,
            'Content Hub News Migration',
            $severity
        );
        $this->flashMessageService->getMessageQueueByIdentifier()->addMessage($flashMessage);
    }

    protected function handlePdfDimensions(array $fileReferenceRecord): array
    {
        $imageService = GeneralUtility::makeInstance(ImageService::class);
        $fileReference = GeneralUtility::makeInstance(ResourceFactory::class)->getFileReferenceObject($fileReferenceRecord['uid']);
        $processedImage = $imageService->applyProcessingInstructions($fileReference, ['width' => null, 'height' => null, 'crop' => null]);
        $fileReferenceRecord['height'] = $processedImage->getProperty('height');
        $fileReferenceRecord['width'] = $processedImage->getProperty('width');
        return $fileReferenceRecord;
    }
}
