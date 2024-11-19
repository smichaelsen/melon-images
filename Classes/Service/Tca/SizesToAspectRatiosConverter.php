<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\Service\Tca;

use Smichaelsen\MelonImages\Domain\Dto\Dimensions;

class SizesToAspectRatiosConverter
{
    public function getAspectRatiosFromSizes(array $sizes): array
    {
        $aspectRatios = [];
        foreach ($sizes as $sizeIdentifier => $sizeConfig) {
            $identifier = $sizeConfig['ratio'] ?? $sizeIdentifier;
            if (!isset($sizeConfig['allowedRatios']) && (isset($sizeConfig['width']) || isset($sizeConfig['height']))) {
                $ratioIdentifier = $sizeConfig['title'] ?? $sizeConfig['ratio'] ?? ($sizeConfig['width'] . ' x ' . $sizeConfig['height']);
                $nestedConfig = $sizeConfig;
                $sizeConfig = [];
                if (isset($nestedConfig['focusArea'])) {
                    $sizeConfig['focusArea'] = $nestedConfig['focusArea'];
                    unset($nestedConfig['focusArea']);
                }
                if (isset($nestedConfig['coverArea'])) {
                    $sizeConfig['coverArea'] = $nestedConfig['coverArea'];
                    unset($nestedConfig['coverArea']);
                }
                $sizeConfig['allowedRatios'][$ratioIdentifier] = $nestedConfig;
            }
            foreach ($sizeConfig['allowedRatios'] as $allowedRatioKey => $allowedRatioConfig) {
                $dimensions = new Dimensions($allowedRatioConfig['width'] ?? null, $allowedRatioConfig['height'] ?? null, $allowedRatioConfig['ratio'] ?? null);
                $sizeConfig['allowedRatios'][$allowedRatioKey]['width'] = $dimensions->getWidth();
                $sizeConfig['allowedRatios'][$allowedRatioKey]['height'] = $dimensions->getHeight();
            }
            $aspectRatios[$identifier] = $sizeConfig;
        }
        return $aspectRatios;
    }
}
