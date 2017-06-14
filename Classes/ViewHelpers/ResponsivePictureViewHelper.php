<?php
declare(strict_types=1);

namespace Smichaelsen\MelonImages\ViewHelpers;

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
        $cropString = $fileReference->getProperty('crop');
        $cropVariantCollection = CropVariantCollection::create((string) $cropString);
        $sourceMarkups = [];
        $breakpoints = $this->getBreakpointsFromTypoScript();
        $dpiBreakpoints = $this->getDpiBreakpointsFromTypoScript();
        $i = 0;
        foreach ($breakpoints as $breakpointName => $breakpoint) {
            $cropArea = $cropVariantCollection->getCropArea($breakpointName);
            $targetResolution = $this->getTargetResolution($fileReference, $breakpointName);
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
            $sourceMarkups[] = '<source srcset="' . join(', ', $srcset) . '" media="' . $breakpoint . '">';

            // the last defined breakpoint will be used for the fallback image
            if (++$i === count($breakpoints)) {
                $defaultImageUri = $imageUri = $this->processImage(
                    $fileReference,
                    (int) $targetResolution[0],
                    (int) $targetResolution[1],
                    $cropArea
                );
                $sourceMarkups[] = '<img src="' . $defaultImageUri . '" alt="' . $fileReference->getAlternative() . '">';
            }
        }
        $this->tag->setContent(join("\n", $sourceMarkups));
        return $this->tag->render();
    }

    protected function processImage(FileReference $fileReference, int $width, int $height, ?Area $cropArea): string
    {
        $processingInstructions = [
            'width' => $width,
            'height' => $height,
            'crop' => $cropArea->isEmpty() ? null : $cropArea->makeAbsoluteBasedOnFile($fileReference),
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
        return GeneralUtility::trimExplode(',', $this->getTypoScriptSettings()['dpiBreakpoints']);
    }

    protected function getTypoScriptSettings(): array
    {
        static $typoScriptSettings;
        if (!is_array($typoScriptSettings)) {
            $configurationManager = GeneralUtility::makeInstance(ObjectManager::class)->get(ConfigurationManagerInterface::class);
            $typoscript = $configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);
            $typoScriptSettings = $typoscript['lib.']['responsiveImages.'];
        }
        return $typoScriptSettings;
    }

    protected function getTargetResolution(FileReference $fileReference, string $breakpointName): array
    {
        $cropVariants = $this->getCropVariantsForFileReference($fileReference);
        return explode('x', array_keys($cropVariants[$breakpointName]['allowedAspectRatios'])[0]);
    }

    protected function getCropVariantsForFileReference(FileReference $fileReference): array
    {
        static $fileReferenceToRecordTca = [];
        if (!is_array($fileReferenceToRecordTca[$fileReference->getUid()])) {
            $table = $fileReference->getProperty('tablenames');
            $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'];
            $record = BackendUtility::getRecord($table, $fileReference->getProperty('uid_foreign'), $typeField);
            $type = $record[$typeField];
            $fieldName = $fileReference->getProperty('fieldname');
            $columnsOverrides = $GLOBALS['TCA'][$table]['types'][$type]['columnsOverrides'];
            $cropVariants = $columnsOverrides[$fieldName]['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'];
            $fileReferenceToRecordTca[$fileReference->getUid()] = $cropVariants;
        }
        return $fileReferenceToRecordTca[$fileReference->getUid()];
    }

}
