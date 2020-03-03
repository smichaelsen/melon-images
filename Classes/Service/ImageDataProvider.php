<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\Service;

use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Service\ImageService;

class ImageDataProvider implements SingletonInterface
{
    /**
     * @var ImageService
     */
    protected $imageService;

    public function injectImageService(ImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    public function getImageVariantData(FileReference $fileReference, string $variant, $fallbackImageSize = null, $absolute = false, ?FileReference $useCroppingFrom = null): ?array
    {
        if ($useCroppingFrom instanceof FileReference) {
            $crop = $useCroppingFrom->getProperty('crop');
        } else {
            $crop = $fileReference->getProperty('crop');
        }
        $cropConfiguration = json_decode((string)$crop, true);
        if ($cropConfiguration === null) {
            return null;
        }
        // filter for all crop configurations that match the chosen image variant
        $matchingCropConfiguration = array_filter($cropConfiguration, function ($cropVariantId) use ($variant) {
            // the variant is the second last segment in the cropVariantId
            $segments = explode('__', $cropVariantId);
            return $variant === $segments[count($segments) - 2];
        }, ARRAY_FILTER_USE_KEY);
        $cropVariants = CropVariantCollection::create(json_encode($matchingCropConfiguration));
        $cropVariantIds = array_keys($matchingCropConfiguration);

        $sources = [];
        $pixelDensities = $this->getPixelDensitiesFromTypoScript();
        /** @var string $fallbackCropVariantId */
        $fallbackCropVariantId = null;
        foreach ($cropVariantIds as $cropVariantId) {
            $sizeConfigurations = $this->getSizeConfigurations($cropVariantId);
            if (empty($sizeConfigurations)) {
                continue;
            }
            foreach ($sizeConfigurations as $sizeIdentifier => $sizeConfiguration) {
                $srcset = [];
                foreach ($pixelDensities as $pixelDensity) {
                    $processingDimensions = $this->getProcessingWidthAndHeight(
                        $sizeConfiguration,
                        $matchingCropConfiguration[$cropVariantId]['selectedRatio'],
                        (float)$pixelDensity
                    );
                    $processedImage = $this->processImage($fileReference, $processingDimensions['width'], $processingDimensions['height'], $cropVariants->getCropArea($cropVariantId));
                    $imageUri = $this->imageService->getImageUri($processedImage, $absolute);
                    $srcset[] = $imageUri . ' ' . $pixelDensity . 'x';
                }

                $mediaQuery = $this->getMediaQueryFromSizeConfig($sizeConfiguration);

                $regularPixelDensityProcessingDimensions = $this->getProcessingWidthAndHeight(
                    $sizeConfiguration,
                    $matchingCropConfiguration[$cropVariantId]['selectedRatio']
                );
                $sources[$sizeIdentifier] = [
                    'srcsets' => $srcset,
                    'mediaQuery' => $mediaQuery,
                    'width' => $regularPixelDensityProcessingDimensions['width'],
                    'height' => $regularPixelDensityProcessingDimensions['height'],
                ];

                if ($fallbackImageSize) {
                    if ($fallbackImageSize === $sizeIdentifier) {
                        $fallbackCropVariantId = $cropVariantId;
                    }
                } else {
                    $fallbackCropVariantId = $cropVariantId;
                }
            }
        }
        if ($fallbackCropVariantId === null) {
            return null;
        }
        $fallbackSizeConfigurations = $this->getSizeConfigurations($fallbackCropVariantId);
        $processingDimensions = $this->getProcessingWidthAndHeight(
            $fallbackSizeConfigurations[$fallbackImageSize] ?? end($fallbackSizeConfigurations),
            $matchingCropConfiguration[$fallbackCropVariantId]['selectedRatio']
        );
        $processedFallbackImage = $this->processImage(
            $fileReference,
            $processingDimensions['width'],
            $processingDimensions['height'],
            $cropVariants->getCropArea($fallbackCropVariantId)
        );
        $fallbackImageConfig = [
            'src' => $this->imageService->getImageUri($processedFallbackImage, $absolute),
            'width' => $processingDimensions['width'],
            'height' => $processingDimensions['height'],
        ];

        return [
            'sources' => $sources,
            'fallbackImage' => $fallbackImageConfig,
        ];
    }

    protected function processImage(
        FileReference $fileReference,
        ?int $width,
        ?int $height,
        ?Area $cropArea
    ): ProcessedFile {
        if ($cropArea instanceof Area && !$cropArea->isEmpty()) {
            $cropArea = $cropArea->makeAbsoluteBasedOnFile($fileReference);
        } else {
            $cropArea = null;
        }
        $processingInstructions = [
            'crop' => $cropArea,
        ];
        if ($width !== null) {
            $processingInstructions['width'] = $width;
        }
        if ($height !== null) {
            $processingInstructions['height'] = $height;
        }
        return $this->imageService->applyProcessingInstructions($fileReference, $processingInstructions);
    }

    protected function getProcessingWidthAndHeight(array $sizeConfiguration, string $selectedRatio, float $pixelDensity = 1.0): array
    {
        if (isset($sizeConfiguration['ratio'])) {
            $calculatedRatio = MathUtility::calculateWithParentheses($sizeConfiguration['ratio']);
        }

        if (!empty($selectedRatio) && isset($sizeConfiguration['allowedRatios'], $sizeConfiguration['allowedRatios'][$selectedRatio])) {
            $dimensions = $sizeConfiguration['allowedRatios'][$selectedRatio];
        } else {
            $dimensions = $sizeConfiguration;
        }

        if (isset($dimensions['width']) && isset($calculatedRatio) && empty($dimensions['height'])) {
            // derive height from width and ratio
            $dimensions['height'] = round($dimensions['width'] / $calculatedRatio);
        } elseif (isset($dimensions['height']) && isset($calculatedRatio) && empty($dimensions['width'])) {
            // derive width from height and ratio
            $dimensions['width'] = round($dimensions['width'] * $calculatedRatio);
        }

        if (isset($dimensions['width'])) {
            $width = (int)round(($dimensions['width'] * $pixelDensity));
        } else {
            $width = null;
        }
        if (isset($dimensions['height'])) {
            $height = (int)round(($dimensions['height'] * $pixelDensity));
        } else {
            $height = null;
        }
        return [
            'width' => $width,
            'height' => $height,
        ];
    }

    protected function getSizeConfigurations(string $cropVariantId): ?array
    {
        $segments = explode('__', $cropVariantId);
        $lastSegment = array_pop($segments);
        $variantIdentifier = array_pop($segments);
        $typoscriptPath = implode('/', $segments);
        if (empty($typoscriptPath)) {
            return null;
        }
        $variantTypoScriptConfiguration = $this->getMelonImagesConfigForTyposcriptPath($typoscriptPath)['variants'][$variantIdentifier] ?? null;
        if (empty($variantTypoScriptConfiguration)) {
            return null;
        }
        if (isset($variantTypoScriptConfiguration['sizes'][$lastSegment])) {
            // last segment is a size identifier: just return the config for the 1 matching size
            return [
                $lastSegment => $variantTypoScriptConfiguration['sizes'][$lastSegment],
            ];
        }
        // last segment should be a ratio identifier: return all matching size configurations
        $sizeConfigurations = [];
        foreach ($variantTypoScriptConfiguration['sizes'] as $sizeIdentifier => $sizeConfiguration) {
            if (isset($sizeConfiguration['ratio']) && $sizeConfiguration['ratio'] === $lastSegment) {
                $sizeConfigurations[$sizeIdentifier] = $sizeConfiguration;
            }
        }
        return $sizeConfigurations;
    }

    protected function getBreakpointsFromTypoScript(): array
    {
        return (array)$this->getTypoScriptSettings()['breakpoints'];
    }

    protected function getPixelDensitiesFromTypoScript(): array
    {
        return GeneralUtility::trimExplode(',', (string)$this->getTypoScriptSettings()['pixelDensities'] ?? '1');
    }

    protected function getTypoScriptSettings(): array
    {
        static $typoScriptSettings;
        if (!is_array($typoScriptSettings)) {
            $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
            $configurationManager = $objectManager->get(ConfigurationManagerInterface::class);
            $typoscript = $configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
            );
            $typoScriptSettings = GeneralUtility::makeInstance(TypoScriptService::class)->convertTypoScriptArrayToPlainArray(
                $typoscript['package.']['Smichaelsen\MelonImages.'] ?? []
            );
        }
        return $typoScriptSettings;
    }

