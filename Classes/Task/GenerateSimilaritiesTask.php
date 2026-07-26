<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Task;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class GenerateSimilaritiesTask extends AbstractTask
{
    /**
     * Starting page ID for analysis
     * @var int
     */
    public $startPageId = 1;
    
    /**
     * List of pages to exclude (format: "42,56,78")
     * @var string
     */
    public $excludePages = '';
    
    /**
     * Quality level for suggestions (0.0-1.0)
     * This replaces the old minimumSimilarity/proximityThreshold split
     * Storage threshold = qualityLevel - 0.1 (permissive storage)
     * Display threshold = qualityLevel (quality display)
     * @var float
     */
    public $qualityLevel = 0.3;

    /**
     * Legacy support: Minimum similarity threshold to save in database
     * @deprecated Will be removed in v4.0 - use qualityLevel instead
     * @var float
     */
    public $minimumSimilarity = 0.1;
    
    /**
     * Determines if exclusion is recursive or not
     * @var bool
     */
    public $recursiveExclusion = true;

    /**
     * Language ID to process (-1 for all languages)
     * @var int
     */
    public $languageId = -1;

    protected ?LoggerInterface $logger = null;
    protected ?PageAnalysisService $pageAnalysisService = null;
    protected ?ConnectionPool $connectionPool = null;
    protected ?CacheManager $cacheManager = null;
    protected ?ConfigurationManagerInterface $configurationManager = null;
    protected ?PageRepository $pageRepository = null;
    
    public function __construct()
    {
        parent::__construct();
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);

        // Ensure backward compatibility and migration logic
        $this->initializeQualityLevel();
    }

    /**
     * Initialize quality level from legacy parameters if needed
     */
    public function initializeQualityLevel(): void
    {
        // If qualityLevel is at default but minimumSimilarity was customized
        if ($this->qualityLevel === 0.3 && $this->minimumSimilarity !== 0.1) {
            // Migrate from legacy minimumSimilarity (now direct mapping)
            $this->qualityLevel = max(0.1, $this->minimumSimilarity);
            $this->logger->info('Migrated from legacy minimumSimilarity', [
                'legacy' => $this->minimumSimilarity,
                'qualityLevel' => $this->qualityLevel
            ]);
        }

        // Storage threshold equals quality level (no -0.1 offset)
        $this->minimumSimilarity = max(0.05, $this->qualityLevel);
    }

    /**
     * Initialize necessary dependencies
     */
    protected function initializeDependencies(): void
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $this->configurationManager = GeneralUtility::makeInstance(ConfigurationManagerInterface::class);
        $this->pageAnalysisService = GeneralUtility::makeInstance(PageAnalysisService::class);
        $this->pageRepository = GeneralUtility::makeInstance(PageRepository::class);
    }

    /**
     * Execute the scheduled task
     */
    public function execute(): bool
    {
        try {
            $this->initializeDependencies();
            $this->logger->info('Starting similarity generation task', [
                'startPageId' => $this->startPageId,
                'qualityLevel' => $this->qualityLevel,
                'storageThreshold' => $this->minimumSimilarity,
                'recursiveExclusion' => $this->recursiveExclusion,
                'languageId' => $this->languageId
            ]);

            // Convert exclude pages list to array
            $excludePages = !empty($this->excludePages)
                ? GeneralUtility::intExplode(',', $this->excludePages, true)
                : [];

            // Analysis for each language
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $site = $siteFinder->getSiteByPageId($this->startPageId);

            // The real site root, shared by every analysis of this site. Not the same
            // thing as startPageId, which may point at any subtree (see #23).
            $rootPageId = $site->getRootPageId();

            $languagesToProcess = [];
            if ($this->languageId >= 0) {
                // Process only specified language
                try {
                    $specificLanguage = $site->getLanguageById($this->languageId);
                    $languagesToProcess = [$specificLanguage];
                } catch (\Exception $e) {
                    $this->logger->error('Specified language not found', [
                        'languageId' => $this->languageId,
                        'exception' => $e->getMessage()
                    ]);
                    return false;
                }
            } else {
                // Process all languages
                $languagesToProcess = $site->getAllLanguages();
            }

            foreach ($languagesToProcess as $language) {
                $languageId = $language->getLanguageId();

                $this->logger->info('Processing language', [
                    'startPageId' => $this->startPageId,
                    'language' => $languageId
                ]);

                // Retrieve pages for this language
                $pages = $this->getPages($this->startPageId, 999, $languageId, $excludePages);

                if (empty($pages)) {
                    $this->logger->warning('No pages found for language', [
                        'startPageId' => $this->startPageId,
                        'languageId' => $languageId
                    ]);
                    continue; // Continue to next language
                }

                // Analyze similarities
                $analysisData = $this->pageAnalysisService->analyzePages($pages, $languageId);

                // Save results
                $this->saveResults($analysisData, $rootPageId, $this->startPageId, $languageId, $this->minimumSimilarity);
            }

            $this->logger->info('Similarity generation task completed successfully');
            return true;

        } catch (\Exception $e) {
            $this->logger->error('Error during similarity generation task', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Recursively retrieve pages from parent ID
     */
    protected function getPages(int $parentPageId, int $depth, int $languageId, array $excludePages = []): array
    {
        $allPages = [];
        
        try {
            // Retrieve pages directly under parent
            $pages = $this->pageRepository->getMenu(
                $parentPageId,
                '*',
                'sorting',
                'AND hidden=0 AND deleted=0',
                false,
                false, // disableGroupAccessCheck doit être un booléen
                $languageId
            );
            
            foreach ($pages as $page) {
                // Check if page is excluded
                $isExcluded = in_array($page['uid'], $excludePages);
                
                if ($isExcluded) {
                    // If exclusion is not recursive, continue anyway 
                    // to analyze sub-pages WITHOUT excluding them
                    if (!$this->recursiveExclusion && $depth > 1) {
                        // IMPORTANT: Don't pass $excludePages for sub-pages
                        // because we only want to exclude current page, not its children
                        $subPages = $this->getPages($page['uid'], $depth - 1, $languageId, []);
                        $allPages = array_merge($allPages, $subPages);
                    }
                    // In all cases, ignore current page
                    continue;
                }
                
                // Add page if not excluded
                $allPages[$page['uid']] = $page;
                $allPages[$page['uid']]['sys_language_uid'] = $languageId;
                
                // Recurse if depth allows
                if ($depth > 1) {
                    // For non-excluded pages, continue with complete exclusion list
                    $subPages = $this->getPages($page['uid'], $depth - 1, $languageId, $excludePages);
                    $allPages = array_merge($allPages, $subPages);
                }
            }
            
            return $allPages;
            
        } catch (\Exception $e) {
            $this->logger->error('Error retrieving pages', [
                'parentId' => $parentPageId,
                'languageId' => $languageId,
                'exception' => $e->getMessage()
            ]);
            return $allPages;
        }
    }


    /**
     * Save analysis results to database
     *
     * @param int $rootPageId  Real site root page UID, shared by all analyses of this site
     * @param int $scopePageId Page UID this task started from, identifying this task's scope
     */
    protected function saveResults(array $analysisData, int $rootPageId, int $scopePageId, int $languageId, float $proximityThreshold): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_semanticsuggestion_similarities');

        try {
            // Begin transaction
            $connection->beginTransaction();

            // Delete the previous run of THIS task only: scoping on root_page_id alone
            // would let a task erase the rows of another task covering a different
            // subtree of the same site.
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');

            // TYPO3 v12 and v13 compatible version
            $queryBuilder
                ->delete('tx_semanticsuggestion_similarities')
                ->where(
                    $queryBuilder->expr()->eq('root_page_id', $queryBuilder->createNamedParameter($rootPageId)),
                    $queryBuilder->expr()->eq('scope_page_id', $queryBuilder->createNamedParameter($scopePageId)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId))
                )
                ->executeStatement();
            
            // Prepare bulk insertions
            $bulkInserts = [];
            $now = time();
            
            $this->logger->info('Using threshold for filtering', ['threshold' => $proximityThreshold]);
            
        foreach ($analysisData['results'] as $pageId => $pageData) {
            // Vérifier que 'similarities' existe et est un tableau
            if (!isset($pageData['similarities']) || !is_array($pageData['similarities'])) {
                $this->logger->warning('No similarities found for page', ['pageId' => $pageId]);
                continue;
            }
            
            foreach ($pageData['similarities'] as $similarPageId => $similarity) {
                    // Only store similarities above threshold
                    if ($similarity['score'] >= $proximityThreshold) {
                        $bulkInserts[] = [
                            'page_id' => $pageId,
                            'similar_page_id' => $similarPageId,
                            'similarity_score' => $similarity['score'],
                            'root_page_id' => $rootPageId,   // real site root
                            'scope_page_id' => $scopePageId, // this task's startPageId
                            'sys_language_uid' => $languageId,
                            'crdate' => $now,
                            'tstamp' => $now
                        ];
                    }
                    
                    // Insert in batches to optimize performance
                    if (count($bulkInserts) >= 100) {
                        $this->bulkInsert($bulkInserts);
                        $bulkInserts = [];
                    }
                }
            }
            
            // Insert remaining records
            if (!empty($bulkInserts)) {
                $this->bulkInsert($bulkInserts);
            }
            
            // Commit transaction
            $connection->commit();
            
            $this->logger->info('Similarities saved to database', [
                'rootPageId' => $rootPageId,
                'scopePageId' => $scopePageId,
                'languageId' => $languageId,
                'similaritiesCount' => count($bulkInserts)
            ]);

            // Clear the analysis cache of this site. PageAnalysisService tags its
            // entries with site_<rootPageId>, so this now actually matches (see #20).
            $this->cacheManager->getCache('semantic_suggestion')->flushByTag('site_' . $rootPageId);

        } catch (\Exception $e) {
            // Rollback transaction on error
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->logger->error('Failed to save similarities', [
                'rootPageId' => $rootPageId,
                'scopePageId' => $scopePageId,
                'languageId' => $languageId,
                'exception' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Perform bulk insert into similarities table
     */
    protected function bulkInsert(array $records): void
    {
        if (empty($records)) {
            return;
        }
        
        $connection = $this->connectionPool->getConnectionForTable('tx_semanticsuggestion_similarities');
        
        // Use bulkInsert for better performance
        $connection->bulkInsert(
            'tx_semanticsuggestion_similarities',
            $records,
            ['page_id', 'similar_page_id', 'similarity_score', 'root_page_id', 'scope_page_id', 'sys_language_uid', 'crdate', 'tstamp']
        );
    }

    /**
     * Returns additional information to display in the scheduler task list
     */
    public function getAdditionalInformation(): string
    {
        $info = [];

        // Add start page ID
        $startPageLabel = LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.info.start_page', 'semantic_suggestion') ?? 'Start page';
        $info[] = '📄 ' . $startPageLabel . ': ' . $this->startPageId;

        // Add excluded pages if defined
        $excludedPagesLabel = LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.info.excluded_pages', 'semantic_suggestion') ?? 'Excluded pages';
        if (!empty($this->excludePages)) {
            $excludeList = GeneralUtility::trimExplode(',', $this->excludePages, true);
            $info[] = '🚫 ' . $excludedPagesLabel . ': ' . implode(', ', $excludeList) . ' (' . count($excludeList) . ')';
        } else {
            $noneLabel = LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.info.none', 'semantic_suggestion') ?? 'none';
            $info[] = '🚫 ' . $excludedPagesLabel . ': ' . $noneLabel;
        }

        // Add recursive exclusion
        $exclusionLabel = LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.info.exclusion', 'semantic_suggestion') ?? 'Exclusion';
        $recursiveIcon = $this->recursiveExclusion ? '🔄' : '📄';
        if ($this->recursiveExclusion) {
            $recursiveText = LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.info.recursive', 'semantic_suggestion') ?? 'Recursive';
        } else {
            $recursiveText = LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.info.page_only', 'semantic_suggestion') ?? 'Page only';
        }
        $info[] = $recursiveIcon . ' ' . $exclusionLabel . ': ' . $recursiveText;

        // Add quality level (unified configuration)
        $qualityLabel = LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.info.quality_level', 'semantic_suggestion') ?? 'Quality Level';
        $info[] = '🎯 ' . $qualityLabel . ': ' . number_format($this->qualityLevel, 2);

        // Storage threshold (computed)
        $storageLabel = LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.info.storage_threshold', 'semantic_suggestion') ?? 'Storage Threshold';
        $info[] = '💾 ' . $storageLabel . ': ' . number_format($this->minimumSimilarity, 2);

        // Add language limitation
        $languageLabel = LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.info.language', 'semantic_suggestion') ?? 'Language';
        if ($this->languageId >= 0) {
            $info[] = '🌍 ' . $languageLabel . ': ' . $this->languageId;
        } else {
            $allLabel = LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.info.all_languages', 'semantic_suggestion') ?? 'All languages';
            $info[] = '🌍 ' . $languageLabel . ': ' . $allLabel;
        }

        return implode(' | ', $info);
    }
}