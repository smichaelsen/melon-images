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
     *         'aspectRatios' => [
     *           '978x450',
     *         ],
     *       ],
     *       'mobile' => [               // breakpoint name as defined in TypoScript
     *         'aspectRatios' => [
     *           '356x338',
     *         ],
     *       ],
     *     ],
     *   ],
     * ];
     *
     * @param array $cropVariants
     * @param string $table
     */
    public static function writeCropVariantsConfigurationToTca(array $cropVariants, string $table = 'tt_content')
    {
        foreach ($cropVariants as $type => $fields) {
            foreach ($fields as $fieldName => $sizes) {
                if ($type === '__default') {
                    $fieldConfig = &
                        $GLOBALS['TCA'][$table]['columns'][$fieldName]
                        ['config']['overrideChildTca']['columns']['crop']['config'];
                } else {
                    $fieldConfig = &
                        $GLOBALS['TCA'][$table]['types'][$type]['columnsOverrides'][$fieldName]
                        ['config']['overrideChildTca']['columns']['crop']['config'];
                }
                $fieldConfig['cropVariants'] = [];
                foreach ($sizes as $size => $sizeConfig) {
                    $fieldConfig['cropVariants'][$size] = [
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
                        $fieldConfig['cropVariants'][$size]['allowedAspectRatios'][$key] = [
                            'title' => $resolutionX . ' x ' . $resolutionY,
                            'value' => $resolutionX / $resolutionY,
                        ];
                    }
                    if (is_array($sizeConfig['coverAreas'])) {
                        $fieldConfig['cropVariants'][$size]['coverAreas'] = $sizeConfig['coverAreas'];
                    }
                    if (is_array($sizeConfig['focusArea'])) {
                        $fieldConfig['cropVariants'][$size]['focusArea'] = $sizeConfig['focusArea'];
                    }
                }
            }
        }
    }
}
