<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages;

use Smichaelsen\MelonImages\Configuration\Registry;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

class TcaUtility
{
    public static function registerCropVariantsTca()
    {
        $configurationRegistry = GeneralUtility::makeInstance(Registry::class);
        $configuration = $configurationRegistry->getParsedConfiguration();
        if (empty($configuration)) {
            return;
        }
        foreach ($configuration['croppingConfiguration'] as $tableName => $tableConfiguration) {
            foreach ($tableConfiguration as $type => $fields) {
                foreach ($fields as $fieldName => $fieldConfig) {
                    $variantIdPrefixParts = [$tableName, $type, $fieldName];
                    $GLOBALS['TCA'][$tableName] = self::writeFieldConfigToTCA($GLOBALS['TCA'][$tableName], (string)$type, $fieldName, $fieldConfig, $variantIdPrefixParts);
                }
            }
        }
    }

    protected static function writeFieldConfigToTCA(array $tableTca, string $type, string $fieldName, array $fieldConfig, array $variantIdPrefixParts): array
    {
        $fieldPath = self::getFieldTcaPath($fieldName, $type);
        $childTcaPath = $fieldPath . '/config/overrideChildTca';
        if (isset($fieldConfig['variants'])) {
            $cropVariantsPath = $childTcaPath . '/columns/crop/config/cropVariants';
            $tableTca = ArrayUtility::setValueByPath(
                $tableTca,
                $cropVariantsPath,
                self::createCropVariantsTcaForField($fieldConfig, $variantIdPrefixParts)
            );
        } else {
            foreach ($fieldConfig as $subType => $subFields) {
                foreach ($subFields as $subFieldName => $subFieldConfig) {
                    $subVariantPrefixParts = $variantIdPrefixParts;
                    $subVariantPrefixParts[] = $subType;
                    $subVariantPrefixParts[] = $subFieldName;
                    try {
                        $childTca = ArrayUtility::getValueByPath($tableTca, $childTcaPath);
                    } catch (\RuntimeException $e) {
                        // path does not exist
                        if ($e->getCode() === 1341397869) {
                            $childTca = [];
                        } else {
                            throw $e;
                        }
                    }
                    $tableTca = ArrayUtility::setValueByPath(
                        $tableTca,
                        $childTcaPath,
                        self::writeFieldConfigToTCA(
                            $childTca,
                            $subType,
                            $subFieldName,
                            $subFieldConfig,
                            $subVariantPrefixParts
                        )
                    );
                }
            }
        }
        return $tableTca;
    }

    protected static function getFieldTcaPath(string $fieldName, string $type): string
    {
        if ($type === '_all') {
            $path = 'columns/' . $fieldName;
        } else {
            $path = 'types/' . $type . '/columnsOverrides/' . $fieldName;
        }
        return $path;
    }

    public static function getAspectRatiosFromSizes(array $sizes): array
    {
        $aspectRatios = [];
        foreach ($sizes as $sizeIdentifier => $sizeConfig) {
            if (isset($sizeConfig['ratio'])) {
                $identifier = $sizeConfig['ratio'];
                $calculatedRatio = MathUtility::calculateWithParentheses($sizeConfig['ratio']);
            } else {
                $identifier = $sizeIdentifier;
            }
            if (isset($sizeConfig['width']) && isset($calculatedRatio) && empty($sizeConfig['height'])) {
                // derive height from width and ratio
                $sizeConfig['height'] = round($sizeConfig['width'] / $calculatedRatio);
            } elseif (isset($sizeConfig['height']) && isset($calculatedRatio) && empty($sizeConfig['width'])) {
                // derive width from height and ratio
                $sizeConfig['width'] = round($sizeConfig['width'] * $calculatedRatio);
            }
            $aspectRatios[$identifier] = $sizeConfig;
        }
        return $aspectRatios;
    }

    protected static function createCropVariantsTcaForField(array $fieldConfig, array $variantIdPrefixParts): array
    {
        $cropVariantsTca = [];
        if (!isset($fieldConfig['variants'])) {
            return [];
        }
        foreach ($fieldConfig['variants'] as $variantIdentifier => $variantConfig) {
            $variantTitle = $variantConfig['title'] ?: ucfirst($variantIdentifier);
            $aspectRatioConfigs = self::getAspectRatiosFromSizes($variantConfig['sizes']);
            foreach ($aspectRatioConfigs as $aspectRatioIdentifier => $aspectRatioConfig) {
                if (count($aspectRatioConfigs) === 1) {
                    $cropVariantTitle = $variantTitle;
                } else {
                    $cropVariantTitle = $variantTitle . ' ' . ucfirst($aspectRatioIdentifier);
                }
                $cropVariantKey = implode('__', $variantIdPrefixParts) . '__' . $variantIdentifier . '__' . $aspectRatioIdentifier;
                $cropVariantsTca[$cropVariantKey] = [
                    'title' => $cropVariantTitle,
                ];
                $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'] = [];

                if (isset($aspectRatioConfig['width'], $aspectRatioConfig['height'])) {
                    $ratio = $aspectRatioConfig['ratio'] ?? ($aspectRatioConfig['width'] . ' x ' . $aspectRatioConfig['height']);
                    $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'][$ratio] = [
                        'title' => $ratio,
                        'value' => $aspectRatioConfig['width'] / $aspectRatioConfig['height'],
                    ];
                }
                if (isset($aspectRatioConfig['allowedRatios']) && count($aspectRatioConfig['allowedRatios']) > 0) {
                    foreach ($aspectRatioConfig['allowedRatios'] as $dimensionKey => $dimensionConfig) {
                        $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'][$dimensionKey] = [
                            'title' => $dimensionConfig['title'] ?? $dimensionKey,
                            'value' => $dimensionConfig['width'] / $dimensionConfig['height'],
                        ];
                    }
                }
                if (count($cropVariantsTca[$cropVariantKey]['allowedAspectRatios']) === 0) {
                    $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'] = [
                        'NaN' => [
                            'title' => 'LLL:EXT:lang/Resources/Private/Language/locallang_wizards.xlf:imwizard.ratio.free',
                            'value' => .0,
                        ],
                    ];
                }
                if (!empty($aspectRatioConfig['coverAreas'])) {
                    $cropVariantsTca[$cropVariantKey]['coverAreas'] = $aspectRatioConfig['coverAreas'];
                }
                if (!empty($aspectRatioConfig['focusArea'])) {
                    $cropVariantsTca[$cropVariantKey]['focusArea'] = $aspectRatioConfig['focusArea'];
                }
            }
        }
        return $cropVariantsTca;
    }
}
