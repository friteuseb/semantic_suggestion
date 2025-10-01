<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TalanHdf\SemanticSuggestion\Command\DebugSchedulerCommand;
use TYPO3\CMS\Core\Cache\CacheManager;

class DebugController extends ActionController
{
    protected ModuleTemplateFactory $moduleTemplateFactory;
    protected ConnectionPool $connectionPool;
    protected SiteFinder $siteFinder;
    protected CacheManager $cacheManager;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        ConnectionPool $connectionPool,
        SiteFinder $siteFinder,
        CacheManager $cacheManager
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->connectionPool = $connectionPool;
        $this->siteFinder = $siteFinder;
        $this->cacheManager = $cacheManager;
    }

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        // Get all root pages
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $rootPages = $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('is_siteroot', 1),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('hidden', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Get system info
        $systemInfo = [
            'phpVersion' => phpversion(),
            'memoryLimit' => ini_get('memory_limit'),
            'maxExecutionTime' => ini_get('max_execution_time') === '0' ? 'Unlimited' : ini_get('max_execution_time') . 's',
            'extensions' => $this->checkExtensions(),
        ];

        // Get database stats
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');
        $stats = $queryBuilder
            ->select('sys_language_uid')
            ->addSelectLiteral('COUNT(*) as count')
            ->from('tx_semanticsuggestion_similarities')
            ->groupBy('sys_language_uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $totalSimilarities = array_sum(array_column($stats, 'count'));

        $moduleTemplate->assignMultiple([
            'rootPages' => $rootPages,
            'systemInfo' => $systemInfo,
            'totalSimilarities' => $totalSimilarities,
            'similaritiesByLanguage' => $stats,
        ]);

        return $moduleTemplate->renderResponse('Debug/Index');
    }

    public function executeAction(): ResponseInterface
    {
        $startPageId = (int)($this->request->getParsedBody()['startPageId'] ?? $this->request->getQueryParams()['startPageId'] ?? 1);
        $qualityLevel = (float)($this->request->getParsedBody()['qualityLevel'] ?? $this->request->getQueryParams()['qualityLevel'] ?? 0.3);
        $languageId = (int)($this->request->getParsedBody()['languageId'] ?? $this->request->getQueryParams()['languageId'] ?? -1);
        $testMode = (bool)($this->request->getParsedBody()['testMode'] ?? $this->request->getQueryParams()['testMode'] ?? false);
        $dryRun = (bool)($this->request->getParsedBody()['dryRun'] ?? $this->request->getQueryParams()['dryRun'] ?? false);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        try {
            $results = $this->executeAnalysis($startPageId, $qualityLevel, $languageId, $testMode, $dryRun);

            if ($results['success']) {
                $this->addFlashMessage(
                    'Analysis completed successfully!',
                    'Success',
                    ContextualFeedbackSeverity::OK
                );
            } else {
                $this->addFlashMessage(
                    'Analysis completed with errors: ' . ($results['error'] ?? 'Unknown error'),
                    'Warning',
                    ContextualFeedbackSeverity::WARNING
                );
            }

            $moduleTemplate->assignMultiple([
                'results' => $results,
                'executionTime' => $results['executionTime'] ?? 0,
                'pagesProcessed' => $results['pagesProcessed'] ?? 0,
                'comparisons' => $results['comparisons'] ?? 0,
                'similaritiesSaved' => $results['similaritiesSaved'] ?? 0,
                'logs' => $results['logs'] ?? [],
            ]);

        } catch (\Exception $e) {
            $this->addFlashMessage(
                'Error: ' . $e->getMessage(),
                'Error',
                ContextualFeedbackSeverity::ERROR
            );

            $moduleTemplate->assignMultiple([
                'error' => $e->getMessage(),
            ]);
        }

        return $moduleTemplate->renderResponse('Debug/Results');
    }

    protected function executeAnalysis(int $startPageId, float $qualityLevel, int $languageId, bool $testMode, bool $dryRun): array
    {
        $startTime = microtime(true);
        $logs = [];
        $totalPagesProcessed = 0;
        $totalComparisons = 0;
        $totalSimilaritiesSaved = 0;

        try {
            // Get site and languages
            $site = $this->siteFinder->getSiteByPageId($startPageId);

            $languagesToProcess = [];
            if ($languageId >= 0) {
                $specificLanguage = $site->getLanguageById($languageId);
                $languagesToProcess = [$specificLanguage];
            } else {
                $languagesToProcess = $site->getAllLanguages();
            }

            $logs[] = ['level' => 'info', 'message' => 'Found ' . count($languagesToProcess) . ' language(s) to process'];

            foreach ($languagesToProcess as $language) {
                $langId = $language->getLanguageId();
                $langCode = substr($language->getHreflang(), 0, 2);

                $logs[] = ['level' => 'info', 'message' => "Processing language: $langCode (ID: $langId)"];

                // Get pages
                $pages = $this->getPages($startPageId, $langId);
                $pageCount = count($pages);

                if ($testMode && $pageCount > 10) {
                    $pages = array_slice($pages, 0, 10, true);
                    $pageCount = 10;
                    $logs[] = ['level' => 'warning', 'message' => 'TEST MODE: Limited to 10 pages'];
                }

                $logs[] = ['level' => 'info', 'message' => "Found $pageCount pages"];

                if ($pageCount === 0) {
                    $logs[] = ['level' => 'warning', 'message' => 'No pages found for this language'];
                    continue;
                }

                // Initialize PageAnalysisService
                $pageAnalysisService = GeneralUtility::makeInstance(\TalanHdf\SemanticSuggestion\Service\PageAnalysisService::class);

                // Execute analysis
                $analysisData = $pageAnalysisService->analyzePages($pages, $langId);

                $comparisonsCalculated = $analysisData['metrics']['similarityCalculations'] ?? 0;
                $totalComparisons += $comparisonsCalculated;
                $totalPagesProcessed += $pageCount;

                $logs[] = ['level' => 'success', 'message' => "Analysis completed: " . number_format($comparisonsCalculated) . " comparisons"];

                // Save results
                if (!$dryRun) {
                    $similaritiesSaved = $this->saveResults($analysisData, $startPageId, $langId, $qualityLevel);
                    $totalSimilaritiesSaved += $similaritiesSaved;
                    $logs[] = ['level' => 'success', 'message' => "Saved $similaritiesSaved similarities to database"];
                } else {
                    $logs[] = ['level' => 'info', 'message' => 'DRY RUN: Skipping database save'];
                }
            }

            $executionTime = microtime(true) - $startTime;

            return [
                'success' => true,
                'executionTime' => round($executionTime, 2),
                'pagesProcessed' => $totalPagesProcessed,
                'comparisons' => $totalComparisons,
                'similaritiesSaved' => $totalSimilaritiesSaved,
                'logs' => $logs,
            ];

        } catch (\Exception $e) {
            $logs[] = ['level' => 'error', 'message' => 'ERROR: ' . $e->getMessage()];

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'logs' => $logs,
                'executionTime' => microtime(true) - $startTime,
                'pagesProcessed' => $totalPagesProcessed,
                'comparisons' => $totalComparisons,
                'similaritiesSaved' => $totalSimilaritiesSaved,
            ];
        }
    }

    protected function getPages(int $startPageId, int $languageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        $query = $queryBuilder
            ->select('uid', 'pid', 'title', 'description', 'keywords', 'abstract', 'sys_language_uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            );

        $results = $query->executeQuery()->fetchAllAssociative();

        // Index by uid
        $indexed = [];
        foreach ($results as $row) {
            $indexed[$row['uid']] = $row;
        }

        return $indexed;
    }

    protected function saveResults(array $analysisData, int $rootPageId, int $languageId, float $proximityThreshold): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_semanticsuggestion_similarities');
        $savedCount = 0;

        try {
            $connection->beginTransaction();

            // Delete old entries
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');
            $queryBuilder
                ->delete('tx_semanticsuggestion_similarities')
                ->where(
                    $queryBuilder->expr()->eq('root_page_id', $queryBuilder->createNamedParameter($rootPageId)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId))
                )
                ->executeStatement();

            // Insert new entries
            $bulkInserts = [];
            $now = time();

            foreach ($analysisData['results'] as $pageId => $pageData) {
                if (!isset($pageData['similarities']) || !is_array($pageData['similarities'])) {
                    continue;
                }

                foreach ($pageData['similarities'] as $similarPageId => $similarity) {
                    if ($similarity['score'] >= $proximityThreshold) {
                        $bulkInserts[] = [
                            'page_id' => $pageId,
                            'similar_page_id' => $similarPageId,
                            'similarity_score' => $similarity['score'],
                            'root_page_id' => $rootPageId,
                            'sys_language_uid' => $languageId,
                            'crdate' => $now,
                            'tstamp' => $now
                        ];
                    }

                    if (count($bulkInserts) >= 100) {
                        $this->bulkInsert($bulkInserts);
                        $savedCount += count($bulkInserts);
                        $bulkInserts = [];
                    }
                }
            }

            if (!empty($bulkInserts)) {
                $this->bulkInsert($bulkInserts);
                $savedCount += count($bulkInserts);
            }

            $connection->commit();

            // Clear cache
            try {
                $cache = $this->cacheManager->getCache('semantic_suggestion');
                $cache->flushByTag('site_' . $rootPageId);
            } catch (\Exception $e) {
                // Ignore cache errors
            }

            return $savedCount;

        } catch (\Exception $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    protected function bulkInsert(array $records): void
    {
        if (empty($records)) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable('tx_semanticsuggestion_similarities');
        $connection->bulkInsert(
            'tx_semanticsuggestion_similarities',
            $records,
            ['page_id', 'similar_page_id', 'similarity_score', 'root_page_id', 'sys_language_uid', 'crdate', 'tstamp']
        );
    }

    protected function checkExtensions(): array
    {
        $extensions = ['mbstring', 'intl', 'iconv', 'json', 'pdo'];
        $result = [];

        foreach ($extensions as $ext) {
            $result[$ext] = extension_loaded($ext);
        }

        return $result;
    }
}
