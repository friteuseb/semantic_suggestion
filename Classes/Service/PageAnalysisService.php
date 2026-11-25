<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\LanguageAspect;
use Psr\Log\NullLogger;
use Cywolf\NlpTools\Service\LanguageDetectionService;
use Cywolf\NlpTools\Service\TextAnalysisService;
use Cywolf\NlpTools\Service\TextVectorizerService;
use TalanHdf\SemanticSuggestion\Embedding\EmbeddingException;
use TalanHdf\SemanticSuggestion\Embedding\EmbeddingSimilarity;
use TalanHdf\SemanticSuggestion\Embedding\PageEmbeddingService;
use TalanHdf\SemanticSuggestion\Service\SiteLanguageService;

class PageAnalysisService implements LoggerAwareInterface
{
    /**
     * Age at which a suggested page no longer gets any recency boost.
     */
    public const RECENCY_WINDOW_DAYS = 30;

    use LoggerAwareTrait;

    protected Context $context;
    protected ConfigurationManagerInterface $configurationManager;
    protected array $settings;
    protected ?CacheManager $cacheManager;
    protected ConnectionPool $connectionPool;
    protected ?QueryBuilder $queryBuilder = null;
    protected SiteFinder $siteFinder;
    protected FrontendInterface $cache;
    protected ?LanguageDetectionService $languageDetector = null;
    protected ?TextAnalysisService $textAnalyzer = null;
    protected ?TextVectorizerService $textVectorizer = null;
    protected ?SiteLanguageService $siteLanguageService;
    protected ?PageEmbeddingService $pageEmbeddingService = null;
    protected EmbeddingSimilarity $embeddingSimilarity;

    /**
     * Set by computeEmbeddingScores() when the API failed during the current run.
     */
    private bool $embeddingApiFailed = false;

    /**
     * Resolved language code per "<pageUid>_<languageUid>", see resolvePageLanguage().
     *
     * @var array<string, string>
     */
    protected array $pageLanguageCache = [];

