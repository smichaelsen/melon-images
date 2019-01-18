<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\Command;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\BackendConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\Controller\CommandController;

class CreateNeededCroppingsCommandController extends CommandController
{
    /**
     * @var FlashMessageService
     */
    protected $flashMessageService;

    public function __construct(FlashMessageService $flashMessageService)
    {
        $this->flashMessageService = $flashMessageService;
    }

    public function createNeededCroppingsCommand()
    {
        foreach ($this->loadCroppingConfiguration() as $tableName => $tableConfiguration) {
            foreach ($tableConfiguration as $type => $fields) {
                foreach ($fields as $fieldName => $fieldConfig) {
                    $tcaPath = [$tableName, $type, $fieldName];
                    $this->createCroppingsForVariantsWithNestedRecords($tcaPath, $fieldConfig);
                }
            }
        }
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
            ->select('sys_file_reference.uid', 'sys_file_reference.crop', 'sys_file_metadata.width', 'sys_file_metadata.height')
            ->from('sys_file_reference')
            ->join(
                'sys_file_reference',
                'sys_file_metadata',
                'sys_file_metadata',
                'sys_file_metadata.file = sys_file_reference.uid_local'
            )
            ->where(
                $queryBuilder->expr()->in('sys_file_reference.uid', $foreignUids)
            );
        $croppingsCreated = 0;
        $fileReferenceRecords = $queryBuilder->execute()->fetchAll();
        foreach ($fileReferenceRecords as $fileReferenceRecord) {
            if ((int)$fileReferenceRecord['width'] === 0) {
                continue;
            }
            $cropConfiguration = json_decode($fileReferenceRecord['crop'], true) ?? [];
            foreach ($variants as $variant => $variantConfiguration) {
                foreach ($variantConfiguration['sizes'] as $size => $sizeConfiguration) {
                    $variantId = $variantIdPrefix . '__' . $variant . '__' . $size;
                    if (!isset($cropConfiguration[$variantId])) {
                        if (isset($sizeConfiguration['width'], $sizeConfiguration['height'])) {
                            $cropConfiguration[$variantId] = [
                                'cropArea' => $this->calculateCropArea(
                                    (int)$fileReferenceRecord['width'],
                                    (int)$fileReferenceRecord['height'],
                                    (int)$sizeConfiguration['width'],
                                    (int)$sizeConfiguration['height']
                                ),
                                'selectedRatio' => $sizeConfiguration['width'] . ' x ' . $sizeConfiguration['height'],
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

    protected function calculateCropArea(int $fileWidth, int $fileHeight, int $croppingWidth, int $croppingHeight): array
    {
        $fileRatio = $fileWidth / $fileHeight;
        $croppingRatio = $croppingWidth / $croppingHeight;
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
            $fieldTca = array_merge_recursive($fieldTca, $tableTca['types'][$type]['columnsOverrides'][$fieldName] ?? []);
        }
        return $fieldTca;
    }

    protected function loadCroppingConfiguration(): array
    {
        $configurationManager = GeneralUtility::makeInstance(BackendConfigurationManager::class);
        $typoScript = $configurationManager->getTypoScriptSetup();
        if (empty($typoScript['package.']['Smichaelsen\\MelonImages.']['croppingConfiguration.'])) {
            return [];
        }
        return GeneralUtility::makeInstance(TypoScriptService::class)->convertTypoScriptArrayToPlainArray(
            $typoScript['package.']['Smichaelsen\\MelonImages.']['croppingConfiguration.']
        );
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
}
