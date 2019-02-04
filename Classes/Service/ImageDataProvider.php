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

    public function getImageVariantData(FileReference $fileReference, string $variant, $fallbackImageSize = null, $absolute = false): ?array
    {
        $cropConfiguration = json_decode((string)$fileReference->getProperty('crop'), true);
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
        unset($matchingCropConfiguration);

        $sources = [];
        $pixelDensities = $this->getPixelDensitiesFromTypoScript();
        /** @var string $fallbackCropVariantId */
        $fallbackCropVariantId = null;
        foreach ($cropVariantIds as $cropVariantId) {
            $srcset = [];
            $sizeConfiguration = $this->getSizeConfiguration($cropVariantId);
            if ($sizeConfiguration === null) {
                continue;
            }

            // the size identifier is the last segment in the cropVariantId
            $sizeIdentifier = array_pop(explode('__', $cropVariantId));

            foreach ($pixelDensities as $pixelDensity) {
                if (isset($sizeConfiguration['width'])) {
                    $width = (int)round(($sizeConfiguration['width'] * $pixelDensity));
                } else {
                    $width = null;
                }
                if (isset($sizeConfiguration['height'])) {
                    $height = (int)round(($sizeConfiguration['height'] * $pixelDensity));
                } else {
                    $height = null;
                }
                $processedImage = $this->processImage($fileReference, $width, $height, $cropVariants->getCropArea($cropVariantId));
                $imageUri = $this->imageService->getImageUri($processedImage, $absolute);
                $srcset[] = $imageUri . ' ' . $pixelDensity . 'x';
            }

            $mediaQuery = $this->getMediaQueryFromSizeConfig($sizeConfiguration);

            $sources[$sizeIdentifier] = [
                'srcsets' => $srcset,
                'mediaQuery' => $mediaQuery,
                'width' => (int)$sizeConfiguration['width'],
                'height' => (int)$sizeConfiguration['height'],
            ];

            if ($fallbackImageSize) {
                if ($fallbackImageSize === $sizeIdentifier) {
                    $fallbackCropVariantId = $cropVariantId;
                }
            } else {
                $fallbackCropVariantId = $cropVariantId;
            }
        }
        if ($fallbackCropVariantId === null) {
            return null;
        }
        $sizeConfiguration = $this->getSizeConfiguration($fallbackCropVariantId);
        $fallbackWidth = $sizeConfiguration['width'] ? (int)$sizeConfiguration['width'] : null;
        $fallbackHeight = $sizeConfiguration['height'] ? (int)$sizeConfiguration['height'] : null;
        $processedFallbackImage = $this->processImage(
            $fileReference,
            $fallbackWidth,
            $fallbackHeight,
            $cropVariants->getCropArea($fallbackCropVariantId)
        );
        $fallbackImageConfig = [
            'src' => $this->imageService->getImageUri($processedFallbackImage, $absolute),
            'width' => $processedFallbackImage->getProperties()['width'],
            'height' => $processedFallbackImage->getProperties()['height'],
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

    protected function getSizeConfiguration(string $cropVariantId): ?array
    {
        $segments = explode('__', $cropVariantId);
        $sizeIdentifier = array_pop($segments);
        $variantIdentifier = array_pop($segments);
        $typoscriptPath = implode('/', $segments);
        if (empty($typoscriptPath)) {
            return null;
        }
        return $this->getMelonImagesConfigForTyposcriptPath($typoscriptPath)['variants'][$variantIdentifier]['sizes'][$sizeIdentifier] ?? null;
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
