<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\EventListener;

use Smichaelsen\MelonImages\TcaUtility;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

class AddCroppingConfigurationToTca
{
    public function __invoke(AfterTcaCompilationEvent $event): AfterTcaCompilationEvent
    {
        $tca = $event->getTca();
        $event->setTca(TcaUtility::registerCropVariantsTca($tca));
        return $event;
    }
}
