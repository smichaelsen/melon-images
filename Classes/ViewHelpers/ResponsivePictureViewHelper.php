<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\ViewHelpers;

use Smichaelsen\MelonImages\BreakpointNotAvailableException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
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
        $this->registerArgument('fileReference', FileReference::class, 'File reference to render', true);
    }

    public function render(): string
    {
        /** @var FileReference $fileReference */
        $fileReference = $this->arguments['fileReference'];
        if (!$fileReference instanceof FileReference) {
            return '';
        }
        $cropString = $fileReference->getProperty('crop');
        $cropVariantCollection = CropVariantCollection::create((string) $cropString);
        $sourceMarkups = [];
        $breakpoints = $this->getBreakpointsFromTypoScript();
        $dpiBreakpoints = $this->getDpiBreakpointsFromTypoScript();
        $i = 0;
        $lastTargetResolution = null;
        foreach ($breakpoints as $breakpointName => $breakpoint) {
            $i++;
            try {
                $targetResolution = $this->getTargetResolution($fileReference, $breakpointName);
            } catch (BreakpointNotAvailableException $e) {
                continue;
            }
            $lastTargetResolution = $targetResolution;
            $cropArea = $cropVariantCollection->getCropArea($breakpointName);
            $srcset = [];
            foreach ($dpiBreakpoints as $dpiBreakpoint) {
                $imageUri = $this->processImage(
                    $fileReference,
                    (int) ($targetResolution[0] * $dpiBreakpoint),
                    (int) ($targetResolution[1] * $dpiBreakpoint),
                    $cropArea
                );
                $srcset[] = $imageUri . ' ' . $dpiBreakpoint . 'x';
            }
            if (!empty($breakpoint)) {
                $sourceMarkups[] = '<source srcset="' . implode(', ', $srcset) . '" media="' . $breakpoint . '">';
            } else {
                $sourceMarkups[] = '<source srcset="' . implode(', ', $srcset) . '">';
            }
        }
        // the last available breakpoint will be used for the fallback image
        if (is_array($lastTargetResolution)) {
            $defaultImageUri = $imageUri = $this->processImage(
                $fileReference,
                (int) $lastTargetResolution[0],
                (int) $lastTargetResolution[1]
            );
            $imgTitle = $fileReference->getTitle() ? ' title="' . $fileReference->getTitle() . '"' : '';
            $sourceMarkups[] = sprintf(
                '<img src="%s" alt="%s"%s>',
                $defaultImageUri,
                $fileReference->getAlternative(),
                $imgTitle
            );
        }

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
        return $this->getTypoScriptSettings()['breakpoints.'];
    }

    protected function getDpiBreakpointsFromTypoScript(): array
    {
        return GeneralUtility::trimExplode(',', $this->getTypoScriptSettings()['pixelDensities']);
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
            $typoScriptSettings = $typoscript['package.']['smichaelsen.']['melon-images.'];
        }
        return $typoScriptSettings;
    }

    protected function getTargetResolution(FileReference $fileReference, string $breakpointName): array
    {
        $cropVariants = $this->getCropVariantsForFileReference($fileReference);
        if (!array_key_exists($breakpointName, $cropVariants)) {
            throw new BreakpointNotAvailableException(
                'FileReference isn\'t available in given breakpoint "' . $breakpointName . '"',
                1497511626
            );
        }
        return explode('x', array_keys($cropVariants[$breakpointName]['allowedAspectRatios'])[0]);
    }

    protected function getCropVariantsForFileReference(FileReference $fileReference): array
    {
        static $fileReferenceToRecordTca = [];
        if (!is_array($fileReferenceToRecordTca[$fileReference->getUid()])) {
            $table = $fileReference->getProperty('tablenames');
            $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'];
            $fieldName = $fileReference->getProperty('fieldname');
            if (empty($typeField)) {
                $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$fieldName]['config'];
            } else {
                $record = BackendUtility::getRecord($table, $fileReference->getProperty('uid_foreign'), $typeField);
                $type = $record[$typeField];
                $fieldConfig = $GLOBALS['TCA'][$table]['types'][$type]['columnsOverrides'][$fieldName]['config'];
            }
            $cropVariants = $fieldConfig['overrideChildTca']['columns']['crop']['config']['cropVariants'];
            if (!is_array($cropVariants)) {
                throw new \Exception(
                    'There are no cropVariants defined for table: ' . $table . ' / type:' . ($type ?? '[no type]'),
                    1497512877
                );
            }
            $fileReferenceToRecordTca[$fileReference->getUid()] = $cropVariants;
        }
        return $fileReferenceToRecordTca[$fileReference->getUid()];
    }
}
