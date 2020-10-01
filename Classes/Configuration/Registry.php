<?php
declare(strict_types = 1);
namespace Smichaelsen\MelonImages\Configuration;

use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Registry implements SingletonInterface
{
    /**
     * @var string[]
     */
    protected $configurationFiles = [];

    public function registerConfigurationFile(string $configurationFilePath): void
    {
        $this->configurationFiles[] = $configurationFilePath;
    }

    public function getParsedConfiguration(): array
    {
        $configuration = [];
        foreach ($this->configurationFiles as $configurationFile) {
            $configuration = array_merge_recursive($configuration, $this->readConfigurationFile($configurationFile));
        }
        return $configuration;
    }

    protected function readConfigurationFile(string $filePath): array
    {
        $pathInfo = pathinfo($filePath);
        switch ($pathInfo['extension']) {
            case 'yml':
            case 'yaml':
                $yamlFileLoader = GeneralUtility::makeInstance(YamlFileLoader::class);
                return $yamlFileLoader->load($filePath);
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
