<?php
namespace TalanHdf\SemanticSuggestion\Hooks;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Cache\CacheManager;

class DataHandlerHook
{
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->clearSemanticSuggestionCache();
    }

    public function processCmdmap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->clearSemanticSuggestionCache();
    }

    private function clearSemanticSuggestionCache(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->flushCachesByTag('tx_semanticsuggestion');
    }
}