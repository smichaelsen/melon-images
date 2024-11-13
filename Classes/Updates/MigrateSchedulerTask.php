<?php

declare(strict_types=1);
namespace Smichaelsen\MelonImages\Updates;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;
use TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask;

#[UpgradeWizard('melon_imagesMigrateSchedulerTask')]
class MigrateSchedulerTask implements UpgradeWizardInterface
{
    public function getIdentifier(): string
    {
        return 'melonImagesMigrateSchedulerTask';
    }

    public function getTitle(): string
    {
        return 'Migrate Melon Images Scheduler Task';
    }

    public function getDescription(): string
    {
        return 'The melon images scheduler task to create missing croppings has changed according to the TYPO3 core. This update migrates it in your database.';
    }

    public function executeUpdate(): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_scheduler_task');
        $tasks = $queryBuilder
            ->select('uid', 'serialized_task_object')
            ->from('tx_scheduler_task')
            ->execute()
            ->fetchAllAssociative();

        $queryBuilder->update('tx_scheduler_task');
        foreach ($tasks as $task) {
            if (strpos($task['serialized_task_object'], 'melon_images:createneededcroppings:createneededcroppings') === false) {
                continue;
            }

            $queryBuilder->resetQueryParts(['set', 'where']);
            $unserializedTaskObject = $this->accessibleUnserizalize($task['serialized_task_object']);
            $newTask = new ExecuteSchedulableCommandTask();
            $newTask->setCommandIdentifier('melon_images:createNeededCroppings');
            $newTask->setExecution($unserializedTaskObject->execution);
            $newTask->setExecutionTime($unserializedTaskObject->executionTime);

            $queryBuilder
                ->set('serialized_task_object', serialize($newTask))
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($task['uid'], \PDO::PARAM_INT))
                )
                ->execute();
        }
        return true;
    }

    public function updateNecessary(): bool
    {
        return true;
    }

    protected function accessibleUnserizalize(string $serializedObject): object
    {
        $unserializedObject = unserialize($serializedObject);
        if (!$unserializedObject instanceof \__PHP_Incomplete_Class) {
            return $unserializedObject;
        }
        $unserializedArray = (array)$unserializedObject;
        $stdClass = new \stdClass();
        // remove null byte prefix from "protected" array keys
        foreach ($unserializedArray as $key => $value) {
            $cleanKey = str_replace("\0", '', $key);
            $cleanKey = trim($cleanKey, '*');
            $stdClass->{$cleanKey} = $value;
        }
        return $stdClass;
    }

    public function getPrerequisites(): array
    {
        return [];
    }
}
