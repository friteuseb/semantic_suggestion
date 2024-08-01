<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Cache\CacheManager;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class PageAnalysisService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $context;
    protected $configurationManager;
    protected $settings;
    protected $cache;

    /**
     * Constructor
     *
     * @param Context $context
     * @param ConfigurationManagerInterface $configurationManager
     */
    public function __construct(Context $context, ConfigurationManagerInterface $configurationManager)
    {
        $this->context = $context;
        $this->configurationManager = $configurationManager;
        $this->settings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'semanticsuggestion_suggestions'
        );
        $this->cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('semantic_suggestion');

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
    /**
     * Analyze pages and calculate similarities
     *
     * @param int|null $parentPageId
     * @param int|null $depth
     * @return array
     */
    public function analyzePages(int $parentPageId = null, int $depth = null): array
    {
        $parentPageId = $parentPageId ?? (int)$this->settings['parentPageId'];
        $depth = $depth ?? (int)$this->settings['recursive'];
        $cacheIdentifier = 'semantic_analysis_' . $parentPageId . '_' . $depth;

        if ($this->cache->has($cacheIdentifier)) {
            return $this->cache->get($cacheIdentifier);
        }

        $pages = $this->getAllSubpages($parentPageId, $depth);
        $analysisResults = [];

        foreach ($pages as $page) {
            $analysisResults[$page['uid']] = $this->preparePageData($page);
        }

        // Calculate similarities
        foreach ($analysisResults as $pageId => &$pageData) {
            foreach ($analysisResults as $comparisonPageId => $comparisonPageData) {
                if ($pageId !== $comparisonPageId) {
                    $similarity = $this->calculateSimilarity($pageData, $comparisonPageData);
                    $pageData['similarities'][$comparisonPageId] = [
                        'score' => $similarity,
                        'commonKeywords' => $this->findCommonKeywords($pageData, $comparisonPageData),
                        'relevance' => $this->determineRelevance($similarity),
                    ];
                }
            }
        }

        $this->cache->set($cacheIdentifier, $analysisResults, ['pages'], 86400); // Cache for 24 hours

        return $analysisResults;
    }

    /**
     * Prepare page data based on configured fields
     *
     * @param array $page
     * @return array
     */

    protected function preparePageData(array $page): array
     {
         $preparedData = [];
         if (!is_array($this->settings['analyzedFields'])) {
             $this->logger->warning('analyzedFields is not an array', ['settings' => $this->settings]);
             return $preparedData;
         }
         foreach ($this->settings['analyzedFields'] as $field => $weight) {
            if ($field === 'content') {
                $preparedData['content'] = [
                    'content' => $this->getPageContent($page['uid']),
                    'weight' => (float)$weight
                ];
            } elseif (isset($page[$field])) {
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
     * @param int $parentId
     * @param int $depth
     * @return array
     */
    private function getAllSubpages(int $parentId, int $depth = 0): array
    {
        $pages = $this->getSubpages($parentId);
        $allPages = $pages;
    
        if ($depth > 0 || $depth === -1) { // -1 pour une profondeur illimitée
            foreach ($pages as $page) {
                $subPages = $this->getAllSubpages($page['uid'], $depth === -1 ? -1 : $depth - 1);
                $allPages = array_merge($allPages, $subPages);
            }
        }
    
        return $allPages;
    }

/**
 * Get immediate subpages of a given page
 *
 * @param int $parentId
 * @return array
 */
private function getSubpages(int $parentId): array
{
    $this->logger->info('Fetching subpages', ['parentId' => $parentId]);

    try {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $languageUid = $this->getCurrentLanguageUid();

        $this->logger->info('Current language UID: ' . $languageUid);

        // Define explicitly the fields you want to retrieve
        $fieldsToSelect = ['uid', 'title', 'description', 'keywords', 'abstract'];

        // Ensure these fields exist in the 'pages' table
        $tableColumns = $queryBuilder->getConnection()->getSchemaManager()->listTableColumns('pages');
        $existingColumns = array_keys($tableColumns);
        $fieldsToSelect = array_intersect($fieldsToSelect, $existingColumns);

        $this->logger->debug('Fields to select', ['fields' => $fieldsToSelect]);

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

        $this->logger->info('Subpages fetched successfully', ['count' => count($result)]);
        $this->logger->debug('Fetched subpages', ['subpages' => $result]);

        return $result;
    } catch (\Exception $e) {
        $this->logger->error('Error fetching subpages', ['exception' => $e->getMessage()]);
        return [];
    }
}
    

    /**
     * Get page content
     *
     * @param int $pageId
     * @return string
     */
    private function getPageContent(int $pageId): string
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $languageUid = $this->getCurrentLanguageUid();
    
        $content = $queryBuilder
            ->select('bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([$languageUid, -1], Connection::PARAM_INT_ARRAY))
            )
            ->executeQuery()
            ->fetchAllAssociative();
    
        return implode(' ', array_column($content, 'bodytext'));
    }

    /**
     * Get weighted words from page data
     *
     * @param array $pageData
     * @return array
     */
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

    /**
     * Calculate similarity between two pages
     *
     * @param array $page1
     * @param array $page2
     * @return float
     */
    private function calculateSimilarity(array $page1, array $page2): float
    {
        $words1 = $this->getWeightedWords($page1);
        $words2 = $this->getWeightedWords($page2);
    
        $intersection = array_intersect_key($words1, $words2);
        $union = $words1 + $words2;
    
        $intersectionSum = array_sum($intersection);
        $unionSum = array_sum($union);
    
        if ($unionSum === 0) {
            return 0.0;
        }
    
        $similarity = min($intersectionSum / $unionSum, 1.0);
    
        $this->logger->info('Similarity calculation', [
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
     * @param array $field1
     * @param array $field2
     * @return float
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
     * @param array $page1
     * @param array $page2
     * @return array
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
     * @param float $similarity
     * @return string
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
     * @return int
     */
    private function getCurrentLanguageUid(): int
    {
        try {
            return $this->context->getPropertyFromAspect('language', 'id');
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get language from context, defaulting to 0', ['exception' => $e]);
            return 0;
        }
    }
}