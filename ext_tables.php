<?php
defined('TYPO3_MODE') || die();

// in the FE this has to be done with a hook, after TypoScript has been initialized
if (TYPO3_MODE === 'BE' && !(TYPO3_REQUESTTYPE & TYPO3_REQUESTTYPE_CLI)) {
    \Smichaelsen\MelonImages\TcaUtility::registerCropVariantsTcaFromTypoScript();
}
