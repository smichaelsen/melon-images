<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\Service;

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

    public function __construct(ImageService $imageService, ConfigurationLoader $configurationLoader)
    {
        $this->configuration = $configurationLoader->getConfiguration();
        $this->imageService = $imageService;
    }

    public function getImageVariantData(FileReference $fileReference, string $variant, ?string $fallbackImageSize = null, $absolute = false, ?FileReference $useCroppingFrom = null): ?array
    {
        if ($useCroppingFrom instanceof FileReference) {
            $crop = $useCroppingFrom->getProperty('crop');
        } else {
            $crop = $fileReference->getProperty('crop');
        }
        $matchingCropConfigurations = $this->getMatchingCropConfigurations((string)$crop, $variant);
        if ($matchingCropConfigurations === []) {
            // the requested variant wasn't found in the available crop data
            return null;
        }

        $cropVariants = CropVariantCollection::create(json_encode($matchingCropConfigurations));

        $sources = [];
        $pixelDensities = $this->getPixelDensities();
        $imageFileFormats = $this->getImageFileFormats();
        $fallbackCropVariantId = null;
        foreach ($matchingCropConfigurations as $cropVariantId => $matchingCropConfiguration) {
            $sizeConfigurations = $this->getSizeConfigurations($cropVariantId);
            if (empty($sizeConfigurations)) {
                continue;
            }
            $fallbackCropVariantId = $cropVariantId;
            foreach ($sizeConfigurations as $sizeIdentifier => $sizeConfiguration) {
                foreach ($imageFileFormats as $imageFileFormat) {
                    $sourceIdentifier = $sizeIdentifier . '_' . $imageFileFormat;
                    $sources[$sourceIdentifier] = $this->createSource(
                        $sizeConfiguration,
                        $matchingCropConfiguration['selectedRatio'],
                        $cropVariantId,
                        $pixelDensities,
                        $fileReference,
                        $cropVariants,
                        $absolute,
                        $imageFileFormat,
                    );
                }
            }
        }

        if ($fallbackCropVariantId === null) {
            return null;
        }

        $fallbackCropConfiguration = $matchingCropConfigurations[$fallbackCropVariantId];
        $fallbackSizeConfigurations = $this->getSizeConfigurations($fallbackCropVariantId);
        $processingDimensions = $this->getProcessingWidthAndHeight(
            $fallbackSizeConfigurations[$fallbackImageSize] ?? end($fallbackSizeConfigurations),
            $fallbackCropConfiguration['selectedRatio']
        );
        $processedFallbackImage = $this->processImage($fileReference, $processingDimensions, $cropVariants->getCropArea($fallbackCropVariantId), '_default');
        $fallbackImageConfig = [
            'src' => $this->imageService->getImageUri($processedFallbackImage, $absolute),
            'dimensions' => $processingDimensions,
            'processedFile' => $processedFallbackImage,
        ];

        return [
            'cropConfigurations' => array_values($matchingCropConfigurations),
            'sources' => $sources,
            'fallbackImage' => $fallbackImageConfig,
        ];
    }

    protected function getMatchingCropConfigurations(string $cropData, string $variantName): array
    {
        $cropConfiguration = json_decode($cropData, true);
        if (!is_array($cropConfiguration)) {
            return [];
        }
        $matchingCropConfigurations = [];
        foreach ($cropConfiguration as $cropVariantId => $singleCropConfiguration) {
            // the variant is the second last segment in the cropVariantId
            $segments = explode('__', $cropVariantId);
            if ($variantName === ($segments[count($segments) - 2] ?? false)) {
                $matchingCropConfigurations[$cropVariantId] = $singleCropConfiguration;
            }
        }
        return $matchingCropConfigurations;
    }

    protected function createSource(array $sizeConfiguration, string $selectedRatio, string $cropVariantId, array $pixelDensities, FileReference $fileReference, CropVariantCollection $cropVariants, bool $absolute, string $imageFileFormat): Source
    {
        $source = new Source(
            $this->getMediaQueryFromSizeConfig($sizeConfiguration),
            $this->getProcessingWidthAndHeight($sizeConfiguration, $selectedRatio),
            $imageFileFormat === '_default' ? $fileReference->getMimeType() : 'image/' . $imageFileFormat,
        );
        foreach ($pixelDensities as $pixelDensity) {
            $processingDimensions = $this->getProcessingWidthAndHeight($sizeConfiguration, $selectedRatio, (float)$pixelDensity);
            $processedImage = $this->processImage($fileReference, $processingDimensions, $cropVariants->getCropArea($cropVariantId), $imageFileFormat);
            $imageUri = $this->imageService->getImageUri($processedImage, $absolute);
            $set = new Set();
            $set->setImageUri($imageUri);
            $set->setPixelDensity((float)$pixelDensity);
            $source->addSet($set);
        }
        return $source;
    }

    protected function processImage(FileReference $fileReference, Dimensions $dimensions, ?Area $cropArea, string $imageFileFormat): ProcessedFile
    {
        $processingInstructions = [];
        if ($cropArea instanceof Area && !$cropArea->isEmpty()) {
            $processingInstructions['crop'] = $cropArea->makeAbsoluteBasedOnFile($fileReference);
        }
        $processingInstructions['width'] = (int)$dimensions->getWidth();
        $processingInstructions['height'] = (int)$dimensions->getHeight();
        if ($imageFileFormat !== '_default') {
            $processingInstructions['fileExtension'] = $imageFileFormat;
        }
        return $this->imageService->applyProcessingInstructions($fileReference, $processingInstructions);
    }

    protected function getProcessingWidthAndHeight(array $sizeConfiguration, string $selectedRatio, float $pixelDensity = 1.0): Dimensions
    {
        if (!empty($selectedRatio) && isset($sizeConfiguration['allowedRatios'])) {
            // compatibility: allow "free" configuration for "NaN":
            if (isset($sizeConfiguration['allowedRatios']['free']) && !(isset($sizeConfiguration['allowedRatios']['NaN']))) {
                $sizeConfiguration['allowedRatios']['NaN'] = $sizeConfiguration['allowedRatios']['free'];
            }
            if ($selectedRatio === 'free') {
                $selectedRatio = 'NaN';
            }

            if (isset($sizeConfiguration['allowedRatios'][$selectedRatio])) {
                $sizeConfiguration = $sizeConfiguration['allowedRatios'][$selectedRatio];
            }
        }
        $dimensions = new Dimensions($sizeConfiguration['width'] ?? null, $sizeConfiguration['height'] ?? null, $sizeConfiguration['ratio'] ?? null);
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
        $breakpoints = $this->configuration['breakpoints'] ?? [];
        if (is_string($breakpoints)) {
            $breakpoints = GeneralUtility::trimExplode(',', $breakpoints);
        }
        return $breakpoints;
    }

    protected function getImageFileFormats(): array
    {
        $imageFileFormats = $this->configuration['imageFileFormats'] ?? [];
        if (empty($imageFileFormats)) {
            return ['_default'];
        }
        if (is_string($imageFileFormats)) {
            $imageFileFormats = GeneralUtility::trimExplode(',', $imageFileFormats);
        }
        return $imageFileFormats;
    }

    protected function getPixelDensities(): array
    {
        $pixelDensities = $this->configuration['pixelDensities'] ?? '';
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
        if (!is_array($sizeConfiguration['breakpoints'] ?? false)) {
            $sizeConfiguration['breakpoints'] = GeneralUtility::trimExplode(',', $sizeConfiguration['breakpoints'] ?? '');
        }
        foreach ($sizeConfiguration['breakpoints'] as $breakpointName) {
            $constraints = [];
            if ($breakpointsConfig[$breakpointName]['from'] ?? false) {
                $constraints[] = '(min-width: ' . $breakpointsConfig[$breakpointName]['from'] . 'px)';
            }
            if ($breakpointsConfig[$breakpointName]['to'] ?? false) {
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
                $melonConfigPerTcaPath[$configurationPath] = ArrayUtility::getValueByPath($this->configuration['croppingConfiguration'] ?? [], $configurationPath);
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
