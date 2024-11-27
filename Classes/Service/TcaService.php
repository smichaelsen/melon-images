<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\Service;

use Smichaelsen\MelonImages\Service\Tca\SizesToAspectRatiosConverter;
use TYPO3\CMS\Core\Utility\ArrayUtility;

class TcaService
{
    public function __construct(
        private readonly ConfigurationLoader $configurationLoader,
        private readonly SizesToAspectRatiosConverter $sizesToAspectRatiosConverter,
    ) {}

    public function registerCropVariantsTca(array $tca): array
    {
        $configuration = $this->configurationLoader->getConfiguration();
        if (empty($configuration)) {
            return $tca;
        }
        foreach ($configuration['croppingConfiguration'] as $tableName => $tableConfiguration) {
            if (empty($tca[$tableName])) {
                continue;
            }
            foreach ($tableConfiguration as $type => $fields) {
                foreach ($fields as $fieldName => $fieldConfig) {
                    $variantIdPrefixParts = [$tableName, $type, $fieldName];
                    $tca[$tableName] = $this->writeFieldConfigToTCA($tca[$tableName], (string)$type, $fieldName, $fieldConfig, $variantIdPrefixParts);
                }
            }
        }
        return $tca;
    }

    protected function writeFieldConfigToTCA(array $tableTca, string $type, string $fieldName, array $fieldConfig, array $variantIdPrefixParts): array
    {
        $fieldPath = self::getFieldTcaPath($fieldName, $type);
        $childTcaPath = $fieldPath . '/config/overrideChildTca';
        if (isset($fieldConfig['variants'])) {
            $cropVariantsPath = $childTcaPath . '/columns/crop/config/cropVariants';
            $tableTca = ArrayUtility::setValueByPath(
                $tableTca,
                $cropVariantsPath,
                $this->createCropVariantsTcaForField($fieldConfig, $variantIdPrefixParts)
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

    protected function createCropVariantsTcaForField(array $fieldConfig, array $variantIdPrefixParts): array
    {
        $cropVariantsTca = [];
        if (!isset($fieldConfig['variants'])) {
            return [];
        }
        foreach ($fieldConfig['variants'] as $variantIdentifier => $variantConfig) {
            $variantTitle = ($variantConfig['title'] ?? '') ?: ucfirst((string)$variantIdentifier);
            $aspectRatioConfigs = $this->sizesToAspectRatiosConverter->getAspectRatiosFromSizes($variantConfig['sizes']);
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
                            $configKey = $dimensionKey;
                            $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'][$dimensionKey] = [
                                'title' => $dimensionConfig['title'] ?? $dimensionKey,
                                'value' => $dimensionConfig['width'] / $dimensionConfig['height'],
                            ];
                        } else {
                            $configKey = 'NaN';
                            $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'][$configKey] = [
                                'title' => $dimensionConfig['title'] ?? 'LLL:EXT:core/Resources/Private/Language/locallang_wizards.xlf:imwizard.ratio.free',
                                'value' => .0,
                            ];
                        }
                        if ($dimensionConfig['coverAreas'] ?? false) {
                            $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'][$configKey]['coverAreas'] = $dimensionConfig['coverAreas'];
                        }
                        if ($dimensionConfig['focusAreas'] ?? false) {
                            $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'][$configKey]['focusAreas'] = $dimensionConfig['focusAreas'];
                        }
                    }
                }
                if ($cropVariantsTca[$cropVariantKey]['allowedAspectRatios'] === []) {
                    $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'] = [
                        'NaN' => [
                            'title' => 'LLL:EXT:core/Resources/Private/Language/locallang_wizards.xlf:imwizard.ratio.free',
                            'value' => .0,
                        ],
                    ];
                }
            }
        }
        return $cropVariantsTca;
    }
}
