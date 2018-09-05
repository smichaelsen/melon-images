<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages\ViewHelpers;

use Smichaelsen\MelonImages\Service\ImageDataProvider;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Extbase\Domain\Model\FileReference as ExtbaseFileReferenceModel;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class CroppedImageUriViewHelper extends AbstractViewHelper
{

    /**
     * @var ImageDataProvider
     *
     */
    protected $imageDataProvider;

    public function injectImageDataProvider(ImageDataProvider $imageDataProvider)
    {
        $this->imageDataProvider = $imageDataProvider;
    }

    public function initializeArguments()
    {
        parent::initializeArguments();
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
        $variantData = $this->imageDataProvider->getImageVariantData($fileReference, $variant);

        return $variantData['fallbackImageSrc'];
    }

}
