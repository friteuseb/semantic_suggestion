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
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class SemanticBackendController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected ModuleTemplateFactory $moduleTemplateFactory;
    protected PageAnalysisService $pageAnalysisService;
    protected ?PageRepository $pageRepository = null;
    private $performanceLog = [];

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        PageAnalysisService $pageAnalysisService,
        LoggerInterface $logger
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->pageAnalysisService = $pageAnalysisService;
        $this->setLogger($logger);
    }

    private function logPerformance($operation)
    {
        $this->performanceLog[] = [
            'operation' => $operation,
            'time' => microtime(true),
            'memory' => memory_get_usage(true)
        ];
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
        $this->logPerformance('Start indexAction');

        if ($this->moduleTemplateFactory === null) {
            throw new \RuntimeException('ModuleTemplateFactory is not initialized', 1234567890);
        }
        
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->logPerformance('After creating moduleTemplate');

        $fullTypoScript = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );
        $this->logPerformance('After getting TypoScript configuration');

        $extensionConfig = $fullTypoScript['plugin.']['tx_semanticsuggestion_suggestions.']['settings.'] ?? [];

        $parentPageId = (int)($extensionConfig['parentPageId'] ?? 0);
        $depth = (int)($extensionConfig['recursive'] ?? 1);
        $proximityThreshold = (float)($extensionConfig['proximityThreshold'] ?? 0.5);
        $maxSuggestions = (int)($extensionConfig['maxSuggestions'] ?? 5);
        $excludePages = GeneralUtility::intExplode(',', $extensionConfig['excludePages'] ?? '', true);

        $this->logPerformance('Before getPages');
        $pages = $this->getPages($parentPageId, $depth);
        $this->logPerformance('After getPages');
        
        $this->logPerformance('Before analyzePages');
        $analysisData = $this->pageAnalysisService->analyzePages($pages);
        $this->logPerformance('After analyzePages');
        
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->debug('Analysis data', ['data' => $analysisData]);
        }

        $analysisResults = [];
        $performanceMetrics = [];
        $statistics = [];
        $languageStatistics = [];

        if (is_array($analysisData) && isset($analysisData['results']) && is_array($analysisData['results'])) {
            $analysisResults = $analysisData['results'];
            
            if (!empty($excludePages)) {
                $analysisResults = array_diff_key($analysisResults, array_flip($excludePages));
            }

            $this->logPerformance('Before calculateStatistics');
            $statistics = $this->calculateStatistics($analysisResults, $proximityThreshold);
            $this->logPerformance('After calculateStatistics');
            
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->debug('Analysis results before language statistics', ['results' => $analysisResults]);
            }
            $this->logPerformance('Before calculateLanguageStatistics');
            $languageStatistics = $this->calculateLanguageStatistics($analysisResults);
            $this->logPerformance('After calculateLanguageStatistics');
            
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->debug('Language statistics', ['stats' => $languageStatistics]);
            }
        } else {
            $this->addFlashMessage(
                'The analysis did not return valid results. Please check your configuration and try again.',
                'Analysis Error',
                \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR
            );
        }

        if (isset($analysisData['metrics']) && is_array($analysisData['metrics'])) {
            $performanceMetrics = [
                'executionTime' => $analysisData['metrics']['executionTime'] ?? 0,
                'totalPages' => $analysisData['metrics']['totalPages'] ?? 0,
                'similarityCalculations' => $analysisData['metrics']['similarityCalculations'] ?? 0,
                'fromCache' => isset($analysisData['metrics']['fromCache']) ? ($analysisData['metrics']['fromCache'] ? 'Yes' : 'No') : 'Unknown',
            ];
        } else {
            $this->addFlashMessage(
                'Performance metrics are not available.',
                'Metrics Unavailable',
                \TYPO3\CMS\Core\Messaging\AbstractMessage::WARNING
            );
        }    

        $this->logPerformance('End indexAction');

        // Calculer les logs de performance
        $performanceLogs = $this->calculatePerformanceDiffs();

        $moduleTemplate->assignMultiple([
            'parentPageId' => $parentPageId,
            'depth' => $depth,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
            'excludePages' => implode(', ', $excludePages),
            'statistics' => $statistics,
            'analysisResults' => $analysisResults,
            'performanceMetrics' => $performanceMetrics,
            'languageStatistics' => $languageStatistics,
            'totalPages' => count($pages),
            'performanceLogs' => $performanceLogs,
        ]);

        if ($this->logger instanceof LoggerInterface) {
            $this->logger->info('Finishing indexAction', [
                'languageStatistics' => $languageStatistics,
                'performanceMetrics' => $performanceMetrics,
                'performanceLogs' => $performanceLogs
            ]);
        }
    
        $moduleTemplate->setContent($this->view->render());
        return $moduleTemplate->renderResponse();
    }

    private function calculatePerformanceDiffs()
    {
        $diffs = [];
        for ($i = 1; $i < count($this->performanceLog); $i++) {
            $diffs[] = [
                'operation' => $this->performanceLog[$i]['operation'],
                'timeDiff' => $this->performanceLog[$i]['time'] - $this->performanceLog[$i-1]['time'],
                'memoryDiff' => $this->performanceLog[$i]['memory'] - $this->performanceLog[$i-1]['memory']
            ];
        }
        return $diffs;
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

    private function calculateStatistics(array $analysisResults, float $proximityThreshold): array
    {
        $totalPages = count($analysisResults);
        $totalSimilarityScore = 0;
        $similarityPairs = [];
        $distributionScores = [
            '0.0-0.2' => 0, '0.2-0.4' => 0, '0.4-0.6' => 0, '0.6-0.8' => 0, '0.8-1.0' => 0
        ];
        $pagesSimilarityCount = [];

        foreach ($analysisResults as $pageId => $pageData) {
            $pagesSimilarityCount[$pageId] = 0;
            foreach ($pageData['similarities'] as $similarPageId => $similarity) {
                if ($pageId < $similarPageId) { // Évite les doublons
                    $totalSimilarityScore += $similarity['score'];
                    $similarityPairs[] = [
                        'page1' => $pageId,
                        'page2' => $similarPageId,
                        'score' => $similarity['score']
                    ];
                    
                    if ($similarity['score'] >= $proximityThreshold) {
                        $pagesSimilarityCount[$pageId]++;
                        $pagesSimilarityCount[$similarPageId] = ($pagesSimilarityCount[$similarPageId] ?? 0) + 1;
                    }

                    // Mettre à jour la distribution des scores
                    if ($similarity['score'] < 0.2) $distributionScores['0.0-0.2']++;
                    elseif ($similarity['score'] < 0.4) $distributionScores['0.2-0.4']++;
                    elseif ($similarity['score'] < 0.6) $distributionScores['0.4-0.6']++;
                    elseif ($similarity['score'] < 0.8) $distributionScores['0.6-0.8']++;
                    else $distributionScores['0.8-1.0']++;
                }
            }
        }

        // Trier les paires par score de similarité décroissant
        usort($similarityPairs, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return [
            'totalPages' => $totalPages,
            'averageSimilarity' => $totalPages > 1 ? $totalSimilarityScore / (($totalPages * ($totalPages - 1)) / 2) : 0,
            'topSimilarPairs' => array_slice($similarityPairs, 0, 5),
            'distributionScores' => $distributionScores,
            'topSimilarPages' => arsort($pagesSimilarityCount) ? array_slice($pagesSimilarityCount, 0, 5, true) : [],
        ];
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


}