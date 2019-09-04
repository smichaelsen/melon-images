<?php
defined('TYPO3_MODE') || die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['configArrayPostProc'][] = \Smichaelsen\MelonImages\Hook\RegisterCropVariantsTcaFromTypoScriptHook::class . '->register';

if (TYPO3_MODE === 'BE') {
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['extbase']['commandControllers'][] = \Smichaelsen\MelonImages\Command\CreateNeededCroppingsCommandController::class;
}