    public function __construct(
        Context $context,
        ConfigurationManagerInterface $configurationManager,
        SiteFinder $siteFinder,
        ?CacheManager $cacheManager = null,
        ?ConnectionPool $connectionPool = null,
        ?LoggerInterface $logger = null,
        ?SiteLanguageService $siteLanguageService = null,
        ?LanguageDetectionService $languageDetector = null,
        ?TextAnalysisService $textAnalyzer = null,
        ?TextVectorizerService $textVectorizer = null,
        ?PageEmbeddingService $pageEmbeddingService = null,
        ?EmbeddingSimilarity $embeddingSimilarity = null
    ) {
        $this->context = $context;
        $this->configurationManager = $configurationManager;
        $this->siteFinder = $siteFinder;
        $this->siteLanguageService = $siteLanguageService ?? GeneralUtility::makeInstance(SiteLanguageService::class);
        $this->cacheManager = $cacheManager;
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->logger = $logger ?? new NullLogger();
        
        if ($logger !== null) {
            $this->setLogger($logger);
        }

        $this->settings = $this->resolveSettings();

        $this->initializeSettings();
        $this->initializeCache();
        
        // Initialize nlp_tools services AFTER settings are initialized
        $this->initializeNlpServices($languageDetector, $textAnalyzer, $textVectorizer);

        $this->embeddingSimilarity = $embeddingSimilarity ?? new EmbeddingSimilarity();
        try {
            $this->pageEmbeddingService = $pageEmbeddingService ?? GeneralUtility::makeInstance(PageEmbeddingService::class);
        } catch (\Throwable $e) {
            // Without it the analysis simply stays on TF-IDF.
            $this->logWarning('Embedding service unavailable, using TF-IDF', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * True when an embedding API is configured. The analysis may still fall back
     * to TF-IDF for a given run, see computeEmbeddingScores().
     */
    protected function isEmbeddingEnabled(): bool
    {
        return $this->pageEmbeddingService !== null && $this->pageEmbeddingService->isEnabled();
    }

    protected function initializeNlpServices(
        ?LanguageDetectionService $languageDetector = null,
        ?TextAnalysisService $textAnalyzer = null,
        ?TextVectorizerService $textVectorizer = null
    ): void {
        try {
            // Try to use injected services first, fallback to manual instantiation
            $this->languageDetector = $languageDetector ?? GeneralUtility::makeInstance(LanguageDetectionService::class);
            $this->textAnalyzer = $textAnalyzer ?? GeneralUtility::makeInstance(TextAnalysisService::class);  
            $this->textVectorizer = $textVectorizer ?? GeneralUtility::makeInstance(TextVectorizerService::class);
            
            // Inject cache into nlp_tools services for better performance
            if ($this->cache) {
                if (method_exists($this->textAnalyzer, 'setCache')) {
                    $this->textAnalyzer->setCache($this->cache);
                }
                if (method_exists($this->textVectorizer, 'setCache')) {
                    $this->textVectorizer->setCache($this->cache);
                }
            }
            
            $this->logDebug('nlp_tools services initialized successfully');
        } catch (\Exception $e) {
            $this->logError('Failed to initialize nlp_tools services', ['exception' => $e->getMessage()]);
            
            // Create null implementations to prevent crashes
            $this->languageDetector = $this->createNullLanguageDetector();
            $this->textAnalyzer = $this->createNullTextAnalyzer(); 
            $this->textVectorizer = $this->createNullTextVectorizer();
        }
    }

    private function createNullLanguageDetector(): object
    {
        return new class {
            public function detectLanguage(string $text): string {
                return 'en'; // Default fallback
            }
        };
    }

    private function createNullTextAnalyzer(): object  
    {
        return new class {
            public function removeStopWords(string $text, ?string $language = null): string {
                return $text; // No processing
            }
            public function stem(string $text, ?string $language = null): array {
                return explode(' ', $text); // Basic tokenization
            }
        };
    }

    private function createNullTextVectorizer(): object
    {
        return new class {
            public function createTfIdfVectors(array $texts, ?string $language = null): array {
                return ['vectors' => [], 'vocabulary' => []]; // Empty result
            }
            public function cosineSimilarity(array $vector1, array $vector2): float {
                return 0.0; // No similarity
            }
        };
    }

    private function logDebug(string $message, array $context = []): void
    {
        if (isset($this->settings['debugMode']) && $this->settings['debugMode']) {
            $this->logger->debug($message, $context);
        }
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

    /**
     * TypoScript settings, or the best available substitute.
     *
     * The Extbase ConfigurationManager requires a server request, which does not
     * exist when the scheduler runs from cron or `scheduler:execute`. Calling it
     * unguarded made the task throw NoServerRequestGivenException on every CLI
     * run while it worked from the backend "Execute now" button (#14).
     *
     * Falling back is safe: initializeSettings() supplies defaults for every value
     * the analysis actually needs, so an empty array yields a working task rather
     * than a broken one. Extension configuration is tried first because, unlike
     * TypoScript, it is request-independent and may carry the integrator's values.
     */
    protected function resolveSettings(): array
    {
        try {
            $settings = $this->configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
                'semanticsuggestion_suggestions'
            );

            if (is_array($settings) && $settings !== []) {
                return $settings;
            }
        } catch (\Throwable $e) {
            $this->logger?->info(
                'TypoScript settings unavailable, falling back to extension configuration and defaults',
                ['reason' => $e->getMessage()]
            );
        }

        try {
            $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('semantic_suggestion');

            if (is_array($extensionConfiguration['settings'] ?? null)) {
                return $extensionConfiguration['settings'];
            }
        } catch (\Throwable $e) {
            // No extension configuration either; defaults below cover it.
        }

        return [];
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
        // Premier essai: Configuration TYPO3 Site via SiteLanguageService
        if ($this->siteLanguageService !== null) {
            try {
                $languageAspect = $this->context->getAspect('language');
                $languageId = $languageAspect->getId();
                $currentPageId = $this->getCurrentPageId();
                
                $siteLanguageCode = $this->siteLanguageService->getLanguageCodeByUid($languageId, $currentPageId);
                if ($siteLanguageCode !== null) {
                    $this->logDebug('Language detected from TYPO3 site configuration', [
                        'languageId' => $languageId,
                        'pageId' => $currentPageId,
                        'detectedCode' => $siteLanguageCode
                    ]);
                    return $siteLanguageCode;
                }
            } catch (\Exception $e) {
                $this->logWarning('Error detecting language from site configuration', ['exception' => $e->getMessage()]);
            }
        }
        
        // Deuxième essai: Détection via nlp_tools avec contenu exemple
        try {
            // Utiliser un contenu vide pour détecter la langue par défaut
            $detectedLanguage = $this->languageDetector->detectLanguage('');
            $this->logDebug('Language detected via nlp_tools fallback', ['language' => $detectedLanguage]);
            return $detectedLanguage;
        } catch (\Exception $e) {
            $this->logWarning('Error detecting language via nlp_tools', ['exception' => $e->getMessage()]);
        }
        
        // Troisième essai: TypoScript mapping (deprecated mais gardé comme fallback)
        try {
            $languageAspect = $this->context->getAspect('language');
            $languageId = $languageAspect->getId();
            
            $typoscriptLanguage = $this->getLanguageFromTypoScript($languageId);
            if ($typoscriptLanguage !== null) {
                $this->logDebug('Language detected from TypoScript mapping', [
                    'languageId' => $languageId,
                    'detectedCode' => $typoscriptLanguage
                ]);
                return $typoscriptLanguage;
            }
        } catch (\Exception $e) {
            $this->logWarning('Error detecting language from TypoScript', ['exception' => $e->getMessage()]);
        }
        
        // Fallback final
        $defaultLanguage = $this->settings['defaultLanguage'] ?? 'en';
        $this->logDebug('Using default language fallback', ['defaultLanguage' => $defaultLanguage]);
        return $defaultLanguage;
    }

    protected function getCurrentPageId(): ?int
    {
        // Vérifier si on est dans un contexte backend/scheduler
        $isBackendContext = $this->isBackendContext();
        
        // Méthode 1: Via ServerRequest et PageArguments (frontend)
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $pageArguments = $request->getAttribute('routing');
            if ($pageArguments instanceof PageArguments) {
                $pageId = $pageArguments->getPageId();
                $this->logger?->debug('Page ID found via PageArguments', ['pageId' => $pageId]);
                return $pageId;
            }
            
            // Méthode 2: Via paramètres de requête (backend)
            $pageId = $request->getQueryParams()['id'] ?? null;
            if ($pageId !== null) {
                $this->logger?->debug('Page ID found via query params', ['pageId' => (int)$pageId]);
                return (int)$pageId;
            }
        }
        
        // Méthode 3: Via TSFE (frontend fallback)
        if (isset($GLOBALS['TSFE']) && $GLOBALS['TSFE']->id) {
            $pageId = (int)$GLOBALS['TSFE']->id;
            $this->logger?->debug('Page ID found via TSFE', ['pageId' => $pageId]);
            return $pageId;
        }
        
        // Méthode 4: Via Context (nouveau dans TYPO3 v12+)
        try {
            $pageId = $this->context->getPropertyFromAspect('page', 'id', 0);
            if ($pageId > 0) {
                $this->logger?->debug('Page ID found via Context', ['pageId' => $pageId]);
                return $pageId;
            }
        } catch (\Exception $e) {
            // Ne pas logger cette erreur si on est en contexte backend
            if (!$isBackendContext) {
                $this->logger?->debug('Could not get page ID via Context', ['exception' => $e->getMessage()]);
            }
        }
        
        // Méthode 5: Via $_GET (dernier recours)
        $pageId = (int)($_GET['id'] ?? 0);
        if ($pageId > 0) {
            $this->logger?->debug('Page ID found via $_GET', ['pageId' => $pageId]);
            return $pageId;
        }
        
        // Log seulement si ce n'est pas un contexte backend normal
        if (!$isBackendContext) {
            $this->logger?->warning('Unable to determine current page ID', [
                'has_typo3_request' => isset($GLOBALS['TYPO3_REQUEST']),
                'has_tsfe' => isset($GLOBALS['TSFE']),
                'tsfe_id' => $GLOBALS['TSFE']->id ?? 'not_set',
                'get_id' => $_GET['id'] ?? 'not_set',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not_set'
            ]);
        }
        
        return null;
    }

    protected function isBackendContext(): bool
    {
        // CLI
        if (php_sapi_name() === 'cli') {
            return true;
        }
        
        // Modules backend
        if (isset($_SERVER['REQUEST_URI']) && 
            strpos($_SERVER['REQUEST_URI'], '/typo3/module/') !== false) {
            return true;
        }
        
        // Backend général
        if (isset($GLOBALS['TYPO3_REQUEST']) && 
            $GLOBALS['TYPO3_REQUEST']->getAttribute('applicationType') === 'BE') {
            return true;
        }
        
        return false;
    }

    protected function detectLanguageAutomatically(int $languageId): ?string
    {
        try {
            $currentPageId = $this->getCurrentPageId();
            if ($currentPageId === null) {
                // Ne pas logger comme warning si on est en contexte backend
                if (!$this->isBackendContext()) {
                    $this->logger?->debug('Unable to determine current page ID for language detection - using fallback');
                }
                
                // Fallback: essayer de détecter la langue via d'autres moyens
                if ($languageId === 0) {
                    return 'en'; // Langue par défaut
                }
                
                // Via Context language aspect
                try {
                    $languageAspect = $this->context->getAspect('language');
                    $locale = $languageAspect->get('locale');
                    if ($locale && is_string($locale)) {
                        return strtolower(substr($locale, 0, 2));
                    }
                } catch (\Exception $e) {
                    // Pas de log en contexte backend
                    if (!$this->isBackendContext()) {
                        $this->logger?->debug('Could not get language from Context', ['exception' => $e->getMessage()]);
                    }
                }
                
                return null;
            }

            $currentSite = $this->siteFinder->getSiteByPageId($currentPageId);
            $siteLanguage = $currentSite->getLanguageById($languageId);
            if ($siteLanguage) {
                return strtolower(substr($siteLanguage->getHreflang(), 0, 2));
            }
        } catch (\Exception $e) {
            $this->logger?->debug('Failed to detect language automatically', ['exception' => $e->getMessage()]);
        }
        
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

    /**
     * Resolve the site root page UID a page belongs to.
     *
     * Used to scope the analysis cache per site: without it, cache entries carry no
     * site information and per-site invalidation cannot target them.
     *
     * Returns 0 when the page is outside any configured site (page tree not attached
     * to a site, or a fixture in tests). A 0 root simply means "unscoped" and stays
     * consistent between the cache identifier and the cache tag.
     */
    protected function resolveRootPageId(array $page): int
    {
        $pageId = (int)($page['uid'] ?? $page['pid'] ?? 0);
        if ($pageId <= 0) {
            return 0;
        }

        try {
            return $this->siteFinder->getSiteByPageId($pageId)->getRootPageId();
        } catch (\Exception $e) {
            $this->logger?->debug('Could not resolve site root for page', [
                'pageId' => $pageId,
                'exception' => $e->getMessage(),
            ]);
            return 0;
        }
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
    
        $this->logDebug('Starting page analysis', [
            'pageCount' => count($pages),
            'languageUid' => $currentLanguageUid,
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
        $rootPageId = $this->resolveRootPageId($firstPage);
        // Keyed on the language UID being analysed, not on a language code derived from
        // the request: under a CLI run that code is the same for every language of the
        // site, so a multi-language run would serve the first language's results to all
        // the others.
        $cacheIdentifier = "semantic_analysis_{$rootPageId}_{$parentPageId}_{$depth}_lang{$currentLanguageUid}";
        if ($this->isEmbeddingEnabled()) {
            // Switching provider or model must not serve the previous scores.
            $cacheIdentifier .= '_' . substr(md5($this->pageEmbeddingService->getConfiguration()->getIdentifier()), 0, 8);
        }
    
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
    
            // Issue #15: compute TF-IDF vectors a single time per page (grouped by
            // language) up front, then reuse them for every pairwise comparison
            // instead of rebuilding the vectors on each comparison.
            $vectorData = $this->precomputePageVectors($analysisResults);
            $pageLanguages = $vectorData['languages'];
            $pageVectors = $vectorData['vectors'];

            // Null when embeddings are off, or when this run fell back to TF-IDF.
            $embeddingScores = $this->computeEmbeddingScores($analysisResults);
            $similarityMethod = $embeddingScores !== null
                ? $this->pageEmbeddingService->getConfiguration()->getIdentifier()
                : 'tfidf';
            foreach ($analysisResults as &$pageData) {
                unset($pageData['embeddingText']);
            }
            unset($pageData);

            $similarityCalculations = 0;
            foreach ($analysisResults as $pageId => &$pageData) {
                foreach ($analysisResults as $comparisonPageId => $comparisonPageData) {
                    if ($pageId !== $comparisonPageId) {
                        $similarity = $embeddingScores !== null
                            ? $this->calculateSimilarityFromEmbeddings(
                                $comparisonPageData,
                                $pageData['detectedLanguage'] ?? null,
                                $comparisonPageData['detectedLanguage'] ?? null,
                                $embeddingScores[$pageId][$comparisonPageId] ?? 0.0
                            )
                            : $this->calculateSimilarityFromVectors(
                                $pageData,
                                $comparisonPageData,
                                $pageLanguages[$pageId] ?? null,
                                $pageLanguages[$comparisonPageId] ?? null,
                                $pageVectors[$pageId] ?? null,
                                $pageVectors[$comparisonPageId] ?? null
                            );

                        // Ne pas ajouter les similarités à 0 (langues incompatibles)
                        if ($similarity['finalSimilarity'] > 0) {
                            $pageData['similarities'][$comparisonPageId] = [
                                'score' => $similarity['finalSimilarity'],
                                'semanticSimilarity' => $similarity['semanticSimilarity'],
                                'recencyBoost' => $similarity['recencyBoost'],
                                'commonKeywords' => $this->findCommonKeywords($pageData, $comparisonPageData),
                                'relevance' => $this->determineRelevance($similarity['finalSimilarity']),
                                'ageInDays' => round((time() - ($comparisonPageData['content_modified_at'] ?? time())) / (24 * 3600), 1),
                            ];
                        }

                        $similarityCalculations++;
                    }
                }
            }
            unset($pageData);
    
            $result = [
                'results' => $analysisResults,
                'metrics' => [
                    'executionTime' => microtime(true) - $startTime,
                    'totalPages' => $totalPages,
                    'similarityCalculations' => $similarityCalculations,
                    'similarityMethod' => $similarityMethod,
                    'fromCache' => false,
                ],
            ];

            // A run where the API failed is not cached under the embedding key: the
            // next run must try the API again.
            if (!$this->embeddingApiFailed) {
                // site_<rootPageId> is what GenerateSimilaritiesTask and the DataHandler
                // hook flush; without it, per-site invalidation silently matches nothing.
                $this->cache->set(
                    $cacheIdentifier,
                    $result,
                    ['tx_semanticsuggestion', "pages_{$parentPageId}", "site_{$rootPageId}"],
                    86400
                );
            }
    
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


    /**
     * Language code to process a single page with.
     *
     * Derived from the page and from the language being analysed, never from the
     * current request. getCurrentLanguage() reads the request context, which does not
     * exist under `scheduler:run`: it then fell back to 'en' for every page, so a
     * German site analysed from cron had its stop words left in place and the English
     * stemmer applied to German text (#25).
     *
     * @param array $page Raw page record, as fetched by the caller
     * @param int $languageUid Language being analysed; -1 means "take it from the record"
     */
    protected function resolvePageLanguage(array $page, int $languageUid): string
    {
        $pageId = (int)($page['uid'] ?? 0);
        $languageUid = $languageUid >= 0 ? $languageUid : (int)($page['sys_language_uid'] ?? 0);

        $cacheKey = $pageId . '_' . $languageUid;
        if (isset($this->pageLanguageCache[$cacheKey])) {
            return $this->pageLanguageCache[$cacheKey];
        }

        $language = null;

        // 1. The site configuration of the page: the locale of that language.
        if ($this->siteLanguageService !== null && $pageId > 0) {
            $language = $this->siteLanguageService->getLanguageCodeByUid($languageUid, $pageId);
        }

        // 2. Content analysis, for a page that belongs to no configured site.
        if ($language === null) {
            $text = $this->extractTextForLanguageDetection($page);

            if ($text !== '') {
                try {
                    $language = $this->languageDetector->detectLanguage($text);
                } catch (\Throwable $e) {
                    $this->logWarning('Content language detection failed', [
                        'pageId' => $pageId,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 3. Configured fallback.
        if ($language === null || $language === '') {
            $language = (string)($this->settings['defaultLanguage'] ?? 'en');
        }

        $this->logDebug('Page language resolved', [
            'pageId' => $pageId,
            'languageUid' => $languageUid,
            'language' => $language,
        ]);

        $this->pageLanguageCache[$cacheKey] = $language;

        return $language;
    }

    /**
     * Plain text of a raw page record, for content-based language detection.
     */
    private function extractTextForLanguageDetection(array $page): string
    {
        $parts = [];

        foreach (['title', 'description', 'keywords', 'abstract'] as $field) {
            $value = $page[$field] ?? '';
            if (is_string($value) && $value !== '') {
                $parts[] = strip_tags($value);
            }
        }

        return trim(implode(' ', $parts));
    }

    protected function preparePageData(array $page, int $currentLanguageUid): array
    {
        // Resolved from the page itself, and carried in the prepared data so that every
        // later step — vectorisation, pairwise comparison — uses the same language as
        // the stop word removal and stemming below.
        $language = $this->resolvePageLanguage($page, $currentLanguageUid);

        $preparedData = [
            'uid' => $page['uid'],
            'sys_language_uid' => $page['sys_language_uid'] ?? 0,
            'isTranslation' => isset($page['_PAGES_OVERLAY']) || isset($page['_LOCALIZED_UID']),
            'detectedLanguage' => $language,
        ];

        // Embeddings read the text as written: stop word removal and stemming would
        // take away what the model needs to understand it.
        $collectEmbeddingText = $this->isEmbeddingEnabled();
        $embeddingTexts = [];

        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            if ((float)$weight <= 0) {
                // Disabled field: not fetched, not processed, not compared.
                continue;
            }

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
                if ($collectEmbeddingText) {
                    $embeddingTexts[] = $this->cleanTextForEmbedding($originalContent);
                }

                // Utiliser le service TextAnalysisService de nlp_tools pour un traitement avancé
                $startTime = microtime(true);
                try {
                    $processedContent = $this->textAnalyzer->removeStopWords($originalContent, $language);

                    // Optionnel: appliquer le stemming pour de meilleurs résultats
                    if ($this->settings['enableStemming'] ?? true) {
                        $stemmedWords = $this->textAnalyzer->stem($processedContent, $language);
                        $processedContent = implode(' ', $stemmedWords);
                    }
                    $processingTime = microtime(true) - $startTime;
                    $this->logDebug('Text processing completed', [
                        'pageId' => $page['uid'] ?? 'unknown',
                        'processingTime' => round($processingTime, 3),
                        'originalLength' => mb_strlen($originalContent),
                        'processedLength' => mb_strlen($processedContent)
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('Error in nlp_tools text processing, falling back to legacy service', [
                        'pageId' => $page['uid'] ?? 'unknown',
                        'language' => $language,
                        'originalLength' => mb_strlen($originalContent),
                        'exception' => $e->getMessage()
                    ]);
                    // Fallback: utiliser textAnalyzer de base sans stemming
                    $processedContent = $this->textAnalyzer->removeStopWords($originalContent, $language);
                }

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
        if ($collectEmbeddingText) {
            // Fields in analyzedFields order, title first by default: when the text
            // is cut at EmbeddingClient::MAX_TEXT_CHARACTERS, the end of the body goes.
            $preparedData['embeddingText'] = trim(implode("\n", array_filter($embeddingTexts)));
        }

        $this->logDebug('Page data prepared', [
            'pageUid' => $page['uid'],
            'languageUid' => $currentLanguageUid,
            'language' => $language,
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
            $tableColumns = $queryBuilder->getConnection()->createSchemaManager()->listTableColumns('pages');
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
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentId, \Doctrine\DBAL\ParameterType::INTEGER)), // MODIFIÉ
                    $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)), // MODIFIÉ
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)), // MODIFIÉ
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, \Doctrine\DBAL\ParameterType::INTEGER)) // MODIFIÉ
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
                    $queryBuilder->expr()->eq('tt_content.pid', $queryBuilder->createNamedParameter($pageId, \Doctrine\DBAL\ParameterType::INTEGER)), // MODIFIÉ
                    $queryBuilder->expr()->eq('tt_content.hidden', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),      // MODIFIÉ
                    $queryBuilder->expr()->eq('tt_content.deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),     // MODIFIÉ
                    $queryBuilder->expr()->eq('tt_content.sys_language_uid', $queryBuilder->createNamedParameter($languageUid, \Doctrine\DBAL\ParameterType::INTEGER)) // MODIFIÉ
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
        $language = $pageData['detectedLanguage'] ?? $this->getCurrentLanguage();

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



    /**
     * Language of an already prepared page whose data does not carry one.
     *
     * Only a safety net for prepared data produced outside preparePageData(): the raw
     * page record is gone at this point, so the site configuration cannot be consulted
     * and only content analysis is left.
     */
    private function detectLanguageOfPreparedPage(array $preparedPage): string
    {
        $text = $this->prepareTextForAnalysis($preparedPage);

        if ($text !== '') {
            try {
                return $this->languageDetector->detectLanguage($text);
            } catch (\Throwable $e) {
                $this->logWarning('Content language detection failed', [
                    'pageId' => $preparedPage['uid'] ?? 'unknown',
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return (string)($this->settings['defaultLanguage'] ?? 'en');
    }

    /**
     * Whether two pages may be compared at all.
     */
    private function areLanguagesCompatible(?string $lang1, ?string $lang2): bool
    {
        if ($lang1 === null || $lang2 === null) {
            return false;
        }

        if ($this->siteLanguageService !== null) {
            return $this->siteLanguageService->areLanguagesCompatible($lang1, $lang2);
        }

        return $lang1 === $lang2;
    }

    private function calculateSimilarity(array $page1, array $page2): array
    {
        // Languages carried by preparePageData(), which resolved them from the pages
        // themselves. Falling back to detection here only covers prepared data coming
        // from somewhere else.
        $lang1 = $page1['detectedLanguage'] ?? $this->detectLanguageOfPreparedPage($page1);
        $lang2 = $page2['detectedLanguage'] ?? $this->detectLanguageOfPreparedPage($page2);

        if (!$this->areLanguagesCompatible($lang1, $lang2)) {
            $this->logDebug('Languages not compatible, skipping similarity calculation', [
                'page1' => $page1['uid'] ?? 'unknown',
                'page2' => $page2['uid'] ?? 'unknown',
                'lang1' => $lang1,
                'lang2' => $lang2
            ]);
            return [
                'semanticSimilarity' => 0.0,
                'recencyBoost' => 0.0,
                'finalSimilarity' => 0.0
            ];
        }

        try {
            // Two pages from different language corpora: their pair gets its own
            // two-document corpus, as before. Same builder as the batch path, so the
            // field weights mean the same thing in both.
            $vectors = $this->buildTfIdfVectors([$page1, $page2]);

            if ($vectors[0] === null || $vectors[1] === null) {
                $this->logger?->warning('One or both pages have no text content', [
                    'page1' => $page1['uid'] ?? 'unknown',
                    'page2' => $page2['uid'] ?? 'unknown'
                ]);
                return [
                    'semanticSimilarity' => 0.0,
                    'recencyBoost' => 0.0,
                    'finalSimilarity' => 0.0
                ];
            }

            $result = $this->combineScores($this->cosineSimilarity($vectors[0], $vectors[1]), $page2);

            $this->logDebug('TF-IDF similarity calculated', [
                'page1' => $page1['uid'] ?? 'unknown',
                'page2' => $page2['uid'] ?? 'unknown',
                'language' => $lang1,
            ] + $result);

            return $result;

        } catch (\Exception $e) {
            $this->logError('Error in TF-IDF similarity calculation', [
                'page1' => $page1['uid'] ?? 'unknown',
                'page2' => $page2['uid'] ?? 'unknown',
                'exception' => $e->getMessage()
            ]);
            
            // Fallback vers l'ancien calcul en cas d'erreur
            return $this->calculateSimilarityFallback($page1, $page2);
        }
    }

    /**
     * Issue #15: Build the TF-IDF vector for every page exactly once. Pages are
     * grouped by their detected language so each language shares a single
     * vocabulary/IDF corpus, and the resulting vector for each page id is returned
     * for reuse across all pairwise comparisons.
     *
     * @param array<int|string, array> $analysisResults
     * @return array{languages: array<int|string, ?string>, vectors: array<int|string, ?array>}
     */
    private function precomputePageVectors(array $analysisResults): array
    {
        $languages = [];
        $vectors = [];
        $pagesByLanguage = [];

        foreach ($analysisResults as $pageId => $pageData) {
            // Resolved once per page by preparePageData(), from the page record itself.
            $language = $pageData['detectedLanguage'] ?? $this->detectLanguageOfPreparedPage($pageData);

            $languages[$pageId] = $language;
            $vectors[$pageId] = null;
            $pagesByLanguage[$language][$pageId] = $pageData;
        }

        foreach ($pagesByLanguage as $language => $pages) {
            $groupVectors = $this->buildTfIdfVectors(array_values($pages));
            foreach (array_keys($pages) as $index => $pageId) {
                $vectors[$pageId] = $groupVectors[$index];
                if ($vectors[$pageId] === null) {
                    // No text in any weighted field: the page cannot be compared.
                    $languages[$pageId] = null;
                }
            }
        }

        return ['languages' => $languages, 'vectors' => $vectors];
    }

    /**
     * Calibrated embedding score of every pair, or null when this run must use
     * TF-IDF: embeddings off, fewer than EmbeddingSimilarity::MINIMUM_PAGES pages
     * with text, or an API error. The whole run then uses TF-IDF, so that scores
     * of one site never mix the two scales.
     *
     * @param array<int|string, array> $analysisResults prepared pages, with 'embeddingText'
     * @return array<int|string, array<int|string, float>>|null
     */
    private function computeEmbeddingScores(array $analysisResults): ?array
    {
        $this->embeddingApiFailed = false;
        if (!$this->isEmbeddingEnabled()) {
            return null;
        }

        $pages = [];
        foreach ($analysisResults as $pageId => $pageData) {
            $text = (string)($pageData['embeddingText'] ?? '');
            if ($text !== '') {
                $pages[(int)$pageId] = ['languageUid' => (int)($pageData['sys_language_uid'] ?? 0), 'text' => $text];
            }
        }

        if (count($pages) < EmbeddingSimilarity::MINIMUM_PAGES) {
            $this->logInfo('Too few pages for embeddings, using TF-IDF', ['pages' => count($pages)]);
            return null;
        }

        try {
            $vectors = $this->pageEmbeddingService->getPageVectors($pages);
        } catch (EmbeddingException $e) {
            $this->embeddingApiFailed = true;
            $this->logWarning('Embedding API failed, this run uses TF-IDF', ['exception' => $e->getMessage()]);
            return null;
        }

        return $this->embeddingSimilarity->computeScores($vectors);
    }

    /**
     * Same result shape as calculateSimilarityFromVectors(). Embeddings of both
     * default models share one space across languages, so compatible languages
     * need no special case here.
     */
    private function calculateSimilarityFromEmbeddings(
        array $suggestedPage,
        ?string $language1,
        ?string $language2,
        float $score
    ): array {
        if ($score <= 0.0 || !$this->areLanguagesCompatible($language1, $language2)) {
            return [
                'semanticSimilarity' => 0.0,
                'recencyBoost' => 0.0,
                'finalSimilarity' => 0.0,
            ];
        }

        return $this->combineScores($score, $suggestedPage);
    }

    /**
     * Plain text of a field as a reader sees it: no tags, no entities, single spaces.
     */
    private function cleanTextForEmbedding(string $content): string
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $content)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Issue #15: Compute similarity from pre-computed TF-IDF vectors. This mirrors
     * calculateSimilarity() but skips the per-pair TF-IDF construction. It only
     * falls back to on-demand pairwise TF-IDF for the rare case of compatible pages
     * whose vectors were built in different language corpora (different vector spaces).
     */
    private function calculateSimilarityFromVectors(
        array $page1,
        array $page2,
        ?string $language1,
        ?string $language2,
        ?array $vector1,
        ?array $vector2
    ): array {
        $noSimilarity = [
            'semanticSimilarity' => 0.0,
            'recencyBoost' => 0.0,
            'finalSimilarity' => 0.0,
        ];

        if (!$this->areLanguagesCompatible($language1, $language2)) {
            return $noSimilarity;
        }

        // Empty text on either side -> no vector was produced.
        if ($vector1 === null || $vector2 === null) {
            return $noSimilarity;
        }

        // Compatible pages from different language corpora live in different vector
        // spaces; recompute their TF-IDF pair on demand to stay correct.
        if ($language1 !== $language2) {
            return $this->calculateSimilarity($page1, $page2);
        }

        return $this->combineScores($this->cosineSimilarity($vector1, $vector2), $page2);
    }

    private function prepareTextForAnalysis(array $pageData): string
    {
        $texts = [];
        
        // Only used for language detection now; vectors come from buildTfIdfVectors().
        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            if (isset($pageData[$field]['content']) && !empty($pageData[$field]['content'])) {
                if ((float)$weight > 0) {
                    $texts[] = $pageData[$field]['content'];
                }
            }
        }
        
        return implode(' ', $texts);
    }

    private function calculateSimilarityFallback(array $page1, array $page2): array
    {
        // Ancien calcul de similarité en fallback
        $words1 = $this->getWeightedWords($page1);
        $words2 = $this->getWeightedWords($page2);
    
        if (empty($words1) || empty($words2)) {
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
            return [
                'semanticSimilarity' => 0.0,
                'recencyBoost' => 0.0,
                'finalSimilarity' => 0.0
            ];
        }
    
        return $this->combineScores($dotProduct / ($magnitude1 * $magnitude2), $page2);
    }

    /**
     * Freshness of the suggested page: 1 when its content changed today, falling
     * linearly to 0 at RECENCY_WINDOW_DAYS and staying there.
     *
     * Only the suggested page counts. The pairs are directed (page -> suggestion),
     * and what a reader gains from is a recently updated suggestion. Until 4.2 this
     * was the absolute difference between the two pages' ages, which rewarded pairs
     * modified at different times rather than fresh content.
     */
    private function calculateRecencyBoost(array $suggestedPage): float
    {
        if (!isset($suggestedPage['content_modified_at'])) {
            return 0.0;
        }

        $age = max(0, time() - (int)$suggestedPage['content_modified_at']);

        return max(0.0, 1 - $age / (self::RECENCY_WINDOW_DAYS * 86400));
    }

    /**
     * Final score of a directed pair.
     *
     * Recency is a multiplier on the textual similarity, not a term added to it:
     * it reorders pages that are already similar, and never creates similarity.
     * A pair with nothing in common stays at 0, whatever recencyWeight is. With
     * the default weight of 0.2, a page untouched for RECENCY_WINDOW_DAYS keeps
     * 80 % of its textual score and a page updated today keeps all of it.
     *
     * @return array{semanticSimilarity: float, recencyBoost: float, finalSimilarity: float}
     */
    private function combineScores(float $semanticSimilarity, array $suggestedPage): array
    {
        $recencyBoost = $this->calculateRecencyBoost($suggestedPage);
        $recencyWeight = (float)($this->settings['recencyWeight'] ?? 0.2);

        return [
            'semanticSimilarity' => $semanticSimilarity,
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => min(1.0, $semanticSimilarity * ((1 - $recencyWeight) + $recencyWeight * $recencyBoost)),
        ];
    }

    /**
     * TF-IDF vectors for a set of prepared pages sharing one language, in the
     * order given. A page with no text in any weighted field gets null.
     *
     * Built here rather than in nlp_tools for three reasons. Field weights are
     * applied to term counts, so 1.2 differs from 1.0 and 0 removes the field;
     * the previous approach repeated each field's text round($weight) times.
     * The prepared fields are already stop-word filtered and stemmed, and
     * nlp_tools would do both a second time. And nlp_tools cuts every document
     * at 5,000 characters, which on a long page dropped most of the content.
     *
     * Vectors are sparse: only the terms a page contains are stored.
     *
     * @param list<array> $pages
     * @return list<?array<string, float>>
     */
    private function buildTfIdfVectors(array $pages): array
    {
        $termFrequencies = [];
        $documentFrequency = [];

        foreach ($pages as $index => $pageData) {
            $termFrequencies[$index] = $this->buildTermFrequencies($pageData);
            foreach ($termFrequencies[$index] as $term => $unused) {
                $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
            }
        }

        $documentCount = count($pages);
        $vectors = [];
        foreach ($termFrequencies as $index => $frequencies) {
            if ($frequencies === []) {
                $vectors[$index] = null;
                continue;
            }
            $vector = [];
            foreach ($frequencies as $term => $frequency) {
                // Smoothed IDF, the same formula nlp_tools uses.
                $vector[$term] = $frequency * (log(($documentCount + 1) / ($documentFrequency[$term] + 1)) + 1);
            }
            $vectors[$index] = $vector;
        }

        return $vectors;
    }

    /**
     * Weighted term frequencies of one prepared page: for each field, 1 + log(count)
     * per term, multiplied by the field's weight, then summed across fields. A field
     * weighted 0 or less is ignored.
     *
     * The logarithm keeps a term repeated forty times on a long page from outweighing
     * everything else. Measured on 42 pages in six topics: with raw counts 52 % of
     * the top three suggestions were on topic, with 1 + log(count) 98 %.
     *
     * @return array<string, float>
     */
    private function buildTermFrequencies(array $pageData): array
    {
        $frequencies = [];

        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            $weight = (float)$weight;
            $content = $pageData[$field]['content'] ?? '';
            if ($weight <= 0 || !is_string($content) || $content === '') {
                continue;
            }

            $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_count_values($tokens) as $term => $count) {
                if (mb_strlen((string)$term) < 2) {
                    continue;
                }
                $frequencies[(string)$term] = ($frequencies[(string)$term] ?? 0) + $weight * (1 + log($count));
            }
        }

        return $frequencies;
    }

    /**
     * Cosine similarity of two sparse vectors.
     */
    private function cosineSimilarity(array $vector1, array $vector2): float
    {
        if (count($vector1) > count($vector2)) {
            [$vector1, $vector2] = [$vector2, $vector1];
        }

        $dotProduct = 0.0;
        foreach ($vector1 as $term => $value) {
            if (isset($vector2[$term])) {
                $dotProduct += $value * $vector2[$term];
            }
        }

        if ($dotProduct == 0.0) {
            return 0.0;
        }

        $magnitude1 = sqrt(array_sum(array_map(static fn($x) => $x * $x, $vector1)));
        $magnitude2 = sqrt(array_sum(array_map(static fn($x) => $x * $x, $vector2)));

        return $dotProduct / ($magnitude1 * $magnitude2);
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

            if ($similarityValue >= SuggestionService::RELEVANCE_HIGH) {
                return 'High';
            } elseif ($similarityValue >= SuggestionService::RELEVANCE_MEDIUM) {
                return 'Medium';
            } else {
                return 'Low';
            }
        }


}