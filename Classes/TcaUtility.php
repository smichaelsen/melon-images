<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages;

use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\BackendConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\FrontendConfigurationManager;

class TcaUtility
{
    public static function registerCropVariantsTcaFromTypoScript()
    {
        $packageTypoScriptSettings = self::loadPackageTypoScriptSettings();
        if (empty($packageTypoScriptSettings)) {
            return;
        }
        foreach ($packageTypoScriptSettings['croppingConfiguration'] as $tableName => $tableConfiguration) {
            foreach ($tableConfiguration as $type => $fields) {
                foreach ($fields as $fieldName => $fieldConfig) {
                    $variantIdPrefixParts = [$tableName, $type, $fieldName];
                    $GLOBALS['TCA'][$tableName] = self::writeFieldConfigToTCA($GLOBALS['TCA'][$tableName], $type, $fieldName, $fieldConfig, $variantIdPrefixParts);
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

    protected static function createCropVariantsTcaForField(array $fieldConfig, array $variantIdPrefixParts): array
    {
        $cropVariantsTca = [];
        if (!isset($fieldConfig['variants'])) {
            return [];
        }
        foreach ($fieldConfig['variants'] as $variantIdentifier => $variantConfig) {
            $variantTitle = $variantConfig['title'] ?: ucfirst($variantIdentifier);
            foreach ($variantConfig['sizes'] as $sizeIdentifier => $sizeConfig) {
                if (isset($sizeConfig['title'])) {
                    $cropVariantTitle = $sizeConfig['title'];
                } elseif (count($variantConfig['sizes']) === 1) {
                    $cropVariantTitle = $variantTitle;
                } else {
                    $cropVariantTitle = $variantTitle . ' ' . ucfirst($sizeIdentifier);
                }
                $cropVariantKey = implode('__', $variantIdPrefixParts) . '__' . $variantIdentifier . '__' . $sizeIdentifier;
                $cropVariantsTca[$cropVariantKey] = [
                    'title' => $cropVariantTitle,
                ];
                $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'] = [];
                if (isset($sizeConfig['width'], $sizeConfig['height'])) {
                    $cropVariantsTca[$cropVariantKey]['allowedAspectRatios'][$sizeConfig['width'] . ' x ' . $sizeConfig['height']] = [
                        'title' => $sizeConfig['width'] . ' x ' . $sizeConfig['height'],
                        'value' => $sizeConfig['width'] / $sizeConfig['height'],
                    ];
                }
                if (isset($sizeConfig['allowedRatios']) && count($sizeConfig['allowedRatios']) > 0) {
                    foreach ($sizeConfig['allowedRatios'] as $dimensionKey => $dimensionConfig) {
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
                if (!empty($sizeConfig['coverAreas'])) {
                    $cropVariantsTca[$cropVariantKey]['coverAreas'] = $sizeConfig['coverAreas'];
                }
                if (!empty($sizeConfig['focusArea'])) {
                    $cropVariantsTca[$cropVariantKey]['focusArea'] = $sizeConfig['focusArea'];
                }
            }
        }
        return $cropVariantsTca;
    }

    protected static function loadPackageTypoScriptSettings(): array
    {
        if (TYPO3_MODE === 'BE') {
            $configurationManager = GeneralUtility::makeInstance(BackendConfigurationManager::class);
            $typoScript = $configurationManager->getTypoScriptSetup();
        } elseif (TYPO3_MODE === 'FE') {
            $configurationManager = GeneralUtility::makeInstance(FrontendConfigurationManager::class);
            $typoScript = $configurationManager->getTypoScriptSetup();
        }
        if (empty($typoScript['package.']['Smichaelsen\\MelonImages.'])) {
            return [];
        }
        return GeneralUtility::makeInstance(TypoScriptService::class)->convertTypoScriptArrayToPlainArray(
            $typoScript['package.']['Smichaelsen\\MelonImages.']
        );
    }
}
