<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\ViewHelpers;

use Smichaelsen\MelonImages\Service\ImageDataProvider;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Extbase\Domain\Model\FileReference as ExtbaseFileReferenceModel;
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
        $this->registerArgument('fileReference', 'mixed', 'File reference to render', true);
        $this->registerArgument('variant', 'string', 'Name of the image variant to use', true);
        $this->registerArgument('fallbackImageSize', 'string', 'Specify the size config to be used for the fallback image. Default is the last config.', false, null);
        $this->registerArgument('as', 'string', 'Variable name for the picture data if you want to render with your own markup.', false, null);
        $this->registerArgument('additionalImageAttributes', 'array', 'Additional attributes to be applied to the img tag', false, []);
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
        $fallbackImageSize = $this->arguments['fallbackImageSize'];
        $variantData = $this->imageDataProvider->getImageVariantData($fileReference, $variant, $fallbackImageSize);

        if ($this->arguments['as']) {
            $this->templateVariableContainer->add($this->arguments['as'], $variantData);
            $content = $this->renderChildren();
            $this->templateVariableContainer->remove($this->arguments['as']);
            return $content;
        }

        // auto render
        $tagContent = '';
        foreach ($variantData['sources'] as $source) {
            $tagContent .= $this->renderSourceTag($source['srcsets'], $source['mediaQuery']);
        }
        $tagContent .= $this->renderImageTag(
            $variantData['fallbackImage']['src'],
            (string)$fileReference->getAlternative(),
            (string)$fileReference->getTitle(),
            $this->arguments['additionalImageAttributes']
        );

        $this->tag->setContent($tagContent);
        return $this->tag->render();
    }

    protected function renderSourceTag(array $sourceSets, string $mediaQuery): string
    {
        $tag = new TagBuilder('source');
        $tag->addAttribute('srcset', implode(', ', $sourceSets));
        if (!empty($mediaQuery)) {
            $tag->addAttribute('media', $mediaQuery);
        }
        return $tag->render();
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
