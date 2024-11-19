<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\Service;

use Smichaelsen\MelonImages\Domain\Dto\Dimensions;
use Smichaelsen\MelonImages\Service\Tca\SizesToAspectRatiosConverter;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Service\ImageService;

class DefaultCroppingProvider
{
    public function __construct(
        private readonly ImageService $imageService,
        private readonly ResourceFactory $resourceFactory,
        private readonly SizesToAspectRatiosConverter $sizesToAspectRatiosConverter,
    ) {}

    public function provideDefaultCropping(array $fileReferenceRecord, array $variants, string $variantIdPrefix): ?array
    {
        if ((int)$fileReferenceRecord['width'] === 0 && $fileReferenceRecord['extension'] === 'pdf') {
            $fileReferenceRecord = $this->handlePdfDimensions($fileReferenceRecord);
        }
        if ((int)$fileReferenceRecord['width'] === 0) {
            return null;
        }
        $cropConfiguration = json_decode((string)$fileReferenceRecord['crop'], true) ?? [];
        foreach ($variants as $variant => $variantConfiguration) {
            $aspectRatioConfigs = $this->sizesToAspectRatiosConverter->getAspectRatiosFromSizes($variantConfiguration['sizes']);
            foreach ($aspectRatioConfigs as $aspectRatioIdentifier => $aspectRatioConfig) {
                $variantId = $variantIdPrefix . '__' . $variant . '__' . $aspectRatioIdentifier;
                if (isset($cropConfiguration[$variantId])) {
                    continue;
                }
                if (isset($aspectRatioConfig['allowedRatios'])) {
                    $defaultRatio = $selectedRatio = $this->getDefaultRatioKey($aspectRatioConfig['allowedRatios']);
                    $allowedRatioConfig = $aspectRatioConfig['allowedRatios'][$defaultRatio];
                    $dimensions = new Dimensions($allowedRatioConfig['width'] ?? null, $allowedRatioConfig['height'] ?? null, $allowedRatioConfig['ratio'] ?? null);
                    if ($dimensions->isFree()) {
                        $selectedRatio = 'NaN';
                        $cropArea = [
                            'width' => 1,
                            'height' => 1,
                            'x' => 0,
                            'y' => 0,
                        ];
                    } else {
                        $cropArea = $this->calculateCropArea(
                            (int)$fileReferenceRecord['width'],
                            (int)$fileReferenceRecord['height'],
                            $dimensions->getRatio()
                        );
                    }
                    $cropConfiguration[$variantId] = [
                        'cropArea' => $cropArea,
                        'selectedRatio' => $selectedRatio,
                        'focusArea' => null,
                    ];
                } else {
                    $cropConfiguration[$variantId] = [
                        'cropArea' => [
                            'width' => 1,
                            'height' => 1,
                            'x' => 0,
                            'y' => 0,
                        ],
                        'selectedRatio' => $fileReferenceRecord['width'] . ' x ' . $fileReferenceRecord['height'],
                        'focusArea' => null,
                    ];
                }
            }
        }
        return $cropConfiguration;
    }

    protected function calculateCropArea(int $fileWidth, int $fileHeight, float $croppingRatio): array
    {
        $fileRatio = $fileWidth / $fileHeight;
        $croppedHeightValue = min(1, $fileRatio / $croppingRatio);
        $croppedWidthValue = min(1, $croppingRatio / $fileRatio);
        return [
            'width' => $croppedWidthValue,
            'height' => $croppedHeightValue,
            'x' => (1 - $croppedWidthValue) / 2,
            'y' => (1 - $croppedHeightValue) / 2,
        ];
    }

    protected function getDefaultRatioKey(array $allowedRatios): string
    {
        // try to find a "free" ratio
        foreach ($allowedRatios as $ratioKey => $ratioConfig) {
            $dimensions = new Dimensions($ratioConfig['width'] ?? null, $ratioConfig['height'] ?? null, $ratioConfig['ratio'] ?? null);
            if ($dimensions->isFree()) {
                return $ratioKey;
            }
        }
        // return the first key
        $ratioKeys = array_keys($allowedRatios);
        return array_shift($ratioKeys);
    }

    private function handlePdfDimensions(array $fileReferenceRecord): array
    {
        $fileReference = $this->resourceFactory->getFileReferenceObject($fileReferenceRecord['uid']);
        $processedImage = $this->imageService->applyProcessingInstructions($fileReference, ['width' => null, 'height' => null, 'crop' => null]);
        $fileReferenceRecord['height'] = $processedImage->getProperty('height');
        $fileReferenceRecord['width'] = $processedImage->getProperty('width');
        return $fileReferenceRecord;
    }
}
