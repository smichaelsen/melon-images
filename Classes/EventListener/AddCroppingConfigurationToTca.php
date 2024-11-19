<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\EventListener;

use Smichaelsen\MelonImages\Service\TcaService;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

class AddCroppingConfigurationToTca
{
    private TcaService $tcaService;

    public function __construct(TcaService $tcaService)
    {
        $this->tcaService = $tcaService;
    }

    public function __invoke(AfterTcaCompilationEvent $event): AfterTcaCompilationEvent
    {
        $tca = $event->getTca();
        $event->setTca($this->tcaService->registerCropVariantsTca($tca));
        return $event;
    }
}
