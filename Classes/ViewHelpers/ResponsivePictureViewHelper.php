<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\ViewHelpers;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Domain\Model\FileReference as ExtbaseFileReferenceModel;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;

class ResponsivePictureViewHelper extends AbstractTagBasedViewHelper
{
    protected $tagName = 'picture';

    /**
     * @var ImageService
     */
    protected $imageService;

    public function injectImageService(ImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    public function initializeArguments()
    {
        parent::initializeArguments();
        $this->registerUniversalTagAttributes();
        $this->registerArgument('fileReference', 'mixed', 'File reference to render', true);
        $this->registerArgument('variant', 'string', 'Name of the image variant to use', true);
    }

    public function render(): string
    {
        $fileReference = $this->arguments['fileReference'];
        if ($fileReference instanceof ExtbaseFileReferenceModel) {
            $fileReference = $fileReference->getOriginalResource();
        }
        if (!$fileReference instanceof FileReference) {
            return '';
        }

        $variant = $this->arguments['variant'];

        $cropConfiguration = json_decode((string)$fileReference->getProperty('crop'), true);
        // filter for all crop configurations that match the chosen image variant
        $matchingCropConfiguration = array_filter($cropConfiguration, function ($key) use ($variant) {
            return strpos($key, $variant . '__') === 0;
        }, ARRAY_FILTER_USE_KEY);
        $cropVariants = CropVariantCollection::create(json_encode($matchingCropConfiguration));
        $cropVariantIds = array_keys($matchingCropConfiguration);
        unset($matchingCropConfiguration);

        $sourceMarkups = [];
        $pixelDensities = $this->getPixelDensitiesFromTypoScript();
        foreach ($cropVariantIds as $cropVariantId) {
            $srcset = [];
            $sizeConfiguration = $this->getSizeConfiguration($fileReference, $cropVariantId);

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
            if (!empty($mediaQuery)) {
                $mediaQuery = ' media="' . $mediaQuery . '"';
            }
            $sourceMarkups[] = '<source srcset="' . implode(', ', $srcset) . '"' . $mediaQuery . '>';
        }

        // the last crop variant is used as fallback <img>
        $lastCropVariantId = end($cropVariantIds);
        $sizeConfiguration = $this->getSizeConfiguration($fileReference, $lastCropVariantId);
        $defaultImageUri = $imageUri = $this->processImage(
            $fileReference,
            (int)$sizeConfiguration['width'],
            (int)$sizeConfiguration['height']
        );
        $imgTitle = $fileReference->getTitle() ? 'title="' . htmlspecialchars($fileReference->getTitle()) . '"' : '';
        $sourceMarkups[] = sprintf(
            '<img src="%s" alt="%s" %s>',
            $defaultImageUri,
            $fileReference->getAlternative(),
            htmlspecialchars($imgTitle)
        );

        $this->tag->setContent(implode("\n", $sourceMarkups));
        return $this->tag->render();
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
        return $this->imageService->getImageUri($processedImage, $this->arguments['absolute']);
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

    protected function getSizeConfiguration(FileReference $fileReference, string $cropVariantId): array
    {
        list($variantIdentifier, $sizeIdentifier) = explode('__', $cropVariantId);
        return $this->getMelonImagesConfigForFileReference($fileReference)['variants'][$variantIdentifier]['sizes'][$sizeIdentifier];
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
