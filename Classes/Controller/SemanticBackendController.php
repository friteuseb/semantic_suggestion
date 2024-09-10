<?php
namespace TalanHdf\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;

class SemanticBackendController extends ActionController
{
    protected ModuleTemplateFactory $moduleTemplateFactory;
    protected PageAnalysisService $pageAnalysisService;
    protected ?PageRepository $pageRepository = null;
    protected ?FrontendInterface $cache = null;
    protected ExtensionConfiguration $extensionConfiguration;
    protected ?CacheManager $cacheManager = null;
    protected LoggerInterface $logger;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        PageAnalysisService $pageAnalysisService,
        LogManager $logManager
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->pageAnalysisService = $pageAnalysisService;
        $this->logger = $logManager->getLogger(__CLASS__);
    }


    public function initializeObject()
    {
        if ($this->cacheManager === null) {
            $this->cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        }
    }

    public function injectCacheManager(CacheManager $cacheManager): void
    {
        $this->cacheManager = $cacheManager;
    }

    protected function getCache(): FrontendInterface
    {
        if ($this->cache === null) {
            if ($this->cacheManager === null) {
                $this->cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            }
            $this->cache = $this->cacheManager->getCache('semantic_suggestion');
        }
        return $this->cache;
    }

    private function logDebug(string $message, array $context = []): void
    {
        $debugMode = $this->pageAnalysisService instanceof PageAnalysisService 
            ? ($this->pageAnalysisService->getSettings()['debugMode'] ?? false)
            : false;
    
        if ($debugMode && $this->logger instanceof LoggerInterface) {
            $this->logDebug($message, $context);
        }
    }
    

    
    public function updateConfigurationAction(array $configuration): ResponseInterface
    {
        // Update the extension configuration
        $this->extensionConfiguration->set('semantic_suggestion', $configuration);

        // Update TypoScript configuration
        $fullTypoScript = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );
        $pluginSettings = &$fullTypoScript['plugin.']['tx_semanticsuggestion_suggestions.']['settings.'];
        foreach ($configuration as $key => $value) {
            $pluginSettings[$key] = $value;
        }
        $this->configurationManager->setConfiguration($fullTypoScript);

        // Add a flash message to confirm the update
        $this->addFlashMessage(
            'The configuration has been updated successfully.',
            'Configuration Updated',
            \TYPO3\CMS\Core\Messaging\AbstractMessage::OK
        );

        // Redirect back to the index action
        return $this->redirect('index');
    }

    public function injectExtensionConfiguration(ExtensionConfiguration $extensionConfiguration): void
    {
        $this->extensionConfiguration = $extensionConfiguration;
    }

    public function injectModuleTemplateFactory(ModuleTemplateFactory $moduleTemplateFactory): void
    {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->logDebug('ModuleTemplateFactory injected');
    }

    public function injectPageAnalysisService(PageAnalysisService $pageAnalysisService): void
    {
        $this->pageAnalysisService = $pageAnalysisService;
    }

    public function injectConfigurationManager(ConfigurationManagerInterface $configurationManager): void
    {
        $this->configurationManager = $configurationManager;
    }

    protected function initializeAction()
    {
        parent::initializeAction();
        if ($this->arguments === null) {
            $this->arguments = new \TYPO3\CMS\Extbase\Mvc\Controller\Arguments();
        }
    }

    protected function initializeActionMethodValidators(): void
    {
        if ($this->arguments === null) {
            $this->arguments = new \TYPO3\CMS\Extbase\Mvc\Controller\Arguments();
        }
        parent::initializeActionMethodValidators();
    }

    protected function getPageRepository(): PageRepository
    {
        if ($this->pageRepository === null) {
            $this->pageRepository = GeneralUtility::makeInstance(PageRepository::class);
        }
        return $this->pageRepository;
    }

    public function indexAction(): ResponseInterface
    {
        $this->logDebug('Début de indexAction');
        $mergedData = [];
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
    
        try {
            $fullTypoScript = $this->configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
            );
    
            $extensionConfig = $fullTypoScript['plugin.']['tx_semanticsuggestion_suggestions.']['settings.'] ?? [];
    
            $parentPageId = (int)($extensionConfig['parentPageId'] ?? 0);
            $depth = (int)($extensionConfig['recursive'] ?? 1);
            $proximityThreshold = (float)($extensionConfig['proximityThreshold'] ?? 0.5);
            $maxSuggestions = (int)($extensionConfig['maxSuggestions'] ?? 5);
            $excludePages = GeneralUtility::intExplode(',', $extensionConfig['excludePages'] ?? '', true);
            $recencyWeight = (float)($extensionConfig['recencyWeight'] ?? 0.2);
            $showStatistics = (bool)($extensionConfig['showStatistics'] ?? true);
            $showPerformanceMetrics = (bool)($extensionConfig['showPerformanceMetrics'] ?? true);
            $showLanguageStatistics = (bool)($extensionConfig['showLanguageStatistics'] ?? true);
            $calculateDistribution = (bool)($extensionConfig['calculateDistribution'] ?? true);
            $calculateTopSimilarPairs = (bool)($extensionConfig['calculateTopSimilarPairs'] ?? true);
    
            $startTime = microtime(true);
            
            $allFromCache = true;
    
            // Récupérer toutes les langues disponibles pour le site
            $siteLanguages = $this->getSiteLanguages($parentPageId);
    
            $allPages = $this->getPages($parentPageId, $depth);
            $totalPagesAnalyzed = count($allPages);
    
            // Appliquer les exclusions
            $validatedPages = array_filter($allPages, function($page) use ($excludePages) {
                return !in_array($page['uid'], $excludePages);
            });
            $totalValidatedPages = count($validatedPages);
    
            $languageStatistics = $this->calculateLanguageStatistics($validatedPages, $siteLanguages);
            
            $this->logDebug('All pages retrieved', ['count' => $totalPagesAnalyzed, 'validatedCount' => $totalValidatedPages]);
            
            $data = [];
            foreach ($siteLanguages as $language) {
                $languageUid = $language->getLanguageId();
                $cacheIdentifier = $this->generateValidCacheIdentifier($parentPageId, $depth, $proximityThreshold, $maxSuggestions, $languageUid);
     
                if ($this->getCache()->has($cacheIdentifier)) {
                    $languageData = $this->getCache()->get($cacheIdentifier);
                } else {
                    $allFromCache = false;
                    $languagePages = array_filter($validatedPages, function($page) use ($languageUid) {
                        return $page['sys_language_uid'] == $languageUid || isset($page['translations'][$languageUid]);
                    });
                    $this->logDebug('Filtered pages for language', [
                        'languageUid' => $languageUid,
                        'pageCount' => count($languagePages),
                        'pageUids' => array_keys($languagePages)
                    ]);
                    $analysisData = $this->pageAnalysisService->analyzePages($languagePages, $languageUid);
                    $this->logDebug('Pages analysées pour la langue ' . $languageUid, [
                        'count' => count($analysisData['results']),
                        'pages' => array_keys($analysisData['results'])
                    ]);
                    $languageData = $this->processAnalysisData($analysisData, $proximityThreshold, $excludePages, $maxSuggestions);
                    $this->getCache()->set($cacheIdentifier, $languageData, ['semantic_suggestion'], 3600);
                }
                
                $data[$languageUid] = $languageData;
            }
            
            $mergedData = $this->mergeLanguageData($data);
            $this->logDebug('Merged data', [
                'totalPagesAnalyzed' => $totalPagesAnalyzed,
                'totalValidatedPages' => $totalValidatedPages,
                'languageStatistics' => $languageStatistics
            ]);
    
            $executionTime = microtime(true) - $startTime;
    
            $performanceMetrics = [
                'executionTime' => $executionTime,
                'totalPagesAnalyzed' => $totalPagesAnalyzed,
                'totalValidatedPages' => $totalValidatedPages,
                'similarityCalculations' => $totalValidatedPages * ($totalValidatedPages - 1) / 2,
                'fromCache' => $allFromCache,
            ];
    
            $moduleTemplate->assignMultiple([
                'parentPageId' => $parentPageId,
                'depth' => $depth,
                'proximityThreshold' => $proximityThreshold,
                'maxSuggestions' => $maxSuggestions,
                'excludePages' => implode(', ', $excludePages),
                'recencyWeight' => $recencyWeight,
                'performanceMetrics' => $showPerformanceMetrics ? $performanceMetrics : null,
                'showStatistics' => $showStatistics,
                'showPerformanceMetrics' => $showPerformanceMetrics,
                'showLanguageStatistics' => $showLanguageStatistics,
                'showTopSimilarPairs' => (bool)($extensionConfig['showTopSimilarPairs'] ?? true),
                'showDistributionScores' => (bool)($extensionConfig['showDistributionScores'] ?? true),
                'showTopSimilarPages' => (bool)($extensionConfig['showTopSimilarPages'] ?? true),
                'statistics' => $showStatistics ? ($mergedData['statistics'] ?? null) : null,
                'analysisResults' => $mergedData['analysisResults'] ?? [],
                'totalPagesAnalyzed' => $totalPagesAnalyzed,
                'totalValidatedPages' => $totalValidatedPages,
                'languageStatistics' => $languageStatistics['statistics'],
            ]);
    
        } catch (\Exception $e) {
            $this->logger->error('Error in indexAction', ['exception' => $e->getMessage()]);
            $this->addFlashMessage(
                'An error occurred while processing the data. Please check the logs for more information.',
                'Error',
                \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR
            );
        }
    
        try {
            $content = $this->view->render();
            $moduleTemplate->setContent($content);
        } catch (\Exception $e) {
            $this->logger->error('Error rendering view', ['exception' => $e->getMessage()]);
            $this->addFlashMessage(
                'An error occurred while rendering the view. Please check the logs for more information.',
                'Render Error',
                \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR
            );
            $moduleTemplate->setContent('An error occurred while rendering the view.');
        }
    
        $this->logDebug('Fin de indexAction');
        return $moduleTemplate->renderResponse();
    }


    protected function mergeLanguageData(array $data): array
    {
        $mergedData = [
            'statistics' => [],
            'analysisResults' => [],
            'languageStatistics' => [],
            'totalPages' => 0,
        ];
    
        $allPageUids = [];
        foreach ($data as $languageUid => $languageData) {
            $allPageUids = array_merge($allPageUids, array_keys($languageData['analysisResults']));
            $mergedData['analysisResults'] += $languageData['analysisResults'];
            $mergedData['languageStatistics'] += $languageData['languageStatistics'];
            $mergedData['totalPages'] += $languageData['totalPages'];
        }
    
        // Fusionner les statistiques
        $mergedData['statistics'] = $this->mergeStatistics(array_column($data, 'statistics'));
    
        return $mergedData;
    }
    
    protected function mergeStatistics(array $statisticsArray): array
    {
        $mergedStats = [
            'totalPages' => 0,
            'averageSimilarity' => 0,
            'topSimilarPairs' => [],
            'distributionScores' => [],
            'topSimilarPages' => [],
        ];
    
        $totalSimilarityScore = 0;
        $totalPairs = 0;
    
        foreach ($statisticsArray as $stats) {
            $mergedStats['totalPages'] += $stats['totalPages'];
            $totalSimilarityScore += $stats['averageSimilarity'] * $stats['totalPages'] * ($stats['totalPages'] - 1) / 2;
            $totalPairs += $stats['totalPages'] * ($stats['totalPages'] - 1) / 2;
    
            $mergedStats['topSimilarPairs'] = array_merge($mergedStats['topSimilarPairs'], $stats['topSimilarPairs'] ?? []);
            $mergedStats['topSimilarPages'] += $stats['topSimilarPages'] ?? [];
    
            foreach ($stats['distributionScores'] ?? [] as $range => $count) {
                $mergedStats['distributionScores'][$range] = ($mergedStats['distributionScores'][$range] ?? 0) + $count;
            }
        }
    
        $mergedStats['averageSimilarity'] = $totalPairs > 0 ? $totalSimilarityScore / $totalPairs : 0;
    
        usort($mergedStats['topSimilarPairs'], function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        $mergedStats['topSimilarPairs'] = array_slice($mergedStats['topSimilarPairs'], 0, 5);
    
        arsort($mergedStats['topSimilarPages']);
        $mergedStats['topSimilarPages'] = array_slice($mergedStats['topSimilarPages'], 0, 5, true);
    
        return $mergedStats;
    }



    protected function getPages(int $parentPageId, int $depth): array
    {
        $pageRepository = $this->getPageRepository();
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $site = $siteFinder->getSiteByPageId($parentPageId);
        $allPages = [];
    
        $defaultLanguagePages = $pageRepository->getMenu($parentPageId, '*', 'sorting', 'AND hidden=0 AND deleted=0', false);
        
        foreach ($defaultLanguagePages as $page) {
            $allPages[$page['uid']] = $page;
            $allPages[$page['uid']]['sys_language_uid'] = 0;
            
            foreach ($site->getLanguages() as $siteLanguage) {
                $languageId = $siteLanguage->getLanguageId();
                if ($languageId > 0) {
                    $localizedPage = $pageRepository->getPageOverlay($page, $languageId);
                    if ($localizedPage && isset($localizedPage['_PAGES_OVERLAY_UID'])) {
                        $localizedPage['sys_language_uid'] = $languageId;
                        $allPages[$localizedPage['_PAGES_OVERLAY_UID']] = $localizedPage;
                    }
                }
            }
    
            if ($depth > 1) {
                $subPages = $this->getPages($page['uid'], $depth - 1);
                $allPages = array_merge($allPages, $subPages);
            }
        }
    
        return $allPages;
    }

    protected function getSiteLanguages(int $pageId): array
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        try {
            $site = $siteFinder->getSiteByPageId($pageId);
            return $site->getLanguages();
        } catch (\TYPO3\CMS\Core\Exception\SiteNotFoundException $e) {
            $this->logger->warning('No site found for page ID ' . $pageId . '. Using default language.');
            return [new \TYPO3\CMS\Core\Site\Entity\SiteLanguage(0, 'en', new \TYPO3\CMS\Core\Site\Entity\NullSite(), ['title' => 'Default', 'twoLetterIsoCode' => 'en', 'flagIdentifier' => 'en'])];
        }
    }


    
    protected function getPageTranslations(int $pageUid): array
    {
        $translations = [];
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $site = $siteFinder->getSiteByPageId($pageUid);
    
        foreach ($site->getAllLanguages() as $language) {
            $languageId = $language->getLanguageId();
            if ($languageId > 0) {  // Only for non-default languages
                $translatedPage = $this->pageRepository->getPageOverlay($pageUid, $languageId);
                if ($translatedPage && isset($translatedPage['uid'])) {
                    $translatedPage['sys_language_uid'] = $languageId;
                    $translations[$languageId] = $translatedPage;
                }
            }
        }
    
        return $translations;
    }



    private function calculateStatistics(array $analysisResults, float $proximityThreshold, bool $calculateDistribution, bool $calculateTopSimilarPairs): array
    {
        $totalPages = count($analysisResults);
        $totalSimilarityScore = 0;
        $similarityPairs = [];
        $distributionScores = $calculateDistribution ? [
            '0.0-0.2' => 0, '0.2-0.4' => 0, '0.4-0.6' => 0, '0.6-0.8' => 0, '0.8-1.0' => 0
        ] : [];
        $pagesSimilarityCount = [];
    
        foreach ($analysisResults as $pageId => $pageData) {
            $pagesSimilarityCount[$pageId] = 0;
            $pageLanguage = $pageData['sys_language_uid'] ?? 0;
            foreach ($pageData['similarities'] as $similarPageId => $similarity) {
                $similarPageLanguage = $analysisResults[$similarPageId]['sys_language_uid'] ?? 0;
                if ($pageId < $similarPageId && $pageLanguage === $similarPageLanguage) {
                    $totalSimilarityScore += $similarity['score'];
                    if ($calculateTopSimilarPairs) {
                        $similarityPairs[] = [
                            'page1' => $pageId,
                            'page2' => $similarPageId,
                            'score' => $similarity['score'],
                            'language' => $pageLanguage
                        ];
                    }
    
                    if ($similarity['score'] >= $proximityThreshold) {
                        $pagesSimilarityCount[$pageId]++;
                        $pagesSimilarityCount[$similarPageId] = ($pagesSimilarityCount[$similarPageId] ?? 0) + 1;
                    }
    
                    if ($calculateDistribution) {
                        if ($similarity['score'] < 0.2) $distributionScores['0.0-0.2']++;
                        elseif ($similarity['score'] < 0.4) $distributionScores['0.2-0.4']++;
                        elseif ($similarity['score'] < 0.6) $distributionScores['0.4-0.6']++;
                        elseif ($similarity['score'] < 0.8) $distributionScores['0.6-0.8']++;
                        else $distributionScores['0.8-1.0']++;
                    }
                }
            }
        }
    
        $result = [
            'totalPages' => $totalPages,
            'averageSimilarity' => $totalPages > 1 ? $totalSimilarityScore / (($totalPages * ($totalPages - 1)) / 2) : 0,
        ];
    
        if ($calculateTopSimilarPairs) {
            usort($similarityPairs, function($a, $b) {
                return $b['score'] <=> $a['score'];
            });
            $similarityPairs = array_unique($similarityPairs, SORT_REGULAR);
            $result['topSimilarPairs'] = array_slice($similarityPairs, 0, 5);
        }
    
        if ($calculateDistribution) {
            $result['distributionScores'] = $distributionScores;
        }
    
        // Ajout du calcul des Top 5 Pages with Most Similarities
        arsort($pagesSimilarityCount);
        $result['topSimilarPages'] = array_slice($pagesSimilarityCount, 0, 5, true);
    
        return $result;
   }
   

    private function getAllLanguages(): array
    {
        $languages = [];
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $sites = $siteFinder->getAllSites();

        foreach ($sites as $site) {
            foreach ($site->getAllLanguages() as $language) {
                $languageId = $language->getLanguageId();
                $languages[$languageId] = [
                    'title' => $language->getTitle(),
                    'twoLetterIsoCode' => $language->getTwoLetterIsoCode(),
                    'flagIdentifier' => $language->getFlagIdentifier(),
                ];
            }
        }

        // Assurez-vous que la langue par défaut (ID 0) est incluse
        if (!isset($languages[0])) {
            $defaultLanguage = $sites[array_key_first($sites)]->getDefaultLanguage();
            $languages[0] = [
                'title' => $defaultLanguage->getTitle(),
                'twoLetterIsoCode' => $defaultLanguage->getTwoLetterIsoCode(),
                'flagIdentifier' => $defaultLanguage->getFlagIdentifier(),
            ];
        }

        ksort($languages);
        return $languages;
    }


    protected function generateValidCacheIdentifier(int $parentPageId, int $depth, float $proximityThreshold, int $maxSuggestions, int $currentLanguageUid): string
    {
        $identifier = 'semantic_analysis_' . $parentPageId . '_' . $depth . '_' . $proximityThreshold . '_' . $maxSuggestions . '_' . $currentLanguageUid;
        return md5($identifier);
    }


    protected function processAnalysisData(array $analysisData, float $proximityThreshold, array $excludePages, int $maxSuggestions): array
    {
        $analysisResults = $analysisData['results'] ?? [];

        if (!empty($excludePages)) {
            $analysisResults = array_diff_key($analysisResults, array_flip($excludePages));
        }

        $fullTypoScript = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );
        $extensionConfig = $fullTypoScript['plugin.']['tx_semanticsuggestion_suggestions.']['settings.'] ?? [];
        $calculateDistribution = (bool)($extensionConfig['calculateDistribution'] ?? true);
        $calculateTopSimilarPairs = (bool)($extensionConfig['calculateTopSimilarPairs'] ?? true);

        $statistics = $this->calculateStatistics($analysisResults, $proximityThreshold, $calculateDistribution, $calculateTopSimilarPairs);
        
        // Ici, nous devons passer les langues du site
        $siteLanguages = $this->getSiteLanguages($this->getCurrentPageId());
        $languageStatistics = $this->calculateLanguageStatistics($analysisResults, $siteLanguages);

        return [
            'statistics' => $statistics,
            'analysisResults' => $analysisResults,
            'languageStatistics' => $languageStatistics,
            'totalPages' => count($analysisResults),
        ];
    }


    protected function getCurrentPageId(): int
    {
        $pageId = (int)($GLOBALS['TSFE']->id ?? $this->request->getQueryParams()['id'] ?? 0);
        
        if ($pageId === 0) {
            // Si nous n'avons pas d'ID de page, essayons de prendre le premier site disponible
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $sites = $siteFinder->getAllSites();
            if (!empty($sites)) {
                $firstSite = reset($sites);
                $pageId = $firstSite->getRootPageId();
            }
        }
        
        if ($pageId === 0) {
            throw new \RuntimeException('No valid page ID found', 1631234567);
        }
        
        return $pageId;
    }

    private function calculateLanguageStatistics(array $pages, array $siteLanguages): array
    {
        $languageStats = [];
        $totalPages = 0;
    
        foreach ($siteLanguages as $language) {
            $languageId = $language->getLanguageId();
            $languageStats[$languageId] = [
                'count' => 0,
                'info' => [
                    'title' => $language->getTitle(),
                    'twoLetterIsoCode' => $language->getTwoLetterIsoCode(),
                    'flagIdentifier' => $language->getFlagIdentifier(),
                ],
            ];
        }
    
        foreach ($pages as $page) {
            $languageId = $page['sys_language_uid'] ?? 0;
            if (isset($languageStats[$languageId])) {
                $languageStats[$languageId]['count']++;
                $totalPages++;
            }
        }
    
        foreach ($languageStats as &$stat) {
            $stat['percentage'] = ($totalPages > 0) ? ($stat['count'] / $totalPages) * 100 : 0;
        }
    
        return [
            'statistics' => $languageStats,
            'totalPages' => $totalPages
        ];
    }


    protected function getCurrentLanguageUid(): int
{
    return GeneralUtility::makeInstance(Context::class)->getAspect('language')->getId();
}
}