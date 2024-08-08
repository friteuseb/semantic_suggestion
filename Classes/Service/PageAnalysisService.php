<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;


// Ligne vide ajoutée ici
use TYPO3\CMS\Core\Database\Connection;

// Ligne vide ajoutée ici
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
            'semanticsuggestion_suggestions' // Nom de l'extension
        );


        if ($cacheManager) {
            try {
                $this->cache = $cacheManager->getCache('semantic_suggestion');
            } catch (\TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException $e) {
                $this->cache = $cacheManager->getCache('null'); // Fallback to 'null' cache if 'semantic_suggestion' doesn't exist
            }
        } else {
            // Create a simple in-memory cache if no CacheManager is provided
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
                public function flush()
 {
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

    /**
     * Analyzes pages and calculates similarities
     *
     * @param int|null $parentPageId Parent page ID (defaults to configured ID)
     * @param int|null $depth Depth of analysis (defaults to configured depth)
     * @return array Analysis results
     */
    public function analyzePages(int $parentPageId = null, int $depth = null): array
    {
        $startTime = microtime(true);

        // Set default values if not provided
        $parentPageId = $parentPageId ?? (int)$this->settings['parentPageId'];
        $depth = $depth ?? (int)$this->settings['recursive'];
        $cacheIdentifier = 'semantic_analysis_' . $parentPageId . '_' . $depth;

        // Check if the results are already cached
        if ($this->cache->has($cacheIdentifier)) {
            $cachedResult = $this->cache->get($cacheIdentifier);
            $cachedResult['metrics']['fromCache'] = true;
            $cachedResult['metrics']['executionTime'] = microtime(true) - $startTime;
            return $cachedResult;
        }

        try {
            // Retrieve all subpages
            $pages = $this->getAllSubpages($parentPageId, $depth);
            $totalPages = count($pages);
            $analysisResults = [];

            // Prepare data for each page
            foreach ($pages as $page) {
                $analysisResults[$page['uid']] = $this->preparePageData($page);
            }

            $similarityCalculations = 0;
            // Calculate similarities between pages
            foreach ($analysisResults as $pageId => &$pageData) {
                foreach ($analysisResults as $comparisonPageId => $comparisonPageData) {
                    if ($pageId !== $comparisonPageId) {
                        $similarity = $this->calculateSimilarity($pageData, $comparisonPageData);
                        $pageData['similarities'][$comparisonPageId] = [
                            'score' => $similarity,
                            'commonKeywords' => $this->findCommonKeywords($pageData, $comparisonPageData),
                            'relevance' => $this->determineRelevance($similarity),
                        ];
                        $similarityCalculations++;
                    }
                }
            }

        } catch (\Exception $e) {
            // Log the error and return an empty result
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

        // Cache the analysis results for 24 hours
        $this->cache->set($cacheIdentifier, $result, ['pages'], 86400);

        return $result;
    }
 /**
     * Prepares page data based on configured fields
     *
     * @param array $page Page data
     * @return array Prepared data
     */
    protected function preparePageData(array $page): array
    {
        $preparedData = [];

        // Check if analyzedFields is configured correctly
        if (!is_array($this->settings['analyzedFields'])) {
            $this->logger?->warning('analyzedFields is not an array', ['settings' => $this->settings]);
            return $preparedData; // Return empty array if configuration is invalid
        }

        // Iterate over configured fields and their weights
        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            if ($field === 'content') {
                // Get content if field is 'content'
                try {
                    $preparedData['content'] = [
                        'content' => $this->getPageContent($page['uid']),
                        'weight' => (float)$weight
                    ];
                } catch (\Exception $e) {
                    $this->logger?->error('Error fetching page content', ['pageId' => $page['uid'], 'exception' => $e->getMessage()]);
                    // You might want to handle this error differently, e.g., set a default content or re-throw the exception
                    $preparedData['content'] = [
                        'content' => '', // Set default content on error
                        'weight' => (float)$weight
                    ];
                }
            } elseif (isset($page[$field])) {
                // Add existing field content and weight
                $preparedData[$field] = [
                    'content' => $page[$field],
                    'weight' => (float)$weight
                ];
            } else {
                // Add an empty field with default weight if the field doesn't exist
                $preparedData[$field] = [
                    'content' => '',
                    'weight' => (float)$weight
                ];
            }
        }

        return $preparedData;
    }

    /**
     * Get all subpages recursively
     *
     * @param int $parentId Parent page ID
     * @param int $depth Depth of recursion
     * @return array List of all subpages
     * @throws \Exception If there's an error fetching subpages
     */
    private function getAllSubpages(int $parentId, int $depth = 0): array
    {
        $allPages = [];
        $queue = [[$parentId, 0]];

        while (!empty($queue)) {
            [$currentId, $currentDepth] = array_shift($queue);

            if ($depth !== -1 && $currentDepth > $depth) {
                continue; // Stop recursion if depth limit is reached
            }

            $pages = $this->getSubpages($currentId);
            $allPages = array_merge($allPages, $pages);

            foreach ($pages as $page) {
                $queue[] = [$page['uid'], $currentDepth + 1];
            }
        }

        return $allPages;
    }

    /**
     * Get immediate subpages of a given page
     *
     * @param int $parentId Parent page ID
     * @return array List of subpages
     * @throws \Exception If there's an error fetching subpages
     */
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

            // Define the fields to retrieve
            $fieldsToSelect = ['uid', 'title', 'description', 'keywords', 'abstract'];

            // Ensure fields exist in the 'pages' table
            $tableColumns = $queryBuilder->getConnection()->getSchemaManager()->listTableColumns('pages');
            $existingColumns = array_keys($tableColumns);
            $fieldsToSelect = array_intersect($fieldsToSelect, $existingColumns);

            if ($this->logger) {
                $this->logger->debug('Fields to select', ['fields' => $fieldsToSelect]);
            }

            // Execute the query
            $result = $queryBuilder
                ->select(...$fieldsToSelect)
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentId, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([$languageUid, -1], Connection::PARAM_INT_ARRAY))
                )
                ->executeQuery()
                ->fetchAllAssociative();

            if ($this->logger) {
                $this->logger->info('Subpages fetched successfully', ['count' => count($result)]);
                $this->logger->debug('Fetched subpages', ['subpages' => $result]);
            }

            return $result;
        } catch (\Exception $e) {
            // Log the error and re-throw the exception
            $this->logger?->error('Error fetching subpages', ['exception' => $e->getMessage()]);
            throw $e; 
        }
    }

    /**
     * Get page content
     *
     * @param int $pageId Page ID
     * @return string Page content
     * @throws \Exception If there's an error fetching page content
     */
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

            // Concatenate all bodytext content into a single string
            return implode(' ', array_column($content, 'bodytext'));
        } catch (\Exception $e) {
            // Log the error and re-throw the exception
            $this->logger?->error('Error fetching page content', ['pageId' => $pageId, 'exception' => $e->getMessage()]);
            throw $e; 
        }
    }

    /**
     * Get weighted words from page data
     *
     * @param array $pageData Page data
     * @return array Weighted words
     */
    private function getWeightedWords(array $pageData): array
    {
        $weightedWords = [];

        foreach ($pageData as $field => $data) {
            // Check if content exists and is a string
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

    /**
     * Calculate similarity between two pages
     *
     * @param array $page1 Data for the first page
     * @param array $page2 Data for the second page
     * @return float Similarity score
     */
    private function calculateSimilarity(array $page1, array $page2): float
    {
        $words1 = $this->getWeightedWords($page1);
        $words2 = $this->getWeightedWords($page2);

        // Calculate intersection and union of words
        $intersection = array_intersect_key($words1, $words2);
        $union = $words1 + $words2;

        $intersectionSum = array_sum($intersection);
        $unionSum = array_sum($union);

        // Prevent division by zero
        if ($unionSum === 0) {
            return 0.0;
        }

        $similarity = min($intersectionSum / $unionSum, 1.0);

        // Log similarity calculation details (optional)
        $this->logger?->info('Similarity calculation', [
            'page1' => $page1['uid'] ?? 'unknown',
            'page2' => $page2['uid'] ?? 'unknown',
            'similarity' => $similarity,
            'fieldScores' => [
                'title' => $this->calculateFieldSimilarity($page1['title'] ?? [], $page2['title'] ?? []),
                'description' => $this->calculateFieldSimilarity($page1['description'] ?? [], $page2['description'] ?? []),
                'keywords' => $this->calculateFieldSimilarity($page1['keywords'] ?? [], $page2['keywords'] ?? []),
                'content' => $this->calculateFieldSimilarity($page1['content'] ?? [], $page2['content'] ?? []),
            ]
        ]);

        return $similarity;
    }

    /**
     * Calculate similarity for a specific field
     *
     * @param array $field1 Data for the first field
     * @param array $field2 Data for the second field
     * @return float Similarity score for the field
     */
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

    /**
     * Find common keywords between two pages
     *
     * @param array $page1 Data for the first page
     * @param array $page2 Data for the second page
     * @return array Common keywords
     */
    private function findCommonKeywords(array $page1, array $page2): array
    {
        $keywords1 = isset($page1['keywords']['content']) ? array_map('trim', explode(',', strtolower($page1['keywords']['content']))) : [];
        $keywords2 = isset($page2['keywords']['content']) ? array_map('trim', explode(',', strtolower($page2['keywords']['content']))) : [];

        return array_intersect($keywords1, $keywords2);
    }

    /**
     * Determine relevance based on similarity score
     *
     * @param float $similarity Similarity score
     * @return string Relevance level ('High', 'Medium', 'Low')
     */
    private function determineRelevance(float $similarity): string
    {
        if ($similarity > 0.7) {
            return 'High';
        } elseif ($similarity > 0.4) {
            return 'Medium';
        } else {
            return 'Low';
        }
    }

    /**
     * Get current language UID
     *
     * @return int Language UID
     */
    private function getCurrentLanguageUid(): int
    {
        try {
            return (int)$this->context->getAspect('language')->getId();
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to get language from context, defaulting to 0', ['exception' => $e]);
            return 0;
        }
    }
}
