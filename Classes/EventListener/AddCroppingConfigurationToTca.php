<?php

declare(strict_types=1);
namespace Smichaelsen\MelonImages\EventListener;

use Smichaelsen\MelonImages\Service\CropWizardConfigurationService;
use Smichaelsen\MelonImages\Service\LegacyTcaService;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

class AddCroppingConfigurationToTca
{
    private CropWizardConfigurationService $cropWizardConfigurationService;
    private LegacyTcaService $legacyTcaService;

    public function __construct(CropWizardConfigurationService $cropWizardConfigurationService, LegacyTcaService $tcaService)
    {
        $this->cropWizardConfigurationService = $cropWizardConfigurationService;
        $this->legacyTcaService = $tcaService;
    }

    public function __invoke(AfterTcaCompilationEvent $event): AfterTcaCompilationEvent
    {
        $tca = $event->getTca();
        $event->setTca($this->cropWizardConfigurationService->addCropWizardConfigurationToTca($tca));
        $event->setTca($this->legacyTcaService->registerCropVariantsTca($tca));
        return $event;
    }
}
