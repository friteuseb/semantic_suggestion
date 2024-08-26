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

class ItemAnalysisService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $context;
    protected $configurationManager;
    protected $settings;
    protected $cache;
    protected $connectionPool;
    protected $queryBuilder;

    public function __construct(
        Context $context,
        ConfigurationManagerInterface $configurationManager,
        ?CacheManager $cacheManager = null,
        $dbConnection = null,
        ?LoggerInterface $logger = null
    ) {
        $this->context = $context;
        $this->configurationManager = $configurationManager;

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
            $this->cache = new class implements FrontendInterface {
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
                    return $this->settings;
                }
                public function setSettings(array $settings): void {
                    $this->settings = $settings;
                }
            };
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

    public function analyzeItems(array $items, bool $includeNews = false): array
    {
        $startTime = microtime(true);
    
        if (empty($items)) {
            $this->logger->warning('No items provided for analysis');
            return [
                'results' => [],
                'metrics' => [
                    'executionTime' => 0,
                    'totalItems' => 0,
                    'similarityCalculations' => 0,
                    'fromCache' => false,
                ],
            ];
        }
    
        $firstItem = reset($items);
        $parentId = $firstItem['pid'] ?? 0;
        $depth = $this->calculateDepth($items);
        $cacheIdentifier = 'semantic_analysis_' . $parentId . '_' . $depth . '_' . ($includeNews ? 'news' : 'pages');
    
        if ($this->cache->has($cacheIdentifier)) {
            $cachedResult = $this->cache->get($cacheIdentifier);
            $cachedResult['metrics']['fromCache'] = true;
            $cachedResult['metrics']['executionTime'] = microtime(true) - $startTime;
            return $cachedResult;
        }
    
        try {
            $this->logger->debug('Analyzing items', ['itemCount' => count($items)]);
            $totalItems = count($items);
            $analysisResults = [];
    
            foreach ($items as $item) {
                if (isset($item['uid'])) {
                    $analysisResults[$item['uid']] = $this->prepareItemData($item);
                } else {
                    $this->logger->warning('Item without UID encountered', ['item' => $item]);
                }
            }
    
            $similarityCalculations = 0;
            foreach ($analysisResults as $itemId => &$itemData) {
                foreach ($analysisResults as $comparisonItemId => $comparisonItemData) {
                    if ($itemId !== $comparisonItemId) {
                        $similarity = $this->calculateSimilarity($itemData, $comparisonItemData);
                        $itemData['similarities'][$comparisonItemId] = [
                            'score' => $similarity['finalSimilarity'],
                            'semanticSimilarity' => $similarity['semanticSimilarity'],
                            'recencyBoost' => $similarity['recencyBoost'],
                            'commonKeywords' => $this->findCommonKeywords($itemData, $comparisonItemData),
                            'relevance' => $this->determineRelevance($similarity['finalSimilarity']),
                            'ageInDays' => round((time() - $comparisonItemData['content_modified_at']) / (24 * 3600), 1),
                        ];
                        
                        $similarityCalculations++;
                    }
                }
            }
    
        } catch (\Exception $e) {
            $this->logger->error('Error during item analysis', ['exception' => $e->getMessage()]);
            return [
                'results' => [],
                'metrics' => [
                    'executionTime' => microtime(true) - $startTime,
                    'totalItems' => 0,
                    'similarityCalculations' => 0,
                    'fromCache' => false,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
    
        $result = [
            'results' => $analysisResults,
            'metrics' => [
                'executionTime' => $executionTime,
                'totalItems' => $totalItems,
                'similarityCalculations' => $similarityCalculations,
                'fromCache' => false,
            ],
        ];
    
        $this->cache->set(
            $cacheIdentifier,
            $result,
            ['tx_semanticsuggestion', 'items_' . $parentId],
            86400
        );
    
        return $result;
    }

    protected function prepareItemData(array $item): array
    {
        $preparedData = [
            'uid' => $item['uid'],
            'sys_language_uid' => $item['sys_language_uid'] ?? 0,
            'isTranslation' => isset($item['_PAGES_OVERLAY']),
            'type' => $item['type'] ?? 'page',
        ];
    
        if (!is_array($this->settings['analyzedFields'])) {
            $this->logger?->warning('analyzedFields is not an array', ['settings' => $this->settings]);
            return $preparedData;
        }
    
        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            if ($field === 'content') {
                $preparedData['content'] = [
                    'content' => $this->getItemContent($item),
                    'weight' => (float)$weight
                ];
            } elseif (isset($item[$field])) {
                $preparedData[$field] = [
                    'content' => $item[$field],
                    'weight' => (float)$weight
                ];
            } else {
                $preparedData[$field] = [
                    'content' => '',
                    'weight' => (float)$weight
                ];
            }
        }
    
        $preparedData['content_modified_at'] = $item['content_modified_at'] ?? $item['crdate'] ?? $item['datetime'] ?? time();
    
        if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('semantic_suggestion_nlp')) {
            $this->logger?->info('semantic_suggestion_nlp is loaded');
    
            if (isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['semantic_suggestion']['nlpAnalysis'])) {
                foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['semantic_suggestion']['nlpAnalysis'] as $hookClassAndMethod) {
                    [$hookClass, $hookMethod] = explode('->', $hookClassAndMethod);
                    $hookInstance = GeneralUtility::makeInstance($hookClass);
                    if (method_exists($hookInstance, $hookMethod)) {
                        $analyzeParams = [
                            'itemId' => $item['uid'],
                            'languageUid' => $preparedData['sys_language_uid'],
                            'content' => $preparedData['content']['content'],
                            'analysis' => []
                        ];
                        $hookInstance->$hookMethod($analyzeParams);
                        
                        $preparedData['nlp'] = $analyzeParams['analysis']['nlp'] ?? [];
                    }
                }
            }
        }
    
        return $preparedData;
    }

    protected function getItemContent(array $item): string
    {
        if ($item['type'] === 'news') {
            return $item['bodytext'] ?? '';
        } else {
            return $this->getPageContent($item['uid'], $item['sys_language_uid'] ?? 0);
        }
    }

    protected function calculateSimilarity(array $item1, array $item2): array
    {
        $words1 = $this->getWeightedWords($item1);
        $words2 = $this->getWeightedWords($item2);

        $allWords = array_unique(array_merge(array_keys($words1), array_keys($words2)));
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        foreach ($allWords as $word) {
            $weight1 = $words1[$word] ?? 0;
            $weight2 = $words2[$word] ?? 0;
            $dotProduct += $weight1 * $weight2;
            $magnitude1 += $weight1 * $weight1;
            $magnitude2 += $weight2 * $weight2;
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 === 0 || $magnitude2 === 0) {
            return [
                'semanticSimilarity' => 0.0,
                'recencyBoost' => 0.0,
                'finalSimilarity' => 0.0
            ];
        }

        $semanticSimilarity = $dotProduct / ($magnitude1 * $magnitude2);

        $recencyBoost = $this->calculateRecencyBoost($item1, $item2);

        $recencyWeight = $this->settings['recencyWeight'] ?? 0.2;
        $finalSimilarity = ($semanticSimilarity * (1 - $recencyWeight)) + ($recencyBoost * $recencyWeight);

        $this->logger?->info('Similarity calculation', [
            'item1' => $item1['uid'] ?? 'unknown',
            'item2' => $item2['uid'] ?? 'unknown',
            'semanticSimilarity' => $semanticSimilarity, 
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => $finalSimilarity,
            'fieldScores' => [
                'title' => $this->calculateFieldSimilarity($item1['title'] ?? [], $item2['title'] ?? []),
                'description' => $this->calculateFieldSimilarity($item1['description'] ?? [], $item2['description'] ?? []),
                'keywords' => $this->calculateFieldSimilarity($item1['keywords'] ?? [], $item2['keywords'] ?? []),
                'content' => $this->calculateFieldSimilarity($item1['content'] ?? [], $item2['content'] ?? []),
            ]
        ]);

        return [
            'semanticSimilarity' => $semanticSimilarity,
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => min($finalSimilarity, 1.0)
        ];
    }

    protected function getWeightedWords(array $itemData): array
    {
        $weightedWords = [];

        foreach ($itemData as $field => $data) {
            if (!isset($data['content']) || !is_string($data['content'])) {
                continue;
            }

            $words = array_count_values(str_word_count(strtolower($data['content']), 1));
            $weight = $data['weight'] ?? 1.0;

            foreach ($words as $word => $count) {
                $weightedWords[$word] = ($weightedWords[$word] ?? 0) + ($count * $weight);
            }
        }

        return $weightedWords;
    }

    protected function calculateRecencyBoost(array $item1, array $item2): float
    {
        $now = time();
        $maxAge = 30 * 24 * 3600; // 30 days in seconds

        $timestamp1 = $item1['type'] === 'news' ? ($item1['datetime'] ?? $now) : ($item1['content_modified_at'] ?? $now);
        $timestamp2 = $item2['type'] === 'news' ? ($item2['datetime'] ?? $now) : ($item2['content_modified_at'] ?? $now);

        $age1 = min($now - $timestamp1, $maxAge);
        $age2 = min($now - $timestamp2, $maxAge);
        
        // Normalize ages between 0 and 1
        $normalizedAge1 = 1 - ($age1 / $maxAge);
        $normalizedAge2 = 1 - ($age2 / $maxAge);
        
        // Calculate the recency difference
        $recencyDifference = abs($normalizedAge1 - $normalizedAge2);

        // Apply a higher weight to news items if they are more recent
        if ($item1['type'] === 'news' && $item2['type'] === 'news') {
            return $recencyDifference * 1.2; // Increase the impact for news-to-news comparisons
        } elseif ($item1['type'] === 'news' || $item2['type'] === 'news') {
            return $recencyDifference * 1.1; // Slightly increase the impact for news-to-page comparisons
        }

        return $recencyDifference;
    }

    protected function calculateFieldSimilarity($field1, $field2): float
    {
        if (!isset($field1['content']) || !isset($field2['content'])) {
            return 0.0;
        }

        $words1 = array_count_values(str_word_count(strtolower($field1['content']), 1));
        $words2 = array_count_values(str_word_count(strtolower($field2['content']), 1));

        $allWords = array_unique(array_merge(array_keys($words1), array_keys($words2)));
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        foreach ($allWords as $word) {
            $count1 = $words1[$word] ?? 0;
            $count2 = $words2[$word] ?? 0;
            $dotProduct += $count1 * $count2;
            $magnitude1 += $count1 * $count1;
            $magnitude2 += $count2 * $count2;
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        return ($magnitude1 > 0 && $magnitude2 > 0) ? $dotProduct / ($magnitude1 * $magnitude2) : 0.0;
    }

    protected function findCommonKeywords(array $item1, array $item2): array
    {
        $keywords1 = isset($item1['keywords']['content']) ? array_flip(array_map('trim', explode(',', strtolower($item1['keywords']['content'])))) : [];
        $keywords2 = isset($item2['keywords']['content']) ? array_flip(array_map('trim', explode(',', strtolower($item2['keywords']['content'])))) : [];

        return array_keys(array_intersect_key($keywords1, $keywords2));
    }

    protected function determineRelevance($similarity): string
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

    protected function calculateDepth(array $items): int
    {
        $maxDepth = 0;
        $itemsByUid = [];
    
        // First pass: index items by their UID
        foreach ($items as $item) {
            if (isset($item['uid'])) {
                $itemsByUid[$item['uid']] = $item;
            }
        }
    
        // Second pass: calculate depth
        foreach ($items as $item) {
            $depth = 1;
            $currentItem = $item;
            while (isset($currentItem['pid']) && $currentItem['pid'] !== 0 && isset($itemsByUid[$currentItem['pid']])) {
                $depth++;
                $currentItem = $itemsByUid[$currentItem['pid']];
            }
            $maxDepth = max($maxDepth, $depth);
        }
    
        return $maxDepth;
    }

    protected function getPageContent(int $pageId, int $languageUid = 0): string
    {
        try {
            $queryBuilder = $this->getQueryBuilder();
    
            $content = $queryBuilder
                ->select('bodytext')
                ->from('tt_content')
                ->where(
                    $queryBuilder->expr()->eq('tt_content.pid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('tt_content.hidden', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('tt_content.deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('tt_content.sys_language_uid', $queryBuilder->createNamedParameter($languageUid, \PDO::PARAM_INT))
                )
                ->executeQuery()
                ->fetchAllAssociative();
    
            return implode(' ', array_column($content, 'bodytext'));
        } catch (\Exception $e) {
            $this->logger?->error('Error fetching page content', ['pageId' => $pageId, 'languageUid' => $languageUid, 'exception' => $e->getMessage()]);
            throw $e;
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

    protected function getCurrentLanguageUid(): int
    {
        try {
            return (int)$this->context->getAspect('language')->getId();
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to get language from context, defaulting to 0', ['exception' => $e]);
            return 0;
        }
    }
}