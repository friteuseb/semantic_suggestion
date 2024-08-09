<?php
namespace TalanHdf\SemanticSuggestion\Hooks;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Cache\CacheManager;

class DataHandlerHook
{
    /**
     * This method is triggered after all database operations have been processed by the DataHandler.
     * It ensures that the semantic suggestion cache is cleared whenever content is modified.
     * This helps in keeping the suggestions relevant and up-to-date.
     *
     * @param DataHandler $dataHandler The DataHandler instance handling the current operation.
     */
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->clearSemanticSuggestionCache();
    }

    /**
     * This method is triggered after all command map operations (like copying, moving, or deleting records)
     * have been processed by the DataHandler. It ensures that the semantic suggestion cache is cleared
     * to reflect the changes made by these operations.
     *
     * @param DataHandler $dataHandler The DataHandler instance handling the current operation.
     */
    public function processCmdmap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->clearSemanticSuggestionCache();
    }

    /**
     * This private method clears the cache associated with the semantic suggestions.
     * It uses the CacheManager to flush caches tagged with 'tx_semanticsuggestion'.
     * This ensures that the cache is invalidated whenever content changes occur,
     * allowing new suggestions to be generated based on the updated content.
     */
    private function clearSemanticSuggestionCache(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->flushCachesByTag('tx_semanticsuggestion');
    }
}
