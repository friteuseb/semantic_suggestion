<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Hooks;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Invalidates the semantic suggestion analysis cache when content changes.
 *
 * Registered on TYPO3 12, 13 and 14 alike: the processDatamapClass /
 * processCmdmapClass hooks are still consumed by DataHandler in v14, and the
 * PSR-14 events that were meant to replace them never existed (see #24).
 */
class DataHandlerHook
{
    /**
     * Tables whose changes can affect a similarity analysis. Anything else
     * (fe_users, sys_log, custom records...) is irrelevant here.
     */
    private const RELEVANT_TABLES = ['pages', 'tt_content'];

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->clearSemanticSuggestionCache($dataHandler->datamap ?? []);
    }

    public function processCmdmap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->clearSemanticSuggestionCache($dataHandler->cmdmap ?? []);
    }

    /**
     * Flushes only the sites actually touched by the operation.
     *
     * Flushing the whole 'tx_semanticsuggestion' tag would invalidate every site of
     * the instance on any edit, and each analysis is expensive to rebuild (O(n²) over
     * the page set, plus TF-IDF vectorisation).
     *
     * @param array $map DataHandler datamap or cmdmap, keyed by table then record UID
     */
    private function clearSemanticSuggestionCache(array $map): void
    {
        $rootPageIds = $this->resolveAffectedRootPageIds($map);

        if ($rootPageIds === []) {
            return;
        }

        try {
            // Our own cache only: 'site_<id>' is a generic tag and flushing it through
            // CacheManager would reach every cache that happens to use the same tag.
            $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('semantic_suggestion');
        } catch (NoSuchCacheException $e) {
            return;
        }

        foreach ($rootPageIds as $rootPageId) {
            $cache->flushByTag('site_' . $rootPageId);
        }
    }

    /**
     * @return int[] Site root page UIDs affected by this operation
     */
    private function resolveAffectedRootPageIds(array $map): array
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $rootPageIds = [];

        foreach (self::RELEVANT_TABLES as $table) {
            foreach (array_keys($map[$table] ?? []) as $identifier) {
                // New records use "NEW1234abcd" placeholders; their page is not
                // resolvable here, and the record is picked up by the next analysis run.
                if (!MathUtility::canBeInterpretedAsInteger($identifier)) {
                    continue;
                }

                $pageId = $table === 'pages'
                    ? (int)$identifier
                    : $this->resolvePageIdOfContentElement((int)$identifier);

                if ($pageId <= 0) {
                    continue;
                }

                try {
                    $rootPageIds[] = $siteFinder->getSiteByPageId($pageId)->getRootPageId();
                } catch (\Exception $e) {
                    // Page outside any configured site: nothing cached for it either.
                    continue;
                }
            }
        }

        return array_values(array_unique($rootPageIds));
    }

    private function resolvePageIdOfContentElement(int $uid): int
    {
        // $useDeleteClause = false so that a just-deleted element still resolves to
        // its page, which is exactly the case where the cache must be invalidated.
        $record = BackendUtility::getRecord('tt_content', $uid, 'pid', '', false);

        return (int)($record['pid'] ?? 0);
    }
}
