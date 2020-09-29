<?php
defined('TYPO3_MODE') || die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['melonImagesMigrateSchedulerTask'] = \Smichaelsen\MelonImages\Updates\MigrateSchedulerTask::class;
