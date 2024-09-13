<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class ConfigurationService
{
    protected array $settings;
    protected CacheManager $cacheManager;
    protected ExtensionConfiguration $extensionConfiguration;
    protected FrontendInterface $cache;

    public function __construct(CacheManager $cacheManager, ExtensionConfiguration $extensionConfiguration)
    {
        $this->cacheManager = $cacheManager;
        $this->extensionConfiguration = $extensionConfiguration;
        $this->initializeSettings();
        $this->initializeCache();
    }

    protected function initializeSettings(): void
    {
        // Déplacez ici la logique de initializeSettings de PageAnalysisService
        $this->settings = $this->extensionConfiguration->get('semantic_suggestion');
        // Ajoutez d'autres initialisations si nécessaire
    }

    protected function initializeCache(): void
    {
        // Déplacez ici la logique de initializeCache de PageAnalysisService
        $this->cache = $this->cacheManager->getCache('semantic_suggestion');
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function setSettings(array $settings): void
    {
        $this->settings = array_merge($this->settings, $settings);
        // Ajoutez ici la logique pour sauvegarder les paramètres si nécessaire
    }

    public function getCache(): FrontendInterface
    {
        return $this->cache;
    }
}