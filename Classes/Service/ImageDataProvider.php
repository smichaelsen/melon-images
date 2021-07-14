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
use TYPO3\CMS\Extbase\Service\ImageService;

class ImageDataProvider implements SingletonInterface
{
    protected array $configuration;
    protected ImageService $imageService;

    public function __construct(ImageService $imageService, Registry $registry)
    {
        $this->configuration = $registry->getParsedConfiguration();
        $this->imageService = $imageService;
    }

    public function getImageVariantData(FileReference $fileReference, string $variant, ?string $fallbackImageSize = null, $absolute = false, ?FileReference $useCroppingFrom = null): ?array
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
        $fallbackCropVariantId = null;
        foreach ($cropVariantIds as $cropVariantId) {
            $sizeConfigurations = $this->getSizeConfigurations($cropVariantId);
            if (empty($sizeConfigurations)) {
                continue;
            }
            foreach ($sizeConfigurations as $sizeIdentifier => $sizeConfiguration) {
                $sources[$sizeIdentifier] = $this->createSource($sizeConfiguration, $matchingCropConfiguration[$cropVariantId]['selectedRatio'], $cropVariantId, $pixelDensities, $fileReference, $cropVariants, $absolute);
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

    protected function createSource(array $sizeConfiguration, string $selectedRatio, string $cropVariantId, array $pixelDensities, FileReference $fileReference, CropVariantCollection $cropVariants, bool $absolute): Source
    {
        $source = new Source(
            $this->getMediaQueryFromSizeConfig($sizeConfiguration),
            $this->getProcessingWidthAndHeight($sizeConfiguration, $selectedRatio)
        );
        foreach ($pixelDensities as $pixelDensity) {
            $processingDimensions = $this->getProcessingWidthAndHeight($sizeConfiguration, $selectedRatio, (float)$pixelDensity);
            $processedImage = $this->processImage($fileReference, $processingDimensions, $cropVariants->getCropArea($cropVariantId));
            $imageUri = $this->imageService->getImageUri($processedImage, $absolute);
            $set = new Set();
            $set->setImageUri($imageUri);
            $set->setPixelDensity((float)$pixelDensity);
            $source->addSet($set);
        }
        return $source;
    }

    protected function processImage(FileReference $fileReference, Dimensions $dimensions, ?Area $cropArea): ProcessedFile
    {
        $processingInstructions = [];
        if ($cropArea instanceof Area && !$cropArea->isEmpty()) {
            $processingInstructions['crop'] = $cropArea->makeAbsoluteBasedOnFile($fileReference);
        }
        $processingInstructions['width'] = $dimensions->getWidth();
        $processingInstructions['height'] = $dimensions->getHeight();
        return $this->imageService->applyProcessingInstructions($fileReference, $processingInstructions);
    }

    protected function getProcessingWidthAndHeight(array $sizeConfiguration, string $selectedRatio, float $pixelDensity = 1.0): Dimensions
    {
        if (!empty($selectedRatio) && isset($sizeConfiguration['allowedRatios'], $sizeConfiguration['allowedRatios'][$selectedRatio])) {
            $sizeConfiguration = $sizeConfiguration['allowedRatios'][$selectedRatio];
        }
        $dimensions = new Dimensions($sizeConfiguration['width'], $sizeConfiguration['height'], $sizeConfiguration['ratio']);
        return $dimensions->scale($pixelDensity);
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
        $breakpoints = $this->configuration['breakpoints'];
        if (is_string($breakpoints)) {
            $breakpoints = GeneralUtility::trimExplode(',', $breakpoints);
        }
        return $breakpoints;
    }

    protected function getPixelDensities(): array
    {
        $pixelDensities = $this->configuration['pixelDensities'];
        if (empty($pixelDensities)) {
            return ['1'];
        }
        if (is_string($pixelDensities)) {
            $pixelDensities = GeneralUtility::trimExplode(',', $pixelDensities);
        }
        return $pixelDensities;
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
            try {
                $melonConfigPerTcaPath[$configurationPath] = ArrayUtility::getValueByPath($this->configuration['croppingConfiguration'], $configurationPath);
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
