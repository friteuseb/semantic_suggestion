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
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->debug('ModuleTemplateFactory injected');
        }
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
    try {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
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

        $currentLanguageUid = $this->getCurrentLanguageUid();

        $startTime = microtime(true);
        $cacheIdentifier = $this->generateValidCacheIdentifier($parentPageId, $depth, $proximityThreshold, $maxSuggestions, $currentLanguageUid);

        if ($this->getCache()->has($cacheIdentifier)) {
            $data = $this->getCache()->get($cacheIdentifier);
            $fromCache = true;
            $executionTime = 0;
        } else {
            $pages = $this->getPages($parentPageId, $depth);
            $analysisData = $this->pageAnalysisService->analyzePages($pages, $currentLanguageUid);
            $data = $this->processAnalysisData($analysisData, $proximityThreshold, $excludePages, $maxSuggestions);
            $this->getCache()->set($cacheIdentifier, $data, ['semantic_suggestion'], 3600);
            $fromCache = false;
            $executionTime = microtime(true) - $startTime;
        }

        // Ajouter les métriques de performance actuelles
        $performanceMetrics = [
            'executionTime' => $executionTime,
            'totalPages' => $data['totalPages'],
            'similarityCalculations' => $data['statistics']['totalPages'] * ($data['statistics']['totalPages'] - 1) / 2,
            'fromCache' => $fromCache ? 'Yes' : 'No',
        ];

        $moduleTemplate->assignMultiple([
            'parentPageId' => $parentPageId,
            'depth' => $depth,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
            'excludePages' => implode(', ', $excludePages),
            'recencyWeight' => $recencyWeight,
            'statistics' => $showStatistics ? $data['statistics'] : null,
            'analysisResults' => $data['analysisResults'],
            'performanceMetrics' => $showPerformanceMetrics ? $performanceMetrics : null,
            'languageStatistics' => $showLanguageStatistics ? $data['languageStatistics'] : null,
            'totalPages' => $data['totalPages'],
            'showStatistics' => $showStatistics,
            'showPerformanceMetrics' => $showPerformanceMetrics,
            'showLanguageStatistics' => $showLanguageStatistics,
            'showTopSimilarPairs' => (bool)($extensionConfig['showTopSimilarPairs'] ?? true),
            'showDistributionScores' => (bool)($extensionConfig['showDistributionScores'] ?? true),
            'showTopSimilarPages' => (bool)($extensionConfig['showTopSimilarPages'] ?? true),
        ]);

        $moduleTemplate->setContent($this->view->render());
        return $moduleTemplate->renderResponse();

    } catch (\Exception $e) {
        $this->logger->error('Error in indexAction', ['exception' => $e->getMessage()]);
        $this->addFlashMessage(
            'An error occurred while processing the data. Please check the logs for more information.',
            'Error',
            \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR
        );
        return $this->htmlResponse();
    }
}

    protected function getPages(int $parentPageId, int $depth): array
    {
        $pages = [];
        $defaultLanguagePages = $this->getPageRepository()->getMenu(
            $parentPageId,
            '*',
            'sorting',
            '',
            false
        );

        foreach ($defaultLanguagePages as $pageUid => $defaultLanguagePage) {
            $defaultLanguagePage['sys_language_uid'] = 0;  // Assurez-vous que c'est bien défini
            $pages[$pageUid] = $defaultLanguagePage;

            $translations = $this->getPageTranslations($pageUid);
            foreach ($translations as $languageId => $translation) {
                $translatedPageUid = $translation['_PAGES_OVERLAY_UID'] ?? $pageUid;
                $translation['uid'] = $translatedPageUid;
                $translation['sys_language_uid'] = $languageId;
                $pages[$translatedPageUid] = $translation;
            }

            if ($depth > 1) {
                $subpages = $this->getPages($pageUid, $depth - 1);
                $pages = array_merge($pages, $subpages);
            }
        }
        return $pages;
    }

    protected function getPageTranslations(int $pageUid): array
    {
        $translations = [];
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $site = $siteFinder->getSiteByPageId($pageUid);

        foreach ($site->getAllLanguages() as $language) {
            $languageId = $language->getLanguageId();
            if ($languageId > 0) {  // Seulement pour les langues non-default
                $translatedPage = $this->pageRepository->getPageOverlay($pageUid, $languageId);
                if ($translatedPage) {
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
            if ($calculateTopSimilarPairs) {
                $pagesSimilarityCount[$pageId] = 0;
            }
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
    
                        if ($similarity['score'] >= $proximityThreshold) {
                            $pagesSimilarityCount[$pageId]++;
                            $pagesSimilarityCount[$similarPageId] = ($pagesSimilarityCount[$similarPageId] ?? 0) + 1;
                        }
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
            $result['topSimilarPairs'] = array_slice($similarityPairs, 0, 5);
            $result['topSimilarPages'] = arsort($pagesSimilarityCount) ? array_slice($pagesSimilarityCount, 0, 5, true) : [];
        }

        if ($calculateDistribution) {
            $result['distributionScores'] = $distributionScores;
        }

        return $result;
    }




    private function calculateLanguageStatistics(array $pages): array
    {
        $languageStats = [];

        foreach ($pages as $page) {
            $languageUid = $page['sys_language_uid'] ?? 0;
            if (!isset($languageStats[$languageUid])) {
                $languageStats[$languageUid] = 0;
            }
            $languageStats[$languageUid]++;
        }

        $allLanguages = $this->getAllLanguages();
        $result = [];

        foreach ($allLanguages as $languageUid => $languageInfo) {
            $result[$languageUid] = [
                'count' => $languageStats[$languageUid] ?? 0,
                'info' => $languageInfo,
            ];
        }

        // Ajoutez ce log de débogage
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->debug('Language statistics calculation', [
                'input_pages' => $pages,
                'calculated_stats' => $languageStats,
                'result' => $result
            ]);
        }

        return $result;
    }
    private function getAllLanguages(): array
    {
        $languages = [
            0 => [
                'title' => 'Default (English)',
                'twoLetterIsoCode' => 'en',
                'flagIdentifier' => 'gb',
            ]
        ];
        
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $sites = $siteFinder->getAllSites();

        foreach ($sites as $site) {
            foreach ($site->getAllLanguages() as $language) {
                $languageId = $language->getLanguageId();
                $languages[$languageId] = [
                    'title' => $language->getTitle(),
                    'twoLetterIsoCode' => $language->getLocale()->getLanguageCode(),
                    'flagIdentifier' => $language->getFlagIdentifier(),
                ];
            }
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
        $languageStatistics = $this->calculateLanguageStatistics($analysisResults);
    
        return [
            'statistics' => $statistics,
            'analysisResults' => $analysisResults,
            'languageStatistics' => $languageStatistics,
            'totalPages' => count($analysisResults),
        ];
    }


    protected function getCurrentLanguageUid(): int
{
    return GeneralUtility::makeInstance(Context::class)->getAspect('language')->getId();
}
}