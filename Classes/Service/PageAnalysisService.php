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

    public function __construct(
        Context $context,
        ConfigurationManagerInterface $configurationManager,
        StopWordsService $stopWordsService,
        SiteFinder $siteFinder,
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


   
    







}