    protected function getMediaQueryFromSizeConfig(array $sizeConfiguration): string
    {
        $breakpointsConfig = $this->getBreakpointsFromTypoScript();
        $breakpoints = [];
        foreach (GeneralUtility::trimExplode(',', $sizeConfiguration['breakpoints']) as $breakpointName) {
            $constraints = [];
            if ($breakpointsConfig[$breakpointName]['from']) {
                $constraints[] = '(min-width: ' . $breakpointsConfig[$breakpointName]['from'] . 'px)';
            }
            if ($breakpointsConfig[$breakpointName]['to']) {
                $constraints[] = '(max-width: ' . $breakpointsConfig[$breakpointName]['to'] . 'px)';
            }
            if (empty($constraints)) {
                continue;
            }
            $breakpoints[] = implode(' and ', $constraints);
        }
        if (empty($breakpoints)) {
            return '';
        }
        return implode(', ', $breakpoints);
    }

    protected function getMelonImagesConfigForTyposcriptPath(string $typoscriptPath): ?array
    {
        static $melonConfigPerTcaPath = [];
        if (!isset($melonConfigPerTcaPath[$typoscriptPath])) {
            $typoScriptSettings = $this->getTypoScriptSettings();
            try {
                $melonConfigPerTcaPath[$typoscriptPath] = ArrayUtility::getValueByPath($typoScriptSettings['croppingConfiguration'], $typoscriptPath);
            } catch (\RuntimeException $e) {
                // path does not exist
                if ($e->getCode() === 1341397869) {
                    $melonConfigPerTcaPath[$typoscriptPath] = null;
                } else {
                    throw $e;
                }
            }
        }
        return $melonConfigPerTcaPath[$typoscriptPath];
    }
}
