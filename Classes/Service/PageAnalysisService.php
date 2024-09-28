<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Cache\CacheManager;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Psr\Log\NullLogger;

class PageAnalysisService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected Context $context;
    protected ConfigurationManagerInterface $configurationManager;
    protected array $settings;
    protected ?CacheManager $cacheManager;
    protected ConnectionPool $connectionPool;
    protected ?QueryBuilder $queryBuilder = null;
    protected StopWordsService $stopWordsService;
    protected SiteFinder $siteFinder;
    protected FrontendInterface $cache;
    protected ?NlpApiService $nlpApiService = null;
    protected bool $useNlpApi = false;

    public function __construct(
        Context $context,
        ConfigurationManagerInterface $configurationManager,
        StopWordsService $stopWordsService,
        SiteFinder $siteFinder,
        ExtensionConfiguration $extensionConfiguration,
        ?CacheManager $cacheManager = null,
        ?ConnectionPool $connectionPool = null,
        ?LoggerInterface $logger = null
    ) {
        $this->context = $context;
        $this->configurationManager = $configurationManager;
        $this->stopWordsService = $stopWordsService;
        $this->siteFinder = $siteFinder;
        $this->cacheManager = $cacheManager;
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->logger = $logger ?? new NullLogger();
        $this->loadExtensionConfiguration($extensionConfiguration);


        if ($logger !== null) {
            $this->setLogger($logger);
        }

        $this->settings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'semanticsuggestion_suggestions'
        );

        $this->initializeSettings();
        $this->initializeCache();
    }

    private function logDebug(string $message, array $context = []): void
    {
        // Forcer le log pour le débogage
        $this->logger->debug($message, $context);
        
        // Ancien code commenté pour référence
        // if ($this->settings['debugMode']) {
        //     $this->logger->debug($message, $context);
        // }
    }

    private function logInfo(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    private function logWarning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    private function logError(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    protected function initializeSettings(): void
    {
        // Initialiser debugMode en premier
        $this->settings['debugMode'] = (bool)($this->settings['debugMode'] ?? false);
    
        $this->logDebug('Debug mode initialized', ['debugMode' => $this->settings['debugMode']]);
    
        $this->settings['recencyWeight'] = max(0, min(1, (float)($this->settings['recencyWeight'] ?? 0.2)));
    
        $this->settings['analyzedFields'] = $this->settings['analyzedFields'] ?? [
            'title' => 1.5,
            'description' => 1.0,
            'keywords' => 2.0,
            'abstract' => 1.2,
            'content' => 1.0
        ];
    
        $this->logDebug('Settings initialized', ['final_settings' => $this->settings]);
    }

    protected function initializeCache(): void
    {
        if ($this->cacheManager !== null) {
            try {
                $this->cache = $this->cacheManager->getCache('semantic_suggestion');
            } catch (\TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException $e) {
                $this->cache = $this->cacheManager->getCache('null');
            }
        } else {
            $this->cache = new class implements FrontendInterface {
                private array $data = [];
                public function set($entryIdentifier, $data, array $tags = [], $lifetime = null): void {
                    $this->data[$entryIdentifier] = $data;
                }
                public function get($entryIdentifier) {
                    return $this->data[$entryIdentifier] ?? false;
                }
                public function has($entryIdentifier): bool {
                    return isset($this->data[$entryIdentifier]);
                }
                public function remove($entryIdentifier): void {
                    unset($this->data[$entryIdentifier]);
                }
                public function flush(): void {
                    $this->data = [];
                }
                public function flushByTag($tag): void {}
                public function flushByTags(array $tags): void {}
                public function collectGarbage(): void {}
                public function isValidEntryIdentifier($identifier): bool {
                    return is_string($identifier);
                }
                public function isValidTag($tag): bool {
                    return is_string($tag);
                }
                public function getIdentifier(): string {
                    return 'fallback_cache';
                }
                public function getBackend() {
                    return null;
                }
            };
        }
    }

    protected function loadExtensionConfiguration(ExtensionConfiguration $extensionConfiguration): void
    {
        try {
            $config = $extensionConfiguration->get('semantic_suggestion');
            $this->useNlpApi = (bool)($config['settings']['useNlpApi'] ?? false);
            // Chargez d'autres configurations si nécessaire
            $this->logDebug('Extension configuration loaded', ['useNlpApi' => $this->useNlpApi]);
        } catch (\Exception $e) {
            $this->logger->error('Error loading extension configuration', ['exception' => $e->getMessage()]);
            $this->useNlpApi = false;
        }
    }
    public function setNlpApiService(NlpApiService $nlpApiService): void
    {
        $this->nlpApiService = $nlpApiService;
    }

    public function setUseNlpApi($useNlpApi): void
    {
        // Vérifie si la valeur est une chaîne et la convertit en booléen
        if (is_string($useNlpApi)) {
            $useNlpApi = filter_var($useNlpApi, FILTER_VALIDATE_BOOLEAN);
        }
        
        $this->useNlpApi = (bool)$useNlpApi;
        
        $this->logger->info('NLP API usage set', ['useNlpApi' => $this->useNlpApi]);
    }


    public function setSettings(array $settings): void
    {
        $this->settings = array_merge($this->settings, $settings);
        $this->initializeSettings();
    }

    protected function getQueryBuilder(string $table = 'pages'): QueryBuilder
    {
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

    
    protected function getCurrentLanguage(): string
    {
        $languageAspect = $this->context->getAspect('language');
        $languageId = $languageAspect->getId();
        
        try {
            $currentPageId = $this->getCurrentPageId();
            if ($currentPageId === null) {
                throw new \RuntimeException('Unable to determine current page ID');
            }
    
            $currentSite = $this->siteFinder->getSiteByPageId($currentPageId);
            $siteLanguage = $currentSite->getLanguageById($languageId);
            if ($siteLanguage) {
                $language = strtolower(substr($siteLanguage->getHreflang(), 0, 2));
                $this->logDebug('Language detected', ['language' => $language, 'languageId' => $languageId]);
                return $language;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to detect language automatically', ['exception' => $e->getMessage()]);
        }
    
        // Fallback to default language
        $defaultLanguage = $this->settings['defaultLanguage'] ?? 'en';
        $this->logDebug('Using fallback language', ['language' => $defaultLanguage]);
        return $defaultLanguage;
    }

    protected function detectLanguageAutomatically(int $languageId): ?string
    {
        try {
            $currentPageId = $this->getCurrentPageId();
            if ($currentPageId === null) {
                $this->logger?->warning('Unable to determine current page ID for language detection');
                return null;
            }
    
            $currentSite = $this->siteFinder->getSiteByPageId($currentPageId);
            $siteLanguage = $currentSite->getLanguageById($languageId);
            if ($siteLanguage) {
                return strtolower(substr($siteLanguage->getHreflang(), 0, 2));
            }
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to detect language automatically', ['exception' => $e->getMessage()]);
        }
        return null;
    }

    protected function getCurrentPageId(): ?int
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $pageArguments = $request->getAttribute('routing');
            if ($pageArguments instanceof PageArguments) {
                return $pageArguments->getPageId();
            }
            
            // Fallback pour le contexte backend
            $pageId = $request->getQueryParams()['id'] ?? null;
            if ($pageId !== null) {
                return (int)$pageId;
            }
        }
        
        // Fallback pour d'autres contextes
        if (isset($GLOBALS['TSFE']) && $GLOBALS['TSFE']->id) {
            return (int)$GLOBALS['TSFE']->id;
        }
        
        $this->logger?->warning('Unable to determine current page ID');
        return null;
    }


    protected function getLanguageFromTypoScript(int $languageId): ?string
    {
        $typoscriptMapping = $this->settings['languageMapping'] ?? [];
        return $typoscriptMapping[$languageId] ?? null;
    }


    protected function getCurrentLanguageUid(): int
    {
        return GeneralUtility::makeInstance(Context::class)->getAspect('language')->getId();
    }



    public function analyzePages(array $pages, int $currentLanguageUid): array
    {
        $startTime = microtime(true);
    
        if (empty($pages)) {
            $this->logger?->warning('No pages provided for analysis');
            return [
                'results' => [],
                'metrics' => [
                    'executionTime' => microtime(true) - $startTime,
                    'totalPages' => 0,
                    'similarityCalculations' => 0,
                    'fromCache' => false,
                ],
            ];
        }
    
        $language = $this->getCurrentLanguage();
        $stopwords = $this->stopWordsService->getStopWordsForLanguage($language);
    
        $this->logDebug('Starting page analysis', [
            'pageCount' => count($pages),
            'languageUid' => $currentLanguageUid,
            'language' => $language,
            'stopwordsCount' => count($stopwords)
        ]);
    
    
        $pagesByLanguage = [];
        foreach ($pages as $page) {
            $lang = $page['sys_language_uid'] ?? 0;
            $pagesByLanguage[$lang] = ($pagesByLanguage[$lang] ?? 0) + 1;
        }
    
    
        $firstPage = null;
        foreach ($pages as $page) {
            if ($page !== null) {
                $firstPage = $page;
                break;
            }
        }
    
        if ($firstPage === null) {
            $this->logger?->warning('No valid pages found in the provided array');
            return [
                'results' => [],
                'metrics' => [
                    'executionTime' => microtime(true) - $startTime,
                    'totalPages' => 0,
                    'similarityCalculations' => 0,
                    'fromCache' => false,
                ],
            ];
        }
    
        $parentPageId = $firstPage['pid'] ?? 0;
        $depth = $this->calculateDepth($pages);
        $cacheIdentifier = "semantic_analysis_{$parentPageId}_{$depth}_{$language}";
    
        if ($this->cache->has($cacheIdentifier)) {
            $cachedResult = $this->cache->get($cacheIdentifier);
            $cachedResult['metrics']['fromCache'] = true;
            $cachedResult['metrics']['executionTime'] = microtime(true) - $startTime;
            return $cachedResult;
        }
    
        try {
            $this->logDebug('Analyzing pages', ['pageCount' => count($pages), 'languageUid' => $currentLanguageUid]);
            $totalPages = count($pages);
            $analysisResults = [];
    
            foreach ($pages as $page) {
                if (isset($page['uid'])) {
                    $analysisResults[$page['uid']] = $this->preparePageData($page, $currentLanguageUid);
                } else {
                    $this->logger?->warning('Page without UID encountered', ['page' => $page]);
                }
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
                            'ageInDays' => round((time() - ($comparisonPageData['content_modified_at'] ?? time())) / (24 * 3600), 1),
                        ];
                        
                        $similarityCalculations++;
                    }
                }
            }
    
            $result = [
                'results' => $analysisResults,
                'metrics' => [
                    'executionTime' => microtime(true) - $startTime,
                    'totalPages' => $totalPages,
                    'similarityCalculations' => $similarityCalculations,
                    'fromCache' => false,
                ],
            ];
    
            $this->cache->set(
                $cacheIdentifier,
                $result,
                ['tx_semanticsuggestion', "pages_{$parentPageId}"],
                86400
            );
    
            $this->logDebug('Analysis complete', [
                'executionTime' => microtime(true) - $startTime,
                'totalPages' => $totalPages,
                'similarityCalculations' => $similarityCalculations
            ]);
    
            return $result;
    
        } catch (\Exception $e) {
            $this->logger?->error('Error during page analysis', ['exception' => $e->getMessage()]);
            return [
                'results' => [],
                'metrics' => [
                    'executionTime' => microtime(true) - $startTime,
                    'totalPages' => 0,
                    'similarityCalculations' => 0,
                    'fromCache' => false,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }
    


    private function calculateDepth(array $pages): int
    {
        $maxDepth = 0;
        $pagesByUid = [];
    
        // Première passe : indexer les pages par leur UID
        foreach ($pages as $page) {
            if (isset($page['uid'])) {
                $pagesByUid[$page['uid']] = $page;
            }
        }
    
        // Deuxième passe : calculer la profondeur
        foreach ($pages as $page) {
            $depth = 1;
            $currentPage = $page;
            while (isset($currentPage['pid']) && $currentPage['pid'] !== 0 && isset($pagesByUid[$currentPage['pid']])) {
                $depth++;
                $currentPage = $pagesByUid[$currentPage['pid']];
            }
            $maxDepth = max($maxDepth, $depth);
        }
    
        return $maxDepth;
    }


    protected function preparePageData(array $page, int $currentLanguageUid): array
    {
        $preparedData = [
            'uid' => $page['uid'],
            'sys_language_uid' => $page['sys_language_uid'] ?? 0,
            'isTranslation' => isset($page['_PAGES_OVERLAY']),
        ];
    
        $language = $this->getCurrentLanguage();
    
        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            $originalContent = $page[$field] ?? '';
    
            if ($field === 'content' && empty($originalContent)) {
                try {
                    $originalContent = $this->getPageContent($page['uid'], $currentLanguageUid);
                } catch (\Exception $e) {
                    $this->logger->error('Error fetching page content', [
                        'pageId' => $page['uid'], 
                        'language' => $currentLanguageUid, 
                        'exception' => $e->getMessage()
                    ]);
                    $originalContent = '';
                }
            }
    
            if (!empty($originalContent) && is_string($originalContent)) {

                
                $processedContent = $this->stopWordsService->removeStopWords($originalContent, $language);

                
                $preparedData[$field] = [
                    'content' => $processedContent,
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

        $this->logDebug('Page data prepared', [
            'pageUid' => $page['uid'],
            'language' => $currentLanguageUid,
            'fieldsProcessed' => array_keys($this->settings['analyzedFields']),
            'contentLength' => strlen($preparedData['content']['content'] ?? '')
        ]);
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

    protected function getSubpages(int $parentId, string $languageCode): array
    {
        $this->logger?->info('Fetching subpages', ['parentId' => $parentId, 'languageCode' => $languageCode]);

        try {
            $queryBuilder = $this->getQueryBuilder();

            $languageAspect = $this->context->getAspect('language');
            $languageId = $languageAspect->getId();

            $fieldsToSelect = ['uid', 'title', 'description', 'keywords', 'abstract', 'crdate', 'sys_language_uid'];
            $tableColumns = $queryBuilder->getConnection()->getSchemaManager()->listTableColumns('pages');
            $existingColumns = array_keys($tableColumns);
            $fieldsToSelect = array_intersect($fieldsToSelect, $existingColumns);

          $this->logDebug('Fields to select', ['fields' => $fieldsToSelect]);

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
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, \PDO::PARAM_INT))
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($result as &$page) {
                $page['content_modified_at'] = $page['MAX(tstamp)'] ?? $page['crdate'] ?? time();
                unset($page['MAX(tstamp)']);
            }

            $this->logger?->info('Subpages fetched successfully', ['count' => count($result), 'languageCode' => $languageCode]);
          $this->logDebug('Fetched subpages', ['subpages' => $result]);

            return $result;
        } catch (\Exception $e) {
            $this->logger?->error('Error fetching subpages', ['exception' => $e->getMessage(), 'parentId' => $parentId, 'languageCode' => $languageCode]);
            throw $e; 
        }
    }

    protected function getPageContent(int $pageId, int $languageUid = 0): string
    {
        try {
            $queryBuilder = $this->getQueryBuilder('tt_content');
    
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

    protected function getWeightedWords(array $pageData): array
    {
        $weightedWords = [];
        $language = $this->getCurrentLanguage();
    
        if ($this->settings['debugMode']) {
            $this->logDebug('Starting getWeightedWords', ['pageData' => $pageData, 'language' => $language]);
        }
    
        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            if (!isset($pageData[$field]['content']) || !is_string($pageData[$field]['content'])) {
                if ($this->settings['debugMode']) {
                    $this->logger->warning('Invalid or missing field data', ['field' => $field]);
                }
                continue;
            }
    
            $content = $pageData[$field]['content'];
            
            if ($this->settings['debugMode']) {
                $this->logDebug('Content for field', ['field' => $field, 'content' => $content]);
            }
    
            $words = array_count_values(str_word_count(strtolower($content), 1));
            
            if ($this->settings['debugMode']) {
                $this->logDebug('Word count', ['field' => $field, 'words' => $words]);
            }
    
            foreach ($words as $word => $count) {
                $weightedWords[$word] = ($weightedWords[$word] ?? 0) + ($count * $weight);
            }
        }
    
        if ($this->settings['debugMode']) {
            $this->logDebug('Final weighted words result', ['weightedWords' => $weightedWords]);
        }
    
        return $weightedWords;
    }



    private function calculateSimilarity(array $page1, array $page2): array
    {
        if ($this->useNlpApi && $this->nlpApiService !== null) {
            return $this->calculateSimilarityWithApi($page1, $page2);
        }

        $words1 = $this->getWeightedWords($page1);
        $words2 = $this->getWeightedWords($page2);
    
        if (empty($words1) || empty($words2)) {
            $this->logger?->warning('One or both pages have no weighted words', [
                'page1' => $page1['uid'] ?? 'unknown',
                'page2' => $page2['uid'] ?? 'unknown'
            ]);
            return [
                'semanticSimilarity' => 0.0,
                'recencyBoost' => 0.0,
                'finalSimilarity' => 0.0
            ];
        }
    
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
            $this->logger?->warning('Zero magnitude detected', [
                'magnitude1' => $magnitude1,
                'magnitude2' => $magnitude2
            ]);
            return [
                'semanticSimilarity' => 0.0,
                'recencyBoost' => 0.0,
                'finalSimilarity' => 0.0
            ];
        }
    
        $semanticSimilarity = $dotProduct / ($magnitude1 * $magnitude2);
    
        $recencyBoost = $this->calculateRecencyBoost($page1, $page2);
    
        $recencyWeight = $this->settings['recencyWeight'] ?? 0.2;
    
        $finalSimilarity = ($semanticSimilarity * (1 - $recencyWeight)) + ($recencyBoost * $recencyWeight);
    
        $fieldScores = [
            'title' => $this->calculateFieldSimilarity($page1['title'] ?? [], $page2['title'] ?? []),
            'description' => $this->calculateFieldSimilarity($page1['description'] ?? [], $page2['description'] ?? []),
            'keywords' => $this->calculateFieldSimilarity($page1['keywords'] ?? [], $page2['keywords'] ?? []),
            'content' => $this->calculateFieldSimilarity($page1['content'] ?? [], $page2['content'] ?? []),
        ];
  
        $this->logDebug('Similarity calculated', [
            'page1' => $page1['uid'],
            'page2' => $page2['uid'],
            'semanticSimilarity' => $semanticSimilarity,
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => $finalSimilarity
        ]);

        return [
            'semanticSimilarity' => $semanticSimilarity,
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => min($finalSimilarity, 1.0)
        ];
    }


    private function calculateSimilarityWithApi(array $page1, array $page2): array
    {
        $text1 = $this->prepareTextForApi($page1);
        $text2 = $this->prepareTextForApi($page2);

        $similarity = $this->nlpApiService->getSimilarity($text1, $text2);

        $recencyBoost = $this->calculateRecencyBoost($page1, $page2);
        $finalSimilarity = ($similarity * (1 - $this->settings['recencyWeight'])) + ($recencyBoost * $this->settings['recencyWeight']);

        $this->logDebug('API Similarity calculated', [
            'page1' => $page1['uid'],
            'page2' => $page2['uid'],
            'similarity' => $similarity,
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => $finalSimilarity
        ]);

        return [
            'semanticSimilarity' => $similarity,
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => min($finalSimilarity, 1.0)
        ];
    }

    private function prepareTextForApi(array $page): string
    {
        $content = '';
        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            if (isset($page[$field]['content'])) {
                $content .= $page[$field]['content'] . ' ';
            }
        }
        return trim($content);
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

private function findCommonKeywords(array $page1, array $page2): array
{
    $keywords1 = isset($page1['keywords']['content']) ? array_map('trim', explode(',', strtolower($page1['keywords']['content']))) : [];
    $keywords2 = isset($page2['keywords']['content']) ? array_map('trim', explode(',', strtolower($page2['keywords']['content']))) : [];

    $commonKeywords = array_intersect($keywords1, $keywords2);

    $this->logDebug('Common keywords found', [
        'page1' => $page1['uid'] ?? 'unknown',
        'page2' => $page2['uid'] ?? 'unknown',
        'keywords1' => $keywords1,
        'keywords2' => $keywords2,
        'commonKeywords' => $commonKeywords
    ]);

    return $commonKeywords;
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


}