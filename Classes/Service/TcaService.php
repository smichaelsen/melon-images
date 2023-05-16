<?php

declare(strict_types=1);
namespace Smichaelsen\MelonImages\Service;

use Smichaelsen\MelonImages\Domain\Dto\Dimensions;
use TYPO3\CMS\Core\Utility\ArrayUtility;

class TcaService
{
    private array $configuration;

    public function __construct(ConfigurationLoader $configurationLoader)
    {
        $this->configuration = $configurationLoader->getConfiguration();
    }

    public function registerCropVariantsTca(array $tca): array
    {
        if (empty($this->configuration)) {
            return $tca;
        }
        foreach ($this->configuration['croppingConfiguration'] as $tableName => $tableConfiguration) {
            if (empty($tca[$tableName])) {
                continue;
            }
            foreach ($tableConfiguration as $type => $fields) {
                foreach ($fields as $fieldName => $fieldConfig) {
                    $variantIdPrefixParts = [$tableName, $type, $fieldName];
                    $tca[$tableName] = self::writeFieldConfigToTCA($tca[$tableName], (string)$type, $fieldName, $fieldConfig, $variantIdPrefixParts);
                }
            }
        }
        return $tca;
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
                if (!is_array($subFields)) {
                    continue;
                }
                foreach ($subFields as $subFieldName => $subFieldConfig) {
                    if (!is_array($subFieldConfig)) {
                        continue;
                    }
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
            $identifier = $sizeConfig['ratio'] ?? $sizeIdentifier;
            if (!isset($sizeConfig['allowedRatios']) && (isset($sizeConfig['width']) || isset($sizeConfig['height']))) {
                $ratioIdentifier = $sizeConfig['title'] ?? $sizeConfig['ratio'] ?? ($sizeConfig['width'] . ' x ' . $sizeConfig['height']);
                $nestedConfig = $sizeConfig;
                $sizeConfig = [];
                if (isset($nestedConfig['focusArea'])) {
                    $sizeConfig['focusArea'] = $nestedConfig['focusArea'];
                    unset($nestedConfig['focusArea']);
                }
                if (isset($nestedConfig['coverArea'])) {
                    $sizeConfig['coverArea'] = $nestedConfig['coverArea'];
                    unset($nestedConfig['coverArea']);
                }
                $sizeConfig['allowedRatios'][$ratioIdentifier] = $nestedConfig;
            }
            foreach ($sizeConfig['allowedRatios'] as $allowedRatioKey => $allowedRatioConfig) {
                $dimensions = new Dimensions($allowedRatioConfig['width'] ?? null, $allowedRatioConfig['height'] ?? null, $allowedRatioConfig['ratio'] ?? null);
                $sizeConfig['allowedRatios'][$allowedRatioKey]['width'] = $dimensions->getWidth();
                $sizeConfig['allowedRatios'][$allowedRatioKey]['height'] = $dimensions->getHeight();
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
            $variantTitle = ($variantConfig['title'] ?? '') ?: ucfirst($variantIdentifier);
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

                if (isset($aspectRatioConfig['allowedRatios']) && count($aspectRatioConfig['allowedRatios']) > 0) {
                    foreach ($aspectRatioConfig['allowedRatios'] as $dimensionKey => $dimensionConfig) {
                        if (isset($dimensionConfig['height'], $dimensionConfig['width'])) {
                            $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'][$dimensionKey] = [
                                'title' => $dimensionConfig['title'] ?? $dimensionKey,
                                'value' => $dimensionConfig['width'] / $dimensionConfig['height'],
                            ];
                        } else {
                            $cropVariantsTca[$cropVariantKey]['allowedAspectRatios']['NaN'] = [
                                'title' => $dimensionConfig['title'] ?? 'LLL:EXT:core/Resources/Private/Language/locallang_wizards.xlf:imwizard.ratio.free',
                                'value' => .0,
                            ];
                        }
                    }
                }
                if (count($cropVariantsTca[$cropVariantKey]['allowedAspectRatios']) === 0) {
                    $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'] = [
                        'NaN' => [
                            'title' => 'LLL:EXT:core/Resources/Private/Language/locallang_wizards.xlf:imwizard.ratio.free',
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
