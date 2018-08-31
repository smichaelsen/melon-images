<?php
defined('TYPO3_MODE') or die();

// in the FE this has to be done with a hook, after TypoScript has been initialized
if (TYPO3_MODE === 'BE') {
    \Smichaelsen\MelonImages\TcaUtility::registerCropVariantsTcaFromTypoScript();
}
