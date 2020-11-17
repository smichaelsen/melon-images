<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\Service;

use Smichaelsen\MelonImages\Configuration\Registry;
use Smichaelsen\MelonImages\Domain\Dto\Dimensions;
use Smichaelsen\MelonImages\Domain\Dto\Set;
use Smichaelsen\MelonImages\Domain\Dto\Source;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
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
        $pixelDensities = $this->getPixelDensities();
        /** @var string $fallbackCropVariantId */
        $fallbackCropVariantId = null;
        foreach ($cropVariantIds as $cropVariantId) {
            $sizeConfigurations = $this->getSizeConfigurations($cropVariantId);
            if (empty($sizeConfigurations)) {
                continue;
            }
            foreach ($sizeConfigurations as $sizeIdentifier => $sizeConfiguration) {
                $source = new Source(
                    $this->getMediaQueryFromSizeConfig($sizeConfiguration),
                    $this->getProcessingWidthAndHeight(
                        $sizeConfiguration,
                        $matchingCropConfiguration[$cropVariantId]['selectedRatio']
                    )
                );
                foreach ($pixelDensities as $pixelDensity) {
                    $processingDimensions = $this->getProcessingWidthAndHeight(
                        $sizeConfiguration,
                        $matchingCropConfiguration[$cropVariantId]['selectedRatio'],
                        (float)$pixelDensity
                    );
                    $processedImage = $this->processImage($fileReference, $processingDimensions, $cropVariants->getCropArea($cropVariantId));
                    $imageUri = $this->imageService->getImageUri($processedImage, $absolute);
                    $set = new Set();
                    $set->setImageUri($imageUri);
                    $set->setPixelDensity((float)$pixelDensity);
                    $source->addSet($set);
                }

                $sources[$sizeIdentifier] = $source;

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
        $processedFallbackImage = $this->processImage($fileReference, $processingDimensions, $cropVariants->getCropArea($fallbackCropVariantId));
        $fallbackImageConfig = [
            'src' => $this->imageService->getImageUri($processedFallbackImage, $absolute),
            'dimensions' => $processingDimensions,
        ];

        return [
            'sources' => $sources,
            'fallbackImage' => $fallbackImageConfig,
        ];
    }

    protected function processImage(
        FileReference $fileReference,
        Dimensions $dimensions,
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
        if ($dimensions->getWidth() !== null) {
            $processingInstructions['width'] = $dimensions->getWidth();
        }
        if ($dimensions->getHeight() !== null) {
            $processingInstructions['height'] = $dimensions->getHeight();
        }
        return $this->imageService->applyProcessingInstructions($fileReference, $processingInstructions);
    }

    protected function getProcessingWidthAndHeight(array $sizeConfiguration, string $selectedRatio, float $pixelDensity = 1.0): Dimensions
    {
        if (!empty($selectedRatio) && isset($sizeConfiguration['allowedRatios'], $sizeConfiguration['allowedRatios'][$selectedRatio])) {
            $dimensions = $sizeConfiguration['allowedRatios'][$selectedRatio];
        } else {
            $dimensions = $sizeConfiguration;
        }

        if (isset($dimensions['ratio'])) {
            $calculatedRatio = MathUtility::calculateWithParentheses($dimensions['ratio']);
        }

        if (isset($dimensions['width']) && isset($calculatedRatio) && empty($dimensions['height'])) {
            // derive height from width and ratio
            $dimensions['height'] = round($dimensions['width'] / $calculatedRatio);
        } elseif (isset($dimensions['height']) && isset($calculatedRatio) && empty($dimensions['width'])) {
            // derive width from height and ratio
            $dimensions['width'] = round($dimensions['height'] * $calculatedRatio);
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
        return new Dimensions($width, $height);
    }

    protected function getSizeConfigurations(string $cropVariantId): ?array
    {
        $segments = explode('__', $cropVariantId);
        $lastSegment = array_pop($segments);
        $variantIdentifier = array_pop($segments);
        $configurationPath = implode('/', $segments);
        if (empty($configurationPath)) {
            return null;
        }
        $variantConfiguration = $this->getCroppingConfigurationByPath($configurationPath)['variants'][$variantIdentifier] ?? null;
        if (empty($variantConfiguration)) {
            return null;
        }
        if (isset($variantConfiguration['sizes'][$lastSegment])) {
            // last segment is a size identifier: just return the config for the 1 matching size
            return [
                $lastSegment => $variantConfiguration['sizes'][$lastSegment],
            ];
        }
        // last segment should be a ratio identifier: return all matching size configurations
        $sizeConfigurations = [];
        foreach ($variantConfiguration['sizes'] as $sizeIdentifier => $sizeConfiguration) {
            if (isset($sizeConfiguration['ratio']) && $sizeConfiguration['ratio'] === $lastSegment) {
                $sizeConfigurations[$sizeIdentifier] = $sizeConfiguration;
            }
        }
        return $sizeConfigurations;
    }

    protected function getBreakpoints(): array
    {
        $breakpoints = $this->getConfiguration()['breakpoints'];
        if (is_string($breakpoints)) {
            $breakpoints = GeneralUtility::trimExplode(',', $breakpoints);
        }
        return $breakpoints;
    }

    protected function getPixelDensities(): array
    {
        $pixelDensities = $this->getConfiguration()['pixelDensities'];
        if (empty($pixelDensities)) {
            return ['1'];
        }
        if (is_string($pixelDensities)) {
            $pixelDensities = GeneralUtility::trimExplode(',', $pixelDensities);
        }
        return $pixelDensities;
    }

    protected function getConfiguration(): array
    {
        static $configuration;
        if (!is_array($configuration)) {
            $configurationRegistry = GeneralUtility::makeInstance(Registry::class);
            $configuration = $configurationRegistry->getParsedConfiguration();
        }
        return $configuration;
    }

    protected function getMediaQueryFromSizeConfig(array $sizeConfiguration): string
    {
        $breakpointsConfig = $this->getBreakpoints();
        $breakpoints = [];
        if (!is_array($sizeConfiguration['breakpoints'])) {
            $sizeConfiguration['breakpoints'] = GeneralUtility::trimExplode(',', $sizeConfiguration['breakpoints']);
        }
        foreach ($sizeConfiguration['breakpoints'] as $breakpointName) {
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

    protected function getCroppingConfigurationByPath(string $configurationPath): ?array
    {
        static $melonConfigPerTcaPath = [];
        if (!isset($melonConfigPerTcaPath[$configurationPath])) {
            $configuration = $this->getConfiguration();
            try {
                $melonConfigPerTcaPath[$configurationPath] = ArrayUtility::getValueByPath($configuration['croppingConfiguration'], $configurationPath);
            } catch (\RuntimeException $e) {
                // path does not exist
                if ($e->getCode() === 1341397869) {
                    $melonConfigPerTcaPath[$configurationPath] = null;
                } else {
                    throw $e;
                }
            }
        }
        return $melonConfigPerTcaPath[$configurationPath];
    }
}
