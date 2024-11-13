<?php

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['melonImagesMigrateSchedulerTask'] = \Smichaelsen\MelonImages\Updates\MigrateSchedulerTask::class;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['melon_images'] = [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
    'options' => [
        'defaultLifetime' => 60 * 60 * 24 * 7, // configuration is cached for a week. clear the cache if you change any MelonImages.yaml file
    ],
];
