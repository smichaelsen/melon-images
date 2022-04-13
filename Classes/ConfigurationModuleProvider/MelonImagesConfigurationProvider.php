<?php

namespace Smichaelsen\MelonImages\ConfigurationModuleProvider;

use Smichaelsen\MelonImages\Service\ConfigurationLoader;
use TYPO3\CMS\Lowlevel\ConfigurationModuleProvider\AbstractProvider;

class MelonImagesConfigurationProvider extends AbstractProvider
{
    private ConfigurationLoader $configurationLoader;

    public function __construct(ConfigurationLoader $configurationLoader)
    {
        $this->configurationLoader = $configurationLoader;
    }

    public function getConfiguration(): array
    {
        return $this->configurationLoader->getConfiguration();
    }
}
