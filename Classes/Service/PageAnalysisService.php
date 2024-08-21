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
    public function setSettings(array $settings): void                                                                                        
      {                                                                                                                                         
          $this->settings = array_merge($this->settings, $settings);                                                                            
          if (!isset($this->settings['recencyWeight'])) {                                                                                       
              $this->settings['recencyWeight'] = 0.2; // Valeur par défaut                                                                      
          }                                                                                                                                     
          $this->settings['recencyWeight'] = max(0, min(1, (float)$this->settings['recencyWeight'])); // Assurez-vous que la valeur est entre 0 et 1                                                                                                                                      
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

    /**
     * @return array Prepared data
     */
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
    $language = $this->getCurrentLanguageUid();

    foreach ($pageData as $field => $data) {
        if (!isset($data['content']) || !is_string($data['content'])) {
            continue;
        }

        $text = $this->normalizeText($data['content']);
        $words = str_word_count($text, 1);
        $words = $this->removeStopWords($words, $language);
        $weight = $data['weight'] ?? 1.0;

        foreach ($words as $word) {
            $weightedWords[$word] = ($weightedWords[$word] ?? 0) + $weight;
        }
    }

    return $weightedWords;
}



    private function calculateSimilarity(array $page1, array $page2): array
    {
        $language = $this->getCurrentLanguageUid();

        // Normaliser et préparer le texte
        $words1 = $this->prepareText($page1, $language);
        $words2 = $this->prepareText($page2, $language);

        // Calculer la similarité basée sur les mots
        $similarity = $this->calculateWordSimilarity($words1, $words2);

        // Calculer la similarité basée sur les n-grammes
        $ngramSimilarity = $this->calculateNGramSimilarity($words1, $words2);

        // Calculer le boost de récence
        $recencyBoost = $this->calculateRecencyBoost($page1, $page2);

        // Combiner les différents scores
        $finalSimilarity = $this->combineSimilarityScores($similarity, $ngramSimilarity, $recencyBoost);

        return [
            'semanticSimilarity' => $similarity,
            'ngramSimilarity' => $ngramSimilarity,
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => $finalSimilarity
        ];
    }

    private function prepareText(array $pageData, string $language): array
    {
        $text = implode(' ', array_column($pageData, 'content'));
        $text = $this->normalizeText($text);
        $words = str_word_count($text, 1);
        $words = $this->removeStopWords($words, $language);
        return $words;
    }

    private function calculateWordSimilarity(array $words1, array $words2): float
    {
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));
        return count($intersection) / count($union);
    }

    private function calculateNGramSimilarity(array $words1, array $words2, int $n = 2): float
    {
        $ngrams1 = $this->generateNGrams($words1, $n);
        $ngrams2 = $this->generateNGrams($words2, $n);
        $intersection = array_intersect($ngrams1, $ngrams2);
        $union = array_unique(array_merge($ngrams1, $ngrams2));
        return count($intersection) / count($union);
    }

    private function combineSimilarityScores(float $wordSimilarity, float $ngramSimilarity, float $recencyBoost): float
    {
        $weightWord = 0.4;
        $weightNGram = 0.4;
        $weightRecency = 0.2;

        return ($wordSimilarity * $weightWord) + ($ngramSimilarity * $weightNGram) + ($recencyBoost * $weightRecency);
    }


        private function calculateTfIdfSimilarity(array $tfidf1, array $tfidf2): float
    {
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        foreach ($tfidf1 as $term => $score) {
            if (isset($tfidf2[$term])) {
                $dotProduct += $score * $tfidf2[$term];
            }
            $magnitude1 += $score * $score;
        }

        foreach ($tfidf2 as $score) {
            $magnitude2 += $score * $score;
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 * $magnitude2 == 0) {
            return 0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }



    public function calculateRecencyBoost(array $page1, array $page2): float
    {
        $now = time();
        $recencyWindow = (int)($this->settings['recencyWindow'] ?? 30) * 24 * 3600; // Convertir en secondes
        $minRecencyDifference = (int)($this->settings['minRecencyDifference'] ?? 1) * 24 * 3600; // Convertir en secondes
        $decayFactor = (float)($this->settings['recencyDecayFactor'] ?? 0.5);

        $age1 = $now - ($page1['content_modified_at'] ?? $now);
        $age2 = $now - ($page2['content_modified_at'] ?? $now);

        // Appliquer le seuil minimal de différence
        if (abs($age1 - $age2) < $minRecencyDifference) {
            return 0;
        }

        // Normaliser les âges entre 0 et 1, en tenant compte de la fenêtre de récence
        $normalizedAge1 = min($age1, $recencyWindow) / $recencyWindow;
        $normalizedAge2 = min($age2, $recencyWindow) / $recencyWindow;

        // Appliquer une fonction de décroissance logarithmique
        $recencyBoost1 = 1 - (log(1 + $normalizedAge1 * 9) / log(10)) * $decayFactor;
        $recencyBoost2 = 1 - (log(1 + $normalizedAge2 * 9) / log(10)) * $decayFactor;

        // Calculer la différence de récence
        $recencyDifference = abs($recencyBoost1 - $recencyBoost2);

        $this->logger->debug('Recency boost calculation', [
            'page1' => $page1['uid'] ?? 'unknown',
            'page2' => $page2['uid'] ?? 'unknown',
            'age1' => $age1,
            'age2' => $age2,
            'normalizedAge1' => $normalizedAge1,
            'normalizedAge2' => $normalizedAge2,
            'recencyBoost1' => $recencyBoost1,
            'recencyBoost2' => $recencyBoost2,
            'recencyDifference' => $recencyDifference
        ]);

        return $recencyDifference;
    }

    private function detectLanguage(string $text): string
    {
        $langScores = [
            'en' => 0, 'fr' => 0, 'es' => 0, 'de' => 0, 'it' => 0, 'pt' => 0
        ];

        $words = str_word_count(strtolower($text), 1);
        foreach ($words as $word) {
            foreach ($langScores as $lang => $score) {
                if (in_array($word, $this->getStopWordsForLanguage($lang))) {
                    $langScores[$lang]++;
                }
            }
        }

        arsort($langScores);
        return key($langScores); // Retourne la langue avec le score le plus élevé
    }

    private function removeStopWords(array $words, string $language): array
    {
        $stopWords = $this->getStopWordsForLanguage($language);
        return array_diff($words, $stopWords);
    }

    private function getStopWordsForLanguage(string $language): array
    {
        $stopWords = [
            'en' => ['the', 'is', 'at', 'which', 'on', 'and', 'a', 'an', 'of', 'to', 'in', 'that', 'it', 'with', 'as', 'for', 'was', 'were', 'be', 'by', 'this', 'are', 'from', 'or', 'but', 'not', 'they', 'can', 'we', 'there', 'so', 'no', 'up', 'if', 'out', 'about', 'into', 'when', 'who', 'what', 'where', 'how', 'why', 'will', 'would', 'should', 'could', 'their', 'my', 'your', 'his', 'her', 'its', 'our', 'have', 'has', 'had', 'do', 'does', 'did', 'than', 'then', 'too', 'more', 'over', 'only', 'just', 'like', 'also'],
            'fr' => ['le', 'la', 'les', 'est', 'à', 'de', 'des', 'et', 'un', 'une', 'du', 'en', 'dans', 'que', 'qui', 'où', 'par', 'pour', 'avec', 'sur', 'se', 'ce', 'sa', 'son', 'ses', 'au', 'aux', 'lui', 'elle', 'il', 'ils', 'elles', 'nous', 'vous', 'ne', 'pas', 'ni', 'plus', 'ou', 'mais', 'donc', 'car', 'si', 'tout', 'comme', 'cela', 'ont', 'été', 'était', 'être', 'sont', 'étant', 'ayant', 'avait', 'avaient'],
        ];
    
        return $stopWords[$language] ?? [];
    }

    private function normalizeText(string $text): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        return preg_replace('/[^a-zA-Z0-9\s]/', '', $text);
    }


    public function generateNGrams(array $words, int $n = 2): array
    {
        $ngrams = [];
        $count = count($words);
        for ($i = 0; $i < $count - $n + 1; $i++) {
            $ngrams[] = implode(' ', array_slice($words, $i, $n));
        }
        return $ngrams;
    }


    private function findSimilarWords(string $word, array $wordList, int $maxDistance = 2): array
    {
        $similar = [];
        foreach ($wordList as $compareWord) {
            if (levenshtein($word, $compareWord) <= $maxDistance) {
                $similar[] = $compareWord;
            }
        }
        return $similar;
    }


    private function calculateTfIdf(array $pageWords, array $allPagesWords): array
    {
        $totalDocs = count($allPagesWords);
        $tfidf = [];

        foreach ($pageWords as $word => $count) {
            $tf = $count / array_sum($pageWords);
            $docsWithTerm = count(array_filter($allPagesWords, function($doc) use ($word) {
                return isset($doc[$word]);
            }));
            $idf = log($totalDocs / (1 + $docsWithTerm));
            $tfidf[$word] = $tf * $idf;
        }

        arsort($tfidf);
        return $tfidf;
    }


    private function calculateFieldSimilarity($field1, $field2): float
    {if (!isset($field1['content']) || !isset($field2['content'])) {
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

    private function getCurrentLanguageUid(): string
    {
        try {
            $languageId = (int)$this->context->getAspect('language')->getId();
            // Convertir l'ID de langue en code de langue (vous devrez peut-être ajuster cette logique selon votre configuration)
            $languageCodes = [
                0 => 'en',  // Par défaut, considérons que 0 est l'anglais
                1 => 'de',  // Exemple : 1 pourrait être l'allemand
                2 => 'fr',  // Exemple : 2 pourrait être le français
                // Ajoutez d'autres mappings selon vos besoins
            ];
            return $languageCodes[$languageId] ?? 'en';  // Retourne 'en' si l'ID n'est pas trouvé
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to get language from context, defaulting to "en"', ['exception' => $e]);
            return 'en';
        }
    }
}