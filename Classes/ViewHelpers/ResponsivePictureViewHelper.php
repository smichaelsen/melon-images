<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\ViewHelpers;

use Smichaelsen\MelonImages\Domain\Dto\Source;
use Smichaelsen\MelonImages\Service\ImageDataProvider;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Domain\Model\FileReference as ExtbaseFileReferenceModel;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\TagBuilder;

class ResponsivePictureViewHelper extends AbstractTagBasedViewHelper
{
    protected $tagName = 'picture';

    public function __construct(
        protected ImageDataProvider $imageDataProvider,
        protected ImageService $imageService,
        protected ResourceFactory $resourceFactory
    )
    {
        parent::__construct();
    }

    public function initializeArguments()
    {
        parent::initializeArguments();
        $this->registerUniversalTagAttributes();
        $this->registerArgument('fileReference', 'mixed', 'File reference to render (can be a core FileReference, an extbase FileReference, a sys_file_reference record array or the uid of a file reference)', true);
        $this->registerArgument('variant', 'string', 'Name of the image variant to use', true);
        $this->registerArgument('fallbackImageSize', 'string', 'Specify the size config to be used for the fallback image. Default is the last config.', false);
        $this->registerArgument('as', 'string', 'Variable name for the picture data if you want to render with your own markup.', false);
        $this->registerArgument('additionalImageAttributes', 'array', 'Additional attributes to be applied to the img tag', false, []);
        $this->registerArgument('absolute', 'boolean', 'Generate absolute image URLs', false, false);
        $this->registerArgument('useCroppingFrom', 'mixed', 'Alternative file reference as cropping source', false);
    }

    public function render(): string
    {
        $fileReference = $this->arguments['fileReference'];
        if (is_numeric($fileReference)) {
            $fileReference = $this->resourceFactory->getFileReferenceObject((int)$fileReference);
        } elseif (is_array($fileReference)) {
            $fileReference = $this->resourceFactory->getFileReferenceObject((int)$fileReference['uid']);
        } elseif ($fileReference instanceof ExtbaseFileReferenceModel) {
            $fileReference = $fileReference->getOriginalResource();
        }
        if (!$fileReference instanceof FileReference || $fileReference->getPublicUrl() === null) {
            return '';
        }
        $useCroppingFrom = $this->arguments['useCroppingFrom'];
        if (is_array($useCroppingFrom)) {
            $useCroppingFrom = $this->resourceFactory->getFileReferenceObject($useCroppingFrom['uid']);
        } elseif (is_array($useCroppingFrom)) {
            $fileReference = $this->resourceFactory->getFileReferenceObject((int)$useCroppingFrom['uid']);
        } elseif ($useCroppingFrom instanceof ExtbaseFileReferenceModel) {
            $useCroppingFrom = $useCroppingFrom->getOriginalResource();
        }

        $variant = $this->arguments['variant'];
        $fallbackImageSize = $this->arguments['fallbackImageSize'];
        $variantData = $this->imageDataProvider->getImageVariantData($fileReference, $variant, $fallbackImageSize, $this->arguments['absolute'], $useCroppingFrom);

        if ($this->arguments['as']) {
            $this->templateVariableContainer->add($this->arguments['as'], $variantData);
            $content = $this->renderChildren();
            $this->templateVariableContainer->remove($this->arguments['as']);
            return $content;
        }

        if ($variantData === null) {
            // variant data could not be loaded. fallback rendering (without taking care of image size or aspect ratio):
            $tagContent = $this->renderImageTag(
                $this->getImageUri($fileReference),
                (string)$fileReference->getAlternative(),
                (string)$fileReference->getTitle(),
                $this->arguments['additionalImageAttributes']
            );
        } else {
            // auto render
            $additionalAttributes = (array)$this->arguments['additionalImageAttributes'];
            if (!isset($additionalAttributes['width']) && !isset($additionalAttributes['height'])) {
                /** @var ProcessedFile $processedFile */
                $processedFile = $variantData['fallbackImage']['processedFile'];
                $additionalAttributes['width'] = $processedFile->getProperty('width');
                $additionalAttributes['height'] = $processedFile->getProperty('height');
            }

            $tagContent = '';
            foreach ($variantData['sources'] as $source) {
                /** @var Source $source */
                $tagContent .= $source->getHtml();
            }
            $tagContent .= $this->renderImageTag(
                $variantData['fallbackImage']['src'],
                (string)$fileReference->getAlternative(),
                (string)$fileReference->getTitle(),
                $additionalAttributes
            );
        }

        $this->tag->setContent($tagContent);
        return $this->tag->render();
    }

    protected function renderImageTag(string $src, string $alternative = '', string $title = null, array $additionalAttributes = []): string
    {
        $tag = new TagBuilder('img');
        $tag->addAttribute('src', $src);
        $tag->addAttribute('alt', $alternative);
        if (!empty($title)) {
            $tag->addAttribute('title', $title);
        }
        foreach ($additionalAttributes as $name => $value) {
            $tag->addAttribute($name, $value);
        }
        return $tag->render();
    }

    protected function getImageUri(FileReference $fileReference): string
    {
        if (!$fileReference instanceof FileReference) {
            return '';
        }
        $imageSource = $this->imageService->getImageUri($fileReference);
        if ($fileReference->getType() !== File::FILETYPE_APPLICATION) {
            return $imageSource;
        }
        $image = $this->imageService->getImage($imageSource, $fileReference, true);
        $processingInstructions = [];
        $processedImage = $this->imageService->applyProcessingInstructions($image, $processingInstructions);
        return $processedImage->getPublicUrl();
    }
}
