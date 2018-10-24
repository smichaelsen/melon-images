<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
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

    public function getImageVariantData(FileReference $fileReference, string $variant, $fallbackImageSize = null): ?array
    {
        $cropConfiguration = json_decode((string)$fileReference->getProperty('crop'), true);
        if ($cropConfiguration === null) {
            return null;
        }
        // filter for all crop configurations that match the chosen image variant
        $matchingCropConfiguration = array_filter($cropConfiguration, function ($key) use ($variant) {
            return strpos($key, $variant . '__') === 0;
        }, ARRAY_FILTER_USE_KEY);
        $cropVariants = CropVariantCollection::create(json_encode($matchingCropConfiguration));
        $cropVariantIds = array_keys($matchingCropConfiguration);
        unset($matchingCropConfiguration);

        $sources = [];
        $pixelDensities = $this->getPixelDensitiesFromTypoScript();
        $lastCropVariantId = null;
        foreach ($cropVariantIds as $cropVariantId) {
            $srcset = [];
            $sizeConfiguration = $this->getSizeConfiguration($fileReference, $cropVariantId);
            if ($sizeConfiguration === null) {
                continue;
            }
            $sizeIdentifier = explode('__', $cropVariantId)[1];

            foreach ($pixelDensities as $pixelDensity) {
                $imageUri = $this->processImage(
                    $fileReference,
                    (int)round(($sizeConfiguration['width'] * $pixelDensity)),
                    (int)round(($sizeConfiguration['height'] * $pixelDensity)),
                    $cropVariants->getCropArea($cropVariantId)
                );
                $srcset[] = $imageUri . ' ' . $pixelDensity . 'x';
            }

            $mediaQuery = $this->getMediaQueryFromSizeConfig($sizeConfiguration);

            $sources[$sizeIdentifier] = [
                'srcsets' => $srcset,
                'mediaQuery' => $mediaQuery,
                'width' => (int)$sizeConfiguration['width'],
                'height' => (int)$sizeConfiguration['height'],
            ];
            $lastCropVariantId = $cropVariantId;
        }

        $cropVariantId = $fallbackImageSize ? ($variant . '__' . $fallbackImageSize) : $lastCropVariantId;
        if ($cropVariantId === null) {
            return null;
        }
        $sizeConfiguration = $this->getSizeConfiguration($fileReference, $cropVariantId);
        $defaultImageUri = $imageUri = $this->processImage(
            $fileReference,
            (int)$sizeConfiguration['width'],
            (int)$sizeConfiguration['height'],
            $cropVariants->getCropArea($cropVariantId)
        );

        return [
            'sources' => $sources,
            'fallbackImage' => [
                'src' => $defaultImageUri,
                'width' => (int)$sizeConfiguration['width'],
                'height' => (int)$sizeConfiguration['height'],
            ],
        ];
    }

    protected function processImage(
        FileReference $fileReference,
        int $width,
        int $height,
        $cropArea = null
    ): string {
        if ($cropArea instanceof Area && !$cropArea->isEmpty()) {
            $cropArea = $cropArea->makeAbsoluteBasedOnFile($fileReference);
        } else {
            $cropArea = null;
        }
        $processingInstructions = [
            'width' => $width,
            'height' => $height,
            'crop' => $cropArea,
        ];
        $processedImage = $this->imageService->applyProcessingInstructions($fileReference, $processingInstructions);
        return $this->imageService->getImageUri($processedImage);
    }

    protected function getSizeConfiguration(FileReference $fileReference, string $cropVariantId): ?array
    {
        list($variantIdentifier, $sizeIdentifier) = explode('__', $cropVariantId);
        return $this->getMelonImagesConfigForFileReference($fileReference)['variants'][$variantIdentifier]['sizes'][$sizeIdentifier] ?? null;
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

    protected function getMelonImagesConfigForFileReference(FileReference $fileReference): array
    {
        static $melonConfigPerFileReferenceUid = [];
        if (!isset($melonConfigPerFileReferenceUid[$fileReference->getUid()])) {
            $melonConfigPerFileReferenceUid[$fileReference->getUid()] = (function () use ($fileReference) {
                $typoScriptSettings = $this->getTypoScriptSettings();
                $table = $fileReference->getProperty('tablenames');
                $tableSettings = $typoScriptSettings['croppingConfiguration'][$table];
                unset($typoScriptSettings);
                $fieldName = $fileReference->getProperty('fieldname');
                $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'];
                if ($typeField) {
                    $record = BackendUtility::getRecord($table, $fileReference->getProperty('uid_foreign'), $typeField);
                    $type = $record[$typeField];
                    if ($tableSettings[$type]) {
                        return $tableSettings[$type][$fieldName];
                    }
                }
                return $tableSettings['_all'][$fieldName];
            })();
        }
        return $melonConfigPerFileReferenceUid[$fileReference->getUid()];
    }
}
