<?php
declare(strict_types=1);
namespace Smichaelsen\MelonImages;

use TYPO3\CMS\Core\TypoScript\TypoScriptService;
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
                    $cropVariantsTca = self::createCropVariantsTcaForField($fieldConfig);
                    if ($type === '_all') {
                        $GLOBALS['TCA'][$tableName]['columns'][$fieldName]['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'] = $cropVariantsTca;
                    } else {
                        $GLOBALS['TCA'][$tableName]['types'][$type]['columnsOverrides'][$fieldName]['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'] = $cropVariantsTca;
                    }
                }
            }
        }
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

    protected static function createCropVariantsTcaForField(array $fieldConfig)
    {
        $cropVariantsTca = [];
        foreach ($fieldConfig['variants'] as $variantIdentifier => $variantConfig) {
            $variantTitle = $variantConfig['title'] ?: ucfirst($variantIdentifier);
            foreach ($variantConfig['sizes'] as $sizeIdentifier => $sizeConfig) {
                if (count($variantConfig['sizes']) === 1) {
                    $cropVariantTitle = $variantTitle;
                } else {
                    $cropVariantTitle = $sizeConfig['title'] ?: $variantTitle . ' ' . ucfirst($sizeIdentifier);
                }
                $cropVariantKey = $variantIdentifier . '__' . $sizeIdentifier;
                $cropVariantsTca[$cropVariantKey] = [
                    'title' => $cropVariantTitle,
                    'allowedAspectRatios' => [
                        $sizeConfig['aspectRatio']['x'] . ' x ' . $sizeConfig['aspectRatio']['y'] => [
                            'title' => $sizeConfig['aspectRatio']['x'] . ' x ' . $sizeConfig['aspectRatio']['y'],
                            'value' => $sizeConfig['aspectRatio']['x'] / $sizeConfig['aspectRatio']['y'],
                        ],
                    ],
                ];
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
}
