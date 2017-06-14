<?php
declare(strict_types=1);

namespace Smichaelsen\MelonImages;

class TcaUtility
{

    /**
     * Example:
     * $cropVariants = [
     *   'my-content-element' => [        // CType
     *     'image' => [                   // field name
     *       'desktop' => [               // breakpoint name as defined in TypoScript
     *         'coverAreas' => [
     *           [
     *             'x' => 0.55,
     *             'y' => 0.2,
     *             'width' => 0.45,
     *             'height' => 0.8,
     *           ],
     *         ],
     *         'resolutions' => [
     *           '978x450',
     *         ],
     *       ],
     *       'mobile' => [               // breakpoint name as defined in TypoScript
     *         'resolutions' => [
     *           '356x338',
     *         ],
     *       ],
     *     ],
     *   ],
     * ];
     *
     * @param array $cropVariants
     */
    public static function writeCropVariantsConfigurationToTca(array $cropVariants)
    {
        foreach ($cropVariants as $cType => $fields) {
            foreach ($fields as $fieldName => $sizes) {
                $GLOBALS['TCA']['tt_content']['types'][$cType]['columnsOverrides'][$fieldName]['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'] = [];
                foreach ($sizes as $size => $sizeConfig) {
                    $GLOBALS['TCA']['tt_content']['types'][$cType]['columnsOverrides'][$fieldName]['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'][$size] = [
                        'title' => ucfirst($size),
                        'allowedAspectRatios' => [],
                    ];
                    foreach ($sizeConfig['aspectRatios'] as $aspectRatio) {
                        list($resolutionX, $resolutionY) = explode('x', $aspectRatio);
                        if (isset($sizeConfig['width'])) {
                            $key = $sizeConfig['width'] . 'x' . ($sizeConfig['width'] / $resolutionX) * $resolutionY;
                        } else {
                            $key = $aspectRatio;
                        }
                        $GLOBALS['TCA']['tt_content']['types'][$cType]['columnsOverrides'][$fieldName]['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'][$size]['allowedAspectRatios'][$key] = [
                            'title' => $resolutionX . ' x ' . $resolutionY,
                            'value' => $resolutionX / $resolutionY,
                        ];
                    }
                    if (is_array($sizeConfig['coverAreas'])) {
                        $GLOBALS['TCA']['tt_content']['types'][$cType]['columnsOverrides'][$fieldName]['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'][$size]['coverAreas'] = $sizeConfig['coverAreas'];
                    }
                    if (is_array($sizeConfig['focusArea'])) {
                        $GLOBALS['TCA']['tt_content']['types'][$cType]['columnsOverrides'][$fieldName]['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'][$size]['focusArea'] = $sizeConfig['focusArea'];
                    }
                }
            }
        }
    }

}
