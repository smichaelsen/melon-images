<?php
namespace Smichaelsen\MelonImages\Hook;

/**
 * Used to perform the necessary TCA manipulations right after TypoScript has been initialized in the frontend
 */
class RegisterCropVariantsTcaFromTypoScriptHook
{
    public function register()
    {
        \Smichaelsen\MelonImages\TcaUtility::registerCropVariantsTcaFromTypoScript();
    }
}
