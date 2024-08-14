<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Cache\CacheManager;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

class PageAnalysisService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $context;
    protected $configurationManager;
    protected $settings;
    protected $cache;
    protected $connectionPool;
    protected $queryBuilder;
    protected $nlpService;

    public function __construct(
        Context $context,
        ConfigurationManagerInterface $configurationManager,
        ?CacheManager $cacheManager = null,
        $dbConnection = null,
        ?LoggerInterface $logger = null,
        ?NlpService $nlpService = null
    ) {
        $this->context = $context;
        $this->configurationManager = $configurationManager;
        $this->nlpService = $nlpService ?? GeneralUtility::makeInstance(NlpService::class);

        if ($logger) {
            $this->setLogger($logger);
        }

        if ($dbConnection instanceof ConnectionPool) {
            $this->connectionPool = $dbConnection;
        } elseif ($dbConnection instanceof QueryBuilder) {
            $this->queryBuilder = $dbConnection;
        } else {
            $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        }

        $this->settings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'semanticsuggestion_suggestions'
        );

        if (!isset($this->settings['recencyWeight'])) {
            $this->settings['recencyWeight'] = 0.2;
        }
        $this->settings['recencyWeight'] = max(0, min(1, (float)$this->settings['recencyWeight']));

        if ($cacheManager) {
            try {
                $this->cache = $cacheManager->getCache('semantic_suggestion');
            } catch (\TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException $e) {
                $this->cache = $cacheManager->getCache('null');
            }
        } else {
            $this->cache = $this->createFallbackCache();
        }

        if (!isset($this->settings['analyzedFields']) || !is_array($this->settings['analyzedFields'])) {
            $this->settings['analyzedFields'] = [
                'title' => 1.5,
                'description' => 1.0,
                'keywords' => 2.0,
                'abstract' => 1.2,
                'content' => 1.0
            ];
        }
    }

    public function setSettings(array $settings): void
    {
        $this->settings = array_merge($this->settings, $settings);
        if (!isset($this->settings['recencyWeight'])) {
            $this->settings['recencyWeight'] = 0.2;
        }
        $this->settings['recencyWeight'] = max(0, min(1, (float)$this->settings['recencyWeight']));
    }

    public function analyzePages(int $parentPageId = null, int $depth = null): array
    {
        $startTime = microtime(true);
    
        $parentPageId = $parentPageId ?? (int)$this->settings['parentPageId'];
        $depth = $depth ?? (int)$this->settings['recursive'];
        $cacheIdentifier = 'semantic_analysis_' . $parentPageId . '_' . $depth;
    
        if ($this->cache->has($cacheIdentifier)) {
            $cachedResult = $this->cache->get($cacheIdentifier);
            $cachedResult['metrics']['fromCache'] = true;
            $cachedResult['metrics']['executionTime'] = microtime(true) - $startTime;
            return $cachedResult;
        }
    
        try {
            $pages = $this->getAllSubpages($parentPageId, $depth);
            $totalPages = count($pages);
            $analysisResults = [];
    
            foreach ($pages as $page) {
                $analysisResults[$page['uid']] = $this->preparePageData($page);
            }
    
            $similarityCalculations = 0;
            foreach ($analysisResults as $pageId => &$pageData) {
                foreach ($analysisResults as $comparisonPageId => $comparisonPageData) {
                    if ($pageId !== $comparisonPageId) {
                        $similarity = $this->calculateSimilarity($pageData, $comparisonPageData);
                        $pageData['similarities'][$comparisonPageId] = [
                            'score' => $similarity['finalSimilarity'],
                            'semanticSimilarity' => $similarity['semanticSimilarity'],
                            'recencyBoost' => $similarity['recencyBoost'],
                            'commonKeywords' => $this->findCommonKeywords($pageData, $comparisonPageData),
                            'relevance' => $this->determineRelevance($similarity['finalSimilarity']),
                            'ageInDays' => round((time() - $comparisonPageData['content_modified_at']) / (24 * 3600), 1),
                        ];
                        
                        $similarityCalculations++;
                    }
                }
            }
    
        } catch (\Exception $e) {
            $this->logger?->error('Error during page analysis', ['exception' => $e->getMessage()]);
            return [];
        }
    
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
    
        $result = [
            'results' => $analysisResults,
            'metrics' => [
                'executionTime' => $executionTime,
                'totalPages' => $totalPages,
                'similarityCalculations' => $similarityCalculations,
                'fromCache' => false,
            ],
        ];
    
        $this->cache->set(
            $cacheIdentifier,
            $result,
            ['tx_semanticsuggestion', 'pages_' . $parentPageId],
            86400
        );
    
        return $result;
    }

    protected function preparePageData(array $page): array
    {
        $preparedData = [];
    
        if (!is_array($this->settings['analyzedFields'])) {
            $this->logger?->warning('analyzedFields is not an array', ['settings' => $this->settings]);
            return $preparedData;
        }
    
        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            if ($field === 'content') {
                try {
                    $preparedData['content'] = [
                        'content' => $this->getPageContent($page['uid']),
                        'weight' => (float)$weight
                    ];
                } catch (\Exception $e) {
                    $this->logger?->error('Error fetching page content', ['pageId' => $page['uid'], 'exception' => $e->getMessage()]);
                    $preparedData['content'] = [
                        'content' => '',
                        'weight' => (float)$weight
                    ];
                }
            } elseif (isset($page[$field])) {
                $preparedData[$field] = [
                    'content' => $page[$field],
                    'weight' => (float)$weight
                ];
            } else {
                $preparedData[$field] = [
                    'content' => '',
                    'weight' => (float)$weight
                ];
            }
        }
    
        $preparedData['content_modified_at'] = $page['content_modified_at'] ?? $page['crdate'] ?? time();
    
        return $preparedData;
    }

    private function getAllSubpages(int $parentId, int $depth = 0): array
    {
        $allPages = [];
        $queue = [[$parentId, 0]];

        while (!empty($queue)) {
            [$currentId, $currentDepth] = array_shift($queue);

            if ($depth !== -1 && $currentDepth > $depth) {
                continue;
            }

            $pages = $this->getSubpages($currentId);
            $allPages = array_merge($allPages, $pages);

            foreach ($pages as $page) {
                $queue[] = [$page['uid'], $currentDepth + 1];
            }
        }

        return $allPages;
    }

    protected function getSubpages(int $parentId): array
    {
        if ($this->logger) {
            $this->logger->info('Fetching subpages', ['parentId' => $parentId]);
        }

        try {
            $queryBuilder = $this->getQueryBuilder();
            $languageUid = $this->getCurrentLanguageUid();

            if ($this->logger) {
                $this->logger->info('Current language UID: ' . $languageUid);
            }

            $fieldsToSelect = ['uid', 'title', 'description', 'keywords', 'abstract', 'crdate'];

            $tableColumns = $queryBuilder->getConnection()->getSchemaManager()->listTableColumns('pages');
            $existingColumns = array_keys($tableColumns);
            $fieldsToSelect = array_intersect($fieldsToSelect, $existingColumns);

            if ($this->logger) {
                $this->logger->debug('Fields to select', ['fields' => $fieldsToSelect]);
            }

            $result = $queryBuilder
                ->select(...$fieldsToSelect)
                ->addSelectLiteral(
                    '(SELECT MAX(tstamp) FROM tt_content WHERE tt_content.pid = pages.uid AND tt_content.deleted = 0 AND tt_content.hidden = 0)'
                )
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentId, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([$languageUid, -1], Connection::PARAM_INT_ARRAY))
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($result as &$page) {
                $page['content_modified_at'] = $page['MAX(tstamp)'] ?? $page['crdate'] ?? time();
                unset($page['MAX(tstamp)']);
            }

            if ($this->logger) {
                $this->logger->info('Subpages fetched successfully', ['count' => count($result)]);
                $this->logger->debug('Fetched subpages', ['subpages' => $result]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger?->error('Error fetching subpages', ['exception' => $e->getMessage()]);
            throw $e;
        }
    }

    protected function getPageContent(int $pageId): string
    {
        try {
            $queryBuilder = $this->getQueryBuilder();
            $languageUid = $this->getCurrentLanguageUid();

            $content = $queryBuilder
                ->select('bodytext')
                ->from('tt_content')
                ->where(
                    $queryBuilder->expr()->eq('tt_content.pid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('tt_content.hidden', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('tt_content.deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->in('tt_content.sys_language_uid', $queryBuilder->createNamedParameter([$languageUid, -1], Connection::PARAM_INT_ARRAY))
                )
                ->executeQuery()
                ->fetchAllAssociative();

            return implode(' ', array_column($content, 'bodytext'));
        } catch (\Exception $e) {
            $this->logger?->error('Error fetching page content', ['pageId' => $pageId, 'exception' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getWeightedWords(array $pageData): array
    {
        $weightedWords = [];

        foreach ($pageData as $field => $data) {
            if (!isset($data['content']) || !is_string($data['content'])) {
                continue;
            }

            $words = str_word_count(strtolower($data['content']), 1);
            $weight = $data['weight'] ?? 1.0;

            foreach ($words as $word) {
                if (!isset($weightedWords[$word])) {
                    $weightedWords[$word] = 0;
                }
                $weightedWords[$word] += $weight;
            }
        }

        return $weightedWords;
    }

    private function calculateSimilarity(array $page1, array $page2): array
    {
        $words1 = $this->getWeightedWords($page1);
        $words2 = $this->getWeightedWords($page2);

        $intersection = array_intersect_key($words1, $words2);
        $union = $words1 + $words2;

        $intersectionSum = array_sum($intersection);
        $unionSum = array_sum($union);

        if ($unionSum === 0) {
            return [
                'semanticSimilarity' => 0.0,
                'recencyBoost' => 0.0,
                'finalSimilarity' => 0.0
            ];
        }

        $semanticSimilarity = min($intersectionSum / $unionSum, 1.0);

        $recencyBoost = $this->calculateRecencyBoost($page1, $page2);

        $recencyWeight = $this->settings['recencyWeight'] ?? 0.2;
        $finalSimilarity = ($semanticSimilarity * (1 - $recencyWeight)) + ($recencyBoost * $recencyWeight);

        $this->logger?->info('Similarity calculation', [
            'page1' => $page1['uid'] ?? 'unknown',
            'page2' => $page2['uid'] ?? 'unknown',
            'semanticSimilarity' => $semanticSimilarity, 
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => $finalSimilarity,
            'fieldScores' => [
                'title' => $this->calculateFieldSimilarity($page1['title'] ?? [], $page2['title'] ?? []),
                'description' => $this->calculateFieldSimilarity($page1['description'] ?? [], $page2['description'] ?? []),
                'keywords' => $this->calculateFieldSimilarity($page1['keywords'] ?? [], $page2['keywords'] ?? []),
                'content' => $this->calculateFieldSimilarity($page1['content'] ?? [], $page2['content'] ?? []),
            ]
        ]);

        return [
            'semanticSimilarity' => $semanticSimilarity,
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => min($finalSimilarity, 1.0)
        ];
    }

    private function calculateRecencyBoost(array $page1, array $page2): float
    {
        $now = time();
        $maxAge = 30 * 24 * 3600; // 30 jours en secondes
        $age1 = min($now - ($page1['content_modified_at'] ?? $now), $maxAge);
        $age2 = min($now - ($page2['content_modified_at'] ?? $now), $maxAge);
        
        // Normaliser les âges entre 0 et 1
        $normalizedAge1 = 1 - ($age1 / $maxAge);
        $normalizedAge2 = 1 - ($age2 / $maxAge);
        
        // Calculer la différence de récence
        return abs($normalizedAge1 - $normalizedAge2);
    }

    private function calculateFieldSimilarity($field1, $field2): float
    {
        if (!isset($field1['content']) || !isset($field2['content'])) {
            return 0.0;
        }

        $words1 = str_word_count(strtolower($field1['content']), 1);
        $words2 = str_word_count(strtolower($field2['content']), 1);
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }

    private function findCommonKeywords(array $page1, array $page2): array
    {
        $keywords1 = isset($page1['keywords']['content']) ? array_map('trim', explode(',', strtolower($page1['keywords']['content']))) : [];
        $keywords2 = isset($page2['keywords']['content']) ? array_map('trim', explode(',', strtolower($page2['keywords']['content']))) : [];

        return array_intersect($keywords1, $keywords2);
    }

    private function determineRelevance($similarity): string
    {
        if (is_array($similarity)) {
            $similarityValue = $similarity['finalSimilarity'] ?? 0;
        } else {
            $similarityValue = (float)$similarity;
        }

        if ($similarityValue > 0.7) {
            return 'High';
        } elseif ($similarityValue > 0.4) {
            return 'Medium';
        } else {
            return 'Low';
        }
    }

    private function getCurrentLanguageUid(): int
    {
        try {
            return (int)$this->context->getAspect('language')->getId();
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to get language from context, defaulting to 0', ['exception' => $e]);
            return 0;
        }
    }

    protected function getQueryBuilder(string $table = 'pages'): QueryBuilder
    {
        if ($this->queryBuilder) {
            return $this->queryBuilder;
        }
        if ($this->connectionPool === null) {
            $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        }
        return $this->connectionPool->getQueryBuilderForTable($table);
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getCacheManager(): ?CacheManager
    {
        return $this->cacheManager;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getConnectionPool(): ?ConnectionPool
    {
        return $this->connectionPool;
    }

    private function createFallbackCache(): FrontendInterface
    {
        return new class implements FrontendInterface {
            private $data = [];
            public function set($entryIdentifier, $data, array $tags = [], $lifetime = null) {
                $this->data[$entryIdentifier] = $data;
            }
            public function get($entryIdentifier) {
                return $this->data[$entryIdentifier] ?? false;
            }
            public function has($entryIdentifier) {
                return isset($this->data[$entryIdentifier]);
            }
            public function remove($entryIdentifier) {
                unset($this->data[$entryIdentifier]);
            }
            public function flush() {
                $this->data = [];
            }
            public function flushByTag($tag) {}
            public function flushByTags(array $tags) {}
            public function collectGarbage() {}
            public function isValidEntryIdentifier($identifier) {
                return is_string($identifier);
            }
            public function isValidTag($tag) {
                return is_string($tag);
            }
            public function getIdentifier() {
                return 'fallback_cache';
            }
            public function getBackend() {
                return null;
            }
            public function getSettings(): array {
                return [];
            }
            public function setSettings(array $settings): void {}
        };
    }
}