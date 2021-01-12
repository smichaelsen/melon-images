<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\ViewHelpers;

use Smichaelsen\MelonImages\Domain\Dto\Source;
use Smichaelsen\MelonImages\Service\ImageDataProvider;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Domain\Model\FileReference as ExtbaseFileReferenceModel;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\TagBuilder;

class ResponsivePictureViewHelper extends AbstractTagBasedViewHelper
{
    protected $tagName = 'picture';

    /**
     * @var ImageDataProvider
     */
    protected $imageDataProvider;

    public function injectImageDataProvider(ImageDataProvider $imageDataProvider)
    {
        $this->imageDataProvider = $imageDataProvider;
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
            $fileReference = ResourceFactory::getInstance()->getFileReferenceObject((int)$fileReference);
        } elseif (is_array($fileReference)) {
            $fileReference = ResourceFactory::getInstance()->getFileReferenceObject((int)$fileReference['uid']);
        } elseif ($fileReference instanceof ExtbaseFileReferenceModel) {
            $fileReference = $fileReference->getOriginalResource();
        }
        if (!$fileReference instanceof FileReference || $fileReference->getPublicUrl() === null) {
            return '';
        }
        $useCroppingFrom = $this->arguments['useCroppingFrom'];
        if (is_array($useCroppingFrom)) {
            $useCroppingFrom = ResourceFactory::getInstance()->getFileReferenceObject($useCroppingFrom['uid']);
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
            $imageService = GeneralUtility::makeInstance(ObjectManager::class)->get(ImageService::class);
            $tagContent = $this->renderImageTag(
                $imageService->getImageUri($fileReference),
                (string)$fileReference->getAlternative(),
                (string)$fileReference->getTitle(),
                $this->arguments['additionalImageAttributes']
            );
        } else {
            // auto render
            $tagContent = '';
            foreach ($variantData['sources'] as $source) {
                /** @var Source $source */
                $tagContent .= $source->getHtml();
            }
            $tagContent .= $this->renderImageTag(
                $variantData['fallbackImage']['src'],
                (string)$fileReference->getAlternative(),
                (string)$fileReference->getTitle(),
                $this->arguments['additionalImageAttributes']
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
}
