<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\Service;

class CropWizardConfigurationService
{
    private array $configuration;

    public function __construct(ConfigurationLoader $configurationLoader)
    {
        $this->configuration = $configurationLoader->getConfiguration();
    }

    public function addCropWizardConfigurationToTca(array $tca): array
    {
        if (($this->configuration['croppings'] ?? []) === []) {
            return $tca;
        }

        foreach ($this->configuration['croppings'] as $croppingName => $croppingConfiguration) {

        }

        return $tca;
    }
}
