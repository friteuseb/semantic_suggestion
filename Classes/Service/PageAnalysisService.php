<?php
namespace Talan\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class PageAnalysisService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $context;
    protected $configurationManager;
    protected $settings;

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
    }

    /**
     * Analyze pages and calculate similarities
     *
     * @param int $parentPageId
     * @param int $depth
     * @return array
     */
    public function analyzePages(int $parentPageId, int $depth = 0): array
    {
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
                // Ajouter un champ vide avec un poids par défaut si le champ n'existe pas
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

        foreach ($pages as $page) {
            if ($depth !== 1) {
                $subPages = $this->getAllSubpages($page['uid'], $depth - 1);
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
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $languageUid = $this->getCurrentLanguageUid();
    
        $this->logger->info('Current language UID: ' . $languageUid);
    
        // Définissez explicitement les champs que vous voulez récupérer
        $fieldsToSelect = ['uid', 'title', 'description', 'keywords', 'abstract'];
    
        // Assurez-vous que ces champs existent dans la table 'pages'
        $tableColumns = $queryBuilder->getConnection()->getSchemaManager()->listTableColumns('pages');
        $existingColumns = array_keys($tableColumns);
        $fieldsToSelect = array_intersect($fieldsToSelect, $existingColumns);
    
        return $queryBuilder
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