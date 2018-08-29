# Melon Images
**Responsive Images Management for TYPO3**

This package uses the powerful responsive image cropping capabilities of TYPO3 and provides easy frontend rendering.

![Image Cropping](doc/image-cropping.png?raw=true "Image Cropping")

TYPO3 8.7 comes with the powerful feature of `cropVariant`s, which let's you define use cases for your image including `allowedAspectRatios` and optionally `coverAreas`.
This package simplifies the configuration of this feature.

## Backend Configuration:

 This package provides the method `\Smichaelsen\MelonImages\TcaUtility::writeCropVariantsConfigurationToTca()`, which manipulates the TCA,
 so in your extension `Configuration/TCA/Overrides/tx_news_domain_model_news.php` is a good place if you want to define cropping for a
 news image field.
 
 This defines 3 crop variants for the `fal_media` field of news:
 
 ````
 \Smichaelsen\MelonImages\TcaUtility::writeCropVariantsConfigurationToTca(
     [
         '__default' => [ // __default = for all news types
             'fal_media' => [
                 'detail' => [
                     'title' => 'Detail Page',
                     'aspectRatios' => [
                         '943 x 419',
                     ],
                 ],
                 'teaser_featured' => [
                     'title' => 'Featured Teaser',
                     'aspectRatios' => [
                         '748 x 420',
                     ],
                 ],
                 'teaser' => [
                     'title' => 'Teaser',
                     'aspectRatios' => [
                         '360 x 240', // using '3 x 2' would have the same effect
                     ],
                     'coverAreas' => [
                         [
                             'x' => 0.2,
                             'y' => 0.7,
                             'width' => 0.8,
                             'height' => 0.3,
                         ],
                     ],
                 ],
             ],
         ],
     ],
     'tx_news_domain_model_news'
 );
 ````

The first level of the array holds the record type for which you want to configure image cropping. `__default` means the config is valid
for all record types. For `pages` you can use doktype constants such as `\TYPO3\CMS\Frontend\Page\PageRepository::DOKTYPE_DEFAULT`, for
`tt_content` you would use the `CType` of the content element you want to configure.

The second level of the array holds the name of the field you want to configure the cropping for.

The third level holds the _identifier_ of the crop variant. You can choose it arbitrarily, but you'll need to reference it later in
TypoScript so keep it without spaces or dots. 

The fourth level holds a config array with following options:

* **title**: A human readable title of the crop variant to be displayed to the backend user. Optional, by default the *identifier* is used with `ucfirst()`.
* **aspectRatios**: Array of aspect ratios that are valid for this crop variant. An aspect ratio is a string in the form `width x height` (e.g. `16 x 9`). 
* **coverAreas**: Array of cover areas that apply to this crop variant. A crop variant is configured as an array with the keys `x`, `y`, `width` and `height`. `x` and `y` set the position of the top left corner of a cover area. All values are relative values between 0 and 1.

## Frontend Configuration

*TypoScript, tbd ...*

## Rendering

*Render with the provided ViewHelper, tbd ...*
