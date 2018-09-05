<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\ViewHelpers;

use Smichaelsen\MelonImages\Service\ImageDataProvider;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Extbase\Domain\Model\FileReference as ExtbaseFileReferenceModel;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;

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

        $tagContent = '';
        foreach ($variantData['sources'] as $source) {
            $mediaQuery = $source['mediaQuery'];
            if (!empty($mediaQuery)) {
                $mediaQuery = ' media="' . $mediaQuery . '"';
            }
            $tagContent .= '<source srcset="' . implode(', ', $source['srcsets']) . '"' . $mediaQuery . '>' . "\n";
        }

        $title = $fileReference->getTitle() ? 'title="' . htmlspecialchars($fileReference->getTitle()) . '"' : '';
        $tagContent .= sprintf(
            '<img src="%s" alt="%s" %s>',
            $variantData['fallbackImage']['src'],
            htmlspecialchars((string)$fileReference->getAlternative()),
            $title
        );

        $this->tag->setContent($tagContent);
        return $this->tag->render();
    }
}
