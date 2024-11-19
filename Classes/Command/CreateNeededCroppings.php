<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\Command;

use Smichaelsen\MelonImages\Service\ConfigurationLoader;
use Smichaelsen\MelonImages\Service\DefaultCroppingProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CreateNeededCroppings extends Command
{
    private const INITIAL_COUNTER_VALUES = [
        'tables' => 0,
        'types' => 0,
        'croppings' => 0,
    ];

    protected array $counters = self::INITIAL_COUNTER_VALUES;

    public function __construct(
        private readonly ConfigurationLoader $configurationLoader,
        private readonly ConnectionPool $connectionPool,
        private readonly DefaultCroppingProvider $defaultCroppingProvider,
        private readonly FlashMessageService $flashMessageService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Creates default cropping configuration where it is missing for image fields configured for MelonImages');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->counters = self::INITIAL_COUNTER_VALUES;
        $configuration = $this->configurationLoader->getConfiguration();
        foreach ($configuration['croppingConfiguration'] as $tableName => $tableConfiguration) {
            $this->counters['tables']++;
            foreach ($tableConfiguration as $type => $fields) {
                $this->counters['types']++;
                foreach ($fields as $fieldName => $fieldConfig) {
                    $tcaPath = [$tableName, (string)$type, $fieldName];
                    $this->createCroppingsForVariantsWithNestedRecords($tcaPath, $fieldConfig);
                }
            }
        }
        $message = sprintf(
            'Configuration of %d tables and %d record types successfully parsed. ',
            $this->counters['tables'],
            $this->counters['types'],
        );
        if ($this->counters['croppings'] > 0) {
            $message .= sprintf('%d croppings were missing and were just created', $this->counters['croppings']);
        } else {
            $message .= 'No croppings were missing.';
        }
        $output->writeln($message);
        $this->addFlashMessage($message, ContextualFeedbackSeverity::INFO);
        return 0;
    }

    protected function createCroppingsForVariantsWithNestedRecords(array $tcaPath, array $fieldConfig): void
    {
        if (isset($fieldConfig['variants'])) {
            $this->createCroppingForVariants($tcaPath, $fieldConfig['variants'], implode('__', $tcaPath));
        } else {
            foreach ($fieldConfig as $subType => $subFields) {
                $this->counters['types']++;
                foreach ($subFields as $subFieldName => $subFieldConfig) {
                    $subFieldTcaPath = $tcaPath;
                    $subFieldTcaPath[] = $subType;
                    $subFieldTcaPath[] = $subFieldName;
                    $this->createCroppingsForVariantsWithNestedRecords($subFieldTcaPath, $subFieldConfig);
                }
            }
        }
    }

    protected function createCroppingForVariants(array $tcaPath, array $variants, string $variantIdPrefix, array $localUids = null): void
    {
        $localTableName = array_shift($tcaPath);
        $type = array_shift($tcaPath);
        $fieldName = array_shift($tcaPath);
        $fieldTca = $this->getFieldTca($localTableName, $type, $fieldName);
        if ($fieldTca === null) {
            return;
        }
        $foreignTableName = $this->getForeignTableName($fieldTca['config']);
        if ($foreignTableName === null) {
            return;
        }
        $foreignUids = $this->queryForeignUids($localTableName, $foreignTableName, $fieldTca['config'], $type, $localUids);
        if ($foreignUids === []) {
            return;
        }
        if ($tcaPath !== []) {
            array_unshift($tcaPath, $foreignTableName);
            $this->createCroppingForVariants($tcaPath, $variants, $variantIdPrefix, $foreignUids);
            return;
        }
        $fileReferenceRecords = $this->loadFileReferenceRecords($foreignUids);
        $croppingsCreated = 0;
        foreach ($fileReferenceRecords as $fileReferenceRecord) {
            $cropConfiguration = $this->defaultCroppingProvider->provideDefaultCropping($fileReferenceRecord, $variants, $variantIdPrefix);
            if ($cropConfiguration === null) {
                continue;
            }
            $newCropValue = json_encode($cropConfiguration);
            if ($newCropValue === $fileReferenceRecord['crop']) {
                continue;
            }
            $croppingsCreated += count($cropConfiguration);
            $queryBuilder = $this->getQueryBuilder();
            $queryBuilder
                ->update('sys_file_reference')
                ->set('crop', $newCropValue)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($fileReferenceRecord['uid'], Connection::PARAM_INT))
                )->executeStatement();
        }
        if ($croppingsCreated > 0) {
            $this->counters['croppings'] += $croppingsCreated;
            $this->addFlashMessage($croppingsCreated . ' croppings created for ' . $variantIdPrefix, ContextualFeedbackSeverity::OK);
        }
    }

    public function loadFileReferenceRecords(array $foreignUids): array
    {
        $queryBuilder = $this->getQueryBuilder();
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
        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    protected function getForeignTableName(array $fieldConfig): ?string
    {
        if ($fieldConfig['type'] === 'inline' && ($fieldConfig['MM'] ?? false)) {
            // todo: Implement inline with MM
            return null;
        }
        if ($fieldConfig['type'] === 'inline' || $fieldConfig['type'] === 'file') {
            return $fieldConfig['foreign_table'];
        }
        if ($fieldConfig['type'] === 'select') {
            // todo implement select
            return null;
        }
        return null;
    }

    protected function queryForeignUids(string $localTableName, string $foreignTableName, array $fieldConfig, string $localType, array $localUids = null): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($foreignTableName);
        if ($fieldConfig['type'] === 'inline' && ($fieldConfig['MM'] ?? false)) {
            // todo: Implement inline with MM
            return [];
        }
        if ($fieldConfig['type'] === 'inline' || $fieldConfig['type'] === 'file') {
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

            $typeField = $GLOBALS['TCA'][$localTableName]['ctrl']['type'] ?? '';
            $queryBuilder->join(
                $foreignTableName,
                $localTableName,
                $localTableName,
                $foreignTableName . '.' . $fieldConfig['foreign_field'] . ' = ' . $localTableName . '.uid'
            );
            if ($localType !== '_all') {
                $queryBuilder->andWhere($queryBuilder->expr()->eq($localTableName . '.' . $typeField, $queryBuilder->createNamedParameter($localType)));
            }
            return array_map(static fn(array $record) => $record['uid'], $queryBuilder->executeQuery()->fetchAllAssociative());
        }
        if ($fieldConfig['type'] === 'select') {
            // todo implement select
            return [];
        }
        return [];
    }

    protected function getFieldTca(string $table, string $type, string $fieldName)
    {
        $tableTca = $GLOBALS['TCA'][$table] ?? null;
        $fieldTca = $tableTca['columns'][$fieldName] ?? null;
        if (!$tableTca || !$fieldTca) {
            return null;
        }
        if (($tableTca['ctrl']['type'] ?? false) && $type !== '_all') {
            $fieldTca = array_replace_recursive($fieldTca, $tableTca['types'][$type]['columnsOverrides'][$fieldName] ?? []);
        }
        return $fieldTca;
    }

    protected function addFlashMessage(string $message, ContextualFeedbackSeverity $severity): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            'Melon Images',
            $severity
        );
        // @extensionScannerIgnoreLine - the extension scanner shows a weak warning, because it suspects that SchedulerModuleController->addMessage is called here, but it isn't
        $this->flashMessageService->getMessageQueueByIdentifier()->addMessage($flashMessage);
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
    }
}
