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

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        PageAnalysisService $pageAnalysisService
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->pageAnalysisService = $pageAnalysisService;
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

    public function injectLogger(LoggerInterface $logger): void
    {
        $this->setLogger($logger);
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
        if ($this->moduleTemplateFactory === null) {
            throw new \RuntimeException('ModuleTemplateFactory is not initialized', 1234567890);
        }
    
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

        $pages = $this->getPages($parentPageId, $depth);
        $analysisData = $this->pageAnalysisService->analyzePages($pages);
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

            $statistics = $this->calculateStatistics($analysisResults, $proximityThreshold);
            $languageStatistics = $this->calculateLanguageStatistics($analysisResults);
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
        ]);

        if ($this->logger instanceof LoggerInterface) {
            $this->logger->info('Finishing indexAction', [
                'languageStatistics' => $languageStatistics,
                'performanceMetrics' => $performanceMetrics
            ]);
        }
    
        $moduleTemplate->setContent($this->view->render());
        return $moduleTemplate->renderResponse();
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
            $pages[$pageUid] = $defaultLanguagePage;
            $translations = $this->getPageTranslations($pageUid);
            foreach ($translations as $languageId => $translation) {
                $translatedPageUid = $translation['uid'] ?? $pageUid; // Utilisez l'UID de la page par défaut si la traduction n'a pas d'UID
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
            if ($languageId > 0) {
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
    
        foreach ($pages as $pageId => $pageData) {
            $languageUid = $pageData['sys_language_uid'] ?? 0;
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
                if (!isset($languages[$languageId])) {
                    $languages[$languageId] = [
                        'title' => $language->getTitle(),
                        'twoLetterIsoCode' => $language->getTwoLetterIsoCode(),
                        'flagIdentifier' => $language->getFlagIdentifier(),
                    ];
                }
            }
        }
    
        ksort($languages);
        return $languages;
    }


}