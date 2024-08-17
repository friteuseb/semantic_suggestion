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
use TalanHdf\SemanticSuggestion\Service\NlpService;

class PageAnalysisService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $context;
    protected $configurationManager;
    protected $settings;
    protected $cache;
    protected $connectionPool;
    protected $nlpService;

    public function __construct(
        Context $context,
        ConfigurationManagerInterface $configurationManager,
        NlpService $nlpService,
        ?CacheManager $cacheManager = null,
        ?ConnectionPool $dbConnection = null
    ) {
        $this->context = $context;
        $this->configurationManager = $configurationManager;
        $this->nlpService = $nlpService;
        
        $this->connectionPool = $dbConnection ?? GeneralUtility::makeInstance(ConnectionPool::class);

        $this->initializeSettings();
        $this->initializeCache($cacheManager);
    }

    protected function initializeSettings(): void
    {
        $this->settings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'semanticsuggestion_suggestions'
        );

        $this->settings['recencyWeight'] = max(0, min(1, (float)($this->settings['recencyWeight'] ?? 0.2)));
        $this->settings['nlpWeight'] = max(0, min(1, (float)($this->settings['nlpWeight'] ?? 0.3)));
        $this->settings['proximityThreshold'] = (float)($this->settings['proximityThreshold'] ?? 0.5);
        $this->settings['maxSuggestions'] = (int)($this->settings['maxSuggestions'] ?? 5);

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

    protected function initializeCache(?CacheManager $cacheManager): void
    {
        if ($cacheManager) {
            try {
                $this->cache = $cacheManager->getCache('semantic_suggestion');
            } catch (\TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException $e) {
                $this->cache = $cacheManager->getCache('null');
            }
        } else {
            $this->cache = $this->createFallbackCache();
        }
    }



    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }


    
    protected function preparePageData(array $page): array
    {
        $preparedData = [];
        $nlpAnalysis = null;

        if (!is_array($this->settings['analyzedFields'])) {
            $this->logger?->warning('analyzedFields is not an array', ['settings' => $this->settings]);
            return $preparedData;
        }

        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            $content = $this->getFieldContent($page, $field);
            if (empty($content)) {
                continue;
            }

            $preparedData[$field] = [
                'content' => $content,
                'weight' => (float)$weight
            ];

            if ($this->nlpService->isEnabled() && ($field === 'content' || in_array($field, ['title', 'description', 'keywords']))) {
                $preparedData[$field]['nlp'] = $this->nlpService->analyzeContent($content);
                if ($field === 'content') {
                    $nlpAnalysis = $preparedData[$field]['nlp'];
                }
            }
        }

        $preparedData['content_modified_at'] = $page['content_modified_at'] ?? $page['crdate'] ?? time();

        if ($nlpAnalysis) {
            $preparedData['nlp'] = $nlpAnalysis;
            $this->logger->debug('NLP analysis result', ['pageId' => $page['uid'], 'nlpAnalysis' => $nlpAnalysis]);
        }

        return $preparedData;
    }

    protected function getFieldContent(array $page, string $field): string
    {
        if ($field === 'content') {
            try {
                return $this->getPageContent($page['uid']);
            } catch (\Exception $e) {
                $this->logger?->error('Error fetching page content', ['pageId' => $page['uid'], 'exception' => $e->getMessage()]);
                return '';
            }
        }
        return $page[$field] ?? '';
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
    
            $fullContent = implode(' ', array_column($content, 'bodytext'));
            $this->logger->info('Retrieved page content', ['pageId' => $pageId, 'content_length' => strlen($fullContent)]);
    
            return $fullContent;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching page content', ['pageId' => $pageId, 'exception' => $e->getMessage()]);
            throw $e;
        }
    }


    private function calculateSimilarities(array $analysisResults): array
    {
        $similarities = [];
        $similarityCalculations = 0;
    
        foreach ($analysisResults as $pageId1 => $pageData1) {
            foreach ($analysisResults as $pageId2 => $pageData2) {
                if ($pageId1 >= $pageId2) {
                    continue;  // Évite les calculs redondants et la comparaison d'une page avec elle-même
                }
    
                $similarity = $this->calculateSimilarity($pageData1, $pageData2);
                $similarities[$pageId1][$pageId2] = $similarity;
                $similarities[$pageId2][$pageId1] = $similarity;  // La similarité est symétrique
    
                $analysisResults[$pageId1]['similarities'][$pageId2] = $this->formatSimilarityResult($similarity, $pageData1, $pageData2);
                $analysisResults[$pageId2]['similarities'][$pageId1] = $this->formatSimilarityResult($similarity, $pageData2, $pageData1);
    
                $similarityCalculations += 2;  // Compte pour les deux directions
            }
        }
    
        return [
            'analysisResults' => $analysisResults,
            'similarityCalculations' => $similarityCalculations
        ];
    }
    

    private function calculateSimilarity(array $page1, array $page2): array
    {
        $semanticSimilarity = $this->calculateSemanticSimilarity($page1, $page2);
        $recencyBoost = $this->calculateRecencyBoost($page1, $page2);
        
        $nlpSimilarity = 0.0;
        if ($this->nlpService->isEnabled()) {
            $nlpData1 = $page1['nlp'] ?? [];
            $nlpData2 = $page2['nlp'] ?? [];
            $nlpSimilarity = $this->calculateNlpSimilarity($nlpData1, $nlpData2);
        }
    
        $recencyWeight = (float)($this->settings['recencyWeight'] ?? 0.2);
        $nlpWeight = (float)($this->settings['nlpWeight'] ?? 0.3);
        $semanticWeight = 1 - $recencyWeight - $nlpWeight;
    
        $finalSimilarity = ($semanticSimilarity * $semanticWeight) + 
                           ($recencyBoost * $recencyWeight) + 
                           ($nlpSimilarity * $nlpWeight);
    
        return [
            'semanticSimilarity' => $semanticSimilarity,
            'recencyBoost' => $recencyBoost,
            'nlpSimilarity' => $nlpSimilarity,
            'finalSimilarity' => min($finalSimilarity, 1.0)
        ];
    }

    
    private function formatSimilarityResult(array $similarity, array $currentPage, array $comparisonPage): array
    {
        return [
            'score' => $similarity['finalSimilarity'],
            'semanticSimilarity' => $similarity['semanticSimilarity'],
            'recencyBoost' => $similarity['recencyBoost'],
            'nlpSimilarity' => $similarity['nlpSimilarity'],
            'commonKeywords' => $this->findCommonKeywords($currentPage, $comparisonPage),
            'relevance' => $this->determineRelevance($similarity['finalSimilarity']),
            'ageInDays' => round((time() - $comparisonPage['content_modified_at']) / (24 * 3600), 1),
        ];
    }

    
    private function calculateSemanticSimilarity(array $page1, array $page2): float
    {
        $content1 = $this->getCombinedContent($page1);
        $content2 = $this->getCombinedContent($page2);

        $words1 = $this->getUniqueWords($content1);
        $words2 = $this->getUniqueWords($content2);

        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }

    private function getCombinedContent(array $page): string
    {
        $content = '';
        foreach ($page as $field => $data) {
            if (isset($data['content']) && is_string($data['content'])) {
                $content .= ' ' . $data['content'];
            }
        }
        return trim($content);
    }

    private function getUniqueWords(string $content): array
    {
        $words = str_word_count(strtolower($content), 1);
        return array_unique($words);
    }


    private function calculateNlpSimilarity(array $nlpData1, array $nlpData2): float
{
    if (empty($nlpData1) || empty($nlpData2)) {
        return 0.0;
    }

    $similarities = [
        'sentiment' => $nlpData1['sentiment'] === $nlpData2['sentiment'] ? 1.0 : 0.0,
        'keyphrases' => $this->compareArrays($nlpData1['keyphrases'], $nlpData2['keyphrases']),
        'named_entities' => $this->compareNamedEntities($nlpData1['named_entities'], $nlpData2['named_entities']),
        'category' => $nlpData1['category'] === $nlpData2['category'] ? 1.0 : 0.0,
        'readability' => 1 - min(abs($nlpData1['readability_score'] - $nlpData2['readability_score']) / 100, 1)
    ];

    $weights = $this->settings['nlp']['weights'] ?? [
        'sentiment' => 0.2,
        'keyphrases' => 0.3,
        'named_entities' => 0.2,
        'category' => 0.2,
        'readability' => 0.1
    ];

    $weightedSum = 0;
    $totalWeight = 0;

    foreach ($similarities as $key => $value) {
        $weightedSum += $value * $weights[$key];
        $totalWeight += $weights[$key];
    }

    return $totalWeight > 0 ? $weightedSum / $totalWeight : 0;
}
    
    private function compareNamedEntities(array $entities1, array $entities2): float
    {
        $types1 = array_column($entities1, 'type');
        $types2 = array_column($entities2, 'type');
        
        $typeSimilarity = $this->compareArrays($types1, $types2);
        
        $names1 = array_column($entities1, 'name');
        $names2 = array_column($entities2, 'name');
        
        $nameSimilarity = $this->compareArrays($names1, $names2);
        
        return ($typeSimilarity + $nameSimilarity) / 2;
    }
    

    private function compareArrays(array $arr1, array $arr2): float
    {
        $intersection = array_intersect($arr1, $arr2);
        $union = array_unique(array_merge($arr1, $arr2));
        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
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