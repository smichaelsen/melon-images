<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\Service;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ConfigurationLoader
{
    /**
     * These paths will automatically loaded for every installed package
     */
    public const MELON_IMAGES_CONFIGURATION_PATHS = [
        'Configuration/MelonImages.yaml',
        'Configuration/MelonImages.yml',
        'Configuration/MelonImages.json',
        'Configuration/MelonImages.config.php',
    ];

    protected FrontendInterface $cache;
    protected array $configuration;
    protected PackageManager $packageManager;
    protected YamlFileLoader $yamlFileLoader;

    public function __construct(FrontendInterface $cache, PackageManager $packageManager, YamlFileLoader $yamlFileLoader)
    {
        $this->cache = $cache;
        $this->packageManager = $packageManager;
        $this->yamlFileLoader = $yamlFileLoader;
    }

    public function getConfiguration(): array
    {
        if (!isset($this->configuration)) {
            $this->loadConfiguration();
        }
        return $this->configuration;
    }

    protected function loadConfiguration(): void
    {
        $key = 'melonImagesConfiguration';
        if ($this->cache->has($key)) {
            $this->configuration = $this->cache->get($key);
            return;
        }
        $configuration = [];
        foreach ($this->findMelonImagesConfigurationFiles() as $configurationFile) {
            $configuration = array_merge_recursive(
                $configuration,
                $this->readConfigurationFile($configurationFile)
            );
        }
        $configuration = array_filter(
            $configuration,
            fn ($key) => !str_starts_with($key, '__'),
            ARRAY_FILTER_USE_KEY
        );
        $this->configuration = $configuration;
        $this->cache->set($key, $configuration);
    }

    protected function findMelonImagesConfigurationFiles(): array
    {
        $paths = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            foreach (self::MELON_IMAGES_CONFIGURATION_PATHS as $possibleRelativePath) {
                $possiblePath = $package->getPackagePath() . $possibleRelativePath;
                if (is_readable($possiblePath)) {
                    $paths[] = $possiblePath;
                }
            }
        }

        return $paths;
    }

    protected function readConfigurationFile(string $filePath): array
    {
        $pathInfo = pathinfo($filePath);
        switch ($pathInfo['extension']) {
            case 'yml':
            case 'yaml':
                return $this->yamlFileLoader->load($filePath);
            case 'json':
                $absoluteFilePath = GeneralUtility::getFileAbsFileName($filePath);
                return json_decode(file_get_contents($absoluteFilePath), true);
            case 'php':
                $absoluteFilePath = GeneralUtility::getFileAbsFileName($filePath);
                return include $absoluteFilePath;
            default:
                throw new \Exception('Unsupported configuration file type', 1601381478);
        }
    }
}
