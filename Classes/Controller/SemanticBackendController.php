<?php
namespace TalanHdf\SemanticSuggestion\Controller;

// --- Imports ---
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Doctrine\DBAL\ParameterType;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TalanHdf\SemanticSuggestion\Service\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class SemanticBackendController extends ActionController
{
    // --- Propriétés pour l'Injection de Dépendances (DI) ---
    protected ModuleTemplateFactory $moduleTemplateFactory;
    protected PageAnalysisService $pageAnalysisService;
    protected ?PageRepository $pageRepository = null;
    protected LoggerInterface $logger;
    protected LanguageService $languageService;
    protected FlashMessageService $flashMessageService;
    protected ?ConnectionPool $connectionPool = null;

    // --- Constructeur pour DI (v13) ---
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        PageAnalysisService $pageAnalysisService,
        LogManager $logManager,
        LanguageService $languageService,
        FlashMessageService $flashMessageService,
        PageRepository $pageRepository,
        ?ConnectionPool $connectionPool = null
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->pageAnalysisService = $pageAnalysisService;
        $this->logger = $logManager->getLogger(__CLASS__);
        $this->languageService = $languageService;
        $this->flashMessageService = $flashMessageService;
        $this->pageRepository = $pageRepository;
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
    }

    public function injectPageRepository(PageRepository $pageRepository): void
    {
        $this->pageRepository = $pageRepository;
    }

    public function indexAction(?int $rootPageId = null): ResponseInterface
    {
        $startTime = microtime(true);
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        // Try to get rootPageId from various sources
        $queryParams = $this->request->getQueryParams();

        // Check in Extbase namespace first (tx_semanticsuggestion_web_semanticsuggestion[rootPageId])
        $extbaseNamespace = 'tx_semanticsuggestion_web_semanticsuggestion';
        if ($rootPageId === null && isset($queryParams[$extbaseNamespace]['rootPageId'])) {
            $rootPageId = (int)$queryParams[$extbaseNamespace]['rootPageId'];
        }

        // Fallback: check in request arguments
        if ($rootPageId === null && $this->request->hasArgument('rootPageId')) {
            $rootPageId = (int)$this->request->getArgument('rootPageId');
        }

        try {
            // Récupérer la configuration TypoScript (seulement pour l'affichage)
            $fullTypoScript = $this->configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
            );
            $extensionConfig = $fullTypoScript['plugin.']['tx_semanticsuggestion_suggestions.']['settings.'] ?? [];

            // Récupérer la configuration d'extension comme fallback
            $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('semantic_suggestion');

            // Paramètres d'affichage frontend : TypoScript > Config extension > Constante
            $proximityThreshold = (float)(
                $extensionConfig['qualityLevel'] ??
                $extensionConfig['proximityThreshold'] ??
                ($extensionConfiguration['settings']['qualityLevel'] ?? null) ??
                ($extensionConfiguration['settings']['proximityThreshold'] ?? null) ??
                \TalanHdf\SemanticSuggestion\Service\SuggestionService::DEFAULT_QUALITY_LEVEL
            );
            $maxSuggestions = (int)(
                $extensionConfig['maxSuggestions'] ??
                ($extensionConfiguration['settings']['maxSuggestions'] ?? 5)
            );
            $excludePages = GeneralUtility::intExplode(',', $extensionConfig['excludePages'] ?? ($extensionConfiguration['settings']['excludePages'] ?? ''), true);
            $excerptLength = (int)(
                $extensionConfig['excerptLength'] ??
                ($extensionConfiguration['settings']['excerptLength'] ?? 100)
            );
            
            // Paramètres de visibilité du module
            $showStatistics = (bool)($extensionConfig['showStatistics'] ?? true);
            $showPerformanceMetrics = (bool)($extensionConfig['showPerformanceMetrics'] ?? true);
            $showLanguageStatistics = (bool)($extensionConfig['showLanguageStatistics'] ?? true);
            $showTopSimilarPairs = (bool)($extensionConfig['showTopSimilarPairs'] ?? true);
            $showDistributionScores = (bool)($extensionConfig['showDistributionScores'] ?? true);
            $showTopSimilarPages = (bool)($extensionConfig['showTopSimilarPages'] ?? true);

            // Récupérer toutes les analyses disponibles depuis la base de données
            $availableAnalyses = $this->getAvailableAnalyses();
            
            // Si aucun rootPageId n'est fourni, prendre le premier disponible
            if ($rootPageId === null && !empty($availableAnalyses)) {
                $rootPageId = (int)$availableAnalyses[0]['root_page_id'];
            }

            // rootPageId comes from the query string and can be forged: it must be one
            // of the analyses this user is actually allowed to see, otherwise the
            // selector filtering above would be trivially bypassed.
            if ($rootPageId !== null && $rootPageId > 0) {
                $permittedRootPageIds = array_map(
                    static fn(array $analysis): int => (int)$analysis['root_page_id'],
                    $availableAnalyses
                );

                if (!in_array($rootPageId, $permittedRootPageIds, true)) {
                    $this->logger->notice('Requested analysis is not available to this user, falling back', [
                        'requestedRootPageId' => $rootPageId,
                        'backendUser' => $GLOBALS['BE_USER']->user['uid'] ?? null,
                    ]);
                    $rootPageId = $permittedRootPageIds[0] ?? 0;
                }
            }

            // Vérifier si un rootPageId valide a été trouvé
            if ($rootPageId <= 0 || empty($availableAnalyses)) {
                $this->addFlashMessage(
                    LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.flash.no_analysis_found', 'semantic_suggestion') ?? 'No similarity analysis found. Please configure and run the "Semantic Suggestion: Generate Similarities" scheduler task.',
                    LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.flash.no_data_available', 'semantic_suggestion') ?? 'No data available',
                    ContextualFeedbackSeverity::INFO
                );
                
                $moduleTemplate->assignMultiple([
                    'errorMessage' => LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.error.no_analysis.message', 'semantic_suggestion') ?? 'No similarity analysis found. Please configure and run the "Semantic Suggestion: Generate Similarities" scheduler task.',
                    'availableAnalyses' => [],
                    'showStatistics' => $showStatistics,
                    'showPerformanceMetrics' => $showPerformanceMetrics,
                ]);
                
                return $moduleTemplate->renderResponse('SemanticBackend/Index');
            }

            // Récupérer les données d'analyse depuis la base
            $analysisData = $this->getAnalysisFromDatabase($rootPageId);
            $statistics = $this->calculateStatistics($analysisData, $proximityThreshold, $excludePages, $maxSuggestions);

            // Informations sur l'analyse sélectionnée
            $currentAnalysis = null;
            foreach ($availableAnalyses as $analysis) {
                if ((int)$analysis['root_page_id'] === $rootPageId) {
                    $currentAnalysis = $analysis;
                    break;
                }
            }

            // Calculer les métriques de performance
            $executionTime = microtime(true) - $startTime;
            $performanceMetrics = [
                'executionTime' => $executionTime,
                'storedSimilarities' => $this->getStoredSimilaritiesCount($rootPageId, $proximityThreshold, $excludePages),
            ];

            // Récupérer les langues du site (pour les statistiques de langue)
            $siteLanguages = [];
            try {
                $siteLanguages = $this->languageService->getSiteLanguages($rootPageId);
            } catch (\Exception $e) {
                $this->logger->warning('No site configuration found for page ID ' . $rootPageId);
            }

            // Statistiques de langue basées sur les pages dans les résultats
            $pageIdsInResults = array_keys($analysisData['results'] ?? []);
            $languageStatistics = [];
            if (!empty($pageIdsInResults) && !empty($siteLanguages)) {
                $pagesForLangStats = $this->pageRepository->getMenuForPages($pageIdsInResults, 'uid, sys_language_uid');
                $languageStatistics = $this->languageService->getLanguageStatistics($pagesForLangStats, $siteLanguages);
            }

            // Assigner les variables à la vue
            $moduleTemplate->assignMultiple([
                // Configuration d'affichage (TypoScript)
                'proximityThreshold' => $proximityThreshold,
                'maxSuggestions' => $maxSuggestions,
                'excludePages' => implode(', ', $excludePages),
                'excerptLength' => $excerptLength,

                // Informations sur l'analyse Scheduler actuelle
                'currentRootPageId' => $rootPageId,
                'currentAnalysis' => $currentAnalysis,
                'availableAnalyses' => $availableAnalyses,

                // Visibilité des sections
                'showStatistics' => $showStatistics,
                'showPerformanceMetrics' => $showPerformanceMetrics,
                'showLanguageStatistics' => $showLanguageStatistics,
                'showTopSimilarPairs' => $showTopSimilarPairs,
                'showDistributionScores' => $showDistributionScores,
                'showTopSimilarPages' => $showTopSimilarPages,

                // Données d'analyse
                'performanceMetrics' => $showPerformanceMetrics ? $performanceMetrics : null,
                'statistics' => $showStatistics ? $statistics : null,
                'analysisResults' => $analysisData['results'] ?? [],
                'languageStatistics' => $showLanguageStatistics ? ($languageStatistics['statistics'] ?? []) : null,
                'totalValidatedPages' => count($pageIdsInResults),
            ]);

            return $moduleTemplate->renderResponse('SemanticBackend/Index');

        } catch (\Exception $e) {
            $this->logger->error('Error in indexAction', [
                'exception' => $e->getMessage(), 
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->addFlashMessage(
                sprintf(
                    LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.flash.module_error', 'semantic_suggestion') ?? 'An error occurred while loading the module. Check the logs: %s',
                    'General error'
                ),
                LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.flash.error', 'semantic_suggestion') ?? 'Error',
                ContextualFeedbackSeverity::ERROR
            );

            $moduleTemplate->assign('errorMessage', LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.flash.error', 'semantic_suggestion') ?? 'Error');
            return $moduleTemplate->renderResponse('SemanticBackend/Index');
        }
    }

    /**
     * Récupère toutes les analyses disponibles dans la base de données
     */
    protected function getAvailableAnalyses(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');

        $queryBuilder
            ->select('root_page_id')
            ->addSelectLiteral('COUNT(DISTINCT page_id) as page_count')
            ->addSelectLiteral('COUNT(*) as pair_count')
            ->from('tx_semanticsuggestion_similarities')
            ->groupBy('root_page_id')
            ->orderBy('root_page_id', 'ASC');

        // Non-admins only see the sites they have a webmount on. Without this, an
        // editor of one site can browse the page titles and scores of every other
        // site of the instance (see #22).
        $allowedRootPageIds = $this->getAllowedRootPageIds();
        if ($allowedRootPageIds !== null) {
            if ($allowedRootPageIds === []) {
                return [];
            }

            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    'root_page_id',
                    $queryBuilder->createNamedParameter($allowedRootPageIds, Connection::PARAM_INT_ARRAY)
                )
            );
        }

        $analyses = $queryBuilder->executeQuery()->fetchAllAssociative();

        // Enrichir avec les informations des pages
        foreach ($analyses as &$analysis) {
            $pageInfo = $this->pageRepository->getPage((int)$analysis['root_page_id']);
            $analysis['title'] = $pageInfo['title'] ?? 'Page ' . $analysis['root_page_id'];
        }

        return $analyses;
    }

    /**
     * Site root page UIDs the current backend user may look at.
     *
     * @return int[]|null null for admins, meaning "no restriction"
     */
    protected function getAllowedRootPageIds(): ?array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || $backendUser->isAdmin()) {
            return null;
        }

        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $rootPageIds = [];

        foreach ($backendUser->returnWebmounts() as $webmount) {
            $webmount = (int)$webmount;
            if ($webmount <= 0) {
                continue;
            }

            try {
                $rootPageIds[] = $siteFinder->getSiteByPageId($webmount)->getRootPageId();
            } catch (\Exception $e) {
                $this->logger->debug('Webmount outside any configured site, ignored', [
                    'webmount' => $webmount,
                ]);
            }
        }

        return array_values(array_unique($rootPageIds));
    }

    /**
     * Récupère les données d'analyse depuis la base de données
     */
    protected function getAnalysisFromDatabase(int $rootPageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');
        
        $similarities = $queryBuilder
            ->select('page_id', 'similar_page_id', 'similarity_score', 'sys_language_uid')
            ->from('tx_semanticsuggestion_similarities')
            ->where(
                $queryBuilder->expr()->eq('root_page_id', $queryBuilder->createNamedParameter($rootPageId, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $analysisResults = [];
        $pageIds = [];

        // Construire les résultats d'analyse
        foreach ($similarities as $similarity) {
            $pageId = (int)$similarity['page_id'];
            $similarPageId = (int)$similarity['similar_page_id'];
            
            $pageIds[] = $pageId;
            $pageIds[] = $similarPageId;

            if (!isset($analysisResults[$pageId])) {
                $analysisResults[$pageId] = [
                    'uid' => $pageId,
                    'similarities' => [],
                    'sys_language_uid' => (int)$similarity['sys_language_uid']
                ];
            }

            $analysisResults[$pageId]['similarities'][$similarPageId] = [
                'score' => (float)$similarity['similarity_score'],
                'relevance' => $this->determineRelevanceLevel((float)$similarity['similarity_score'])
            ];
        }

        // Récupérer les informations des pages
        $pageIds = array_unique($pageIds);
        $pageDataRecords = [];
        
        if (!empty($pageIds)) {
            $pageDataRecords = $this->pageRepository->getMenuForPages($pageIds, 'uid, title');
            
            foreach ($analysisResults as $pageId => &$data) {
                $data['title'] = [
                    'content' => $pageDataRecords[$pageId]['title'] ?? 'Page ' . $pageId
                ];
            }
        }

        return ['results' => $analysisResults];
    }

    /**
     * Calcule les statistiques d'analyse
     */
    protected function calculateStatistics(array $analysisData, float $proximityThreshold, array $excludePages = [], int $maxSuggestions = 3): array
    {
        $results = $analysisData['results'] ?? [];
        $allPairs = [];
        $pagesSimilarityCount = [];

        foreach ($results as $pageId => $pageData) {
            $similarities = $pageData['similarities'] ?? [];
            $pagesSimilarityCount[$pageId] = 0;

            // Trier les similarités par score décroissant pour appliquer maxSuggestions
            arsort($similarities);

            $suggestionCount = 0;
            foreach ($similarities as $similarPageId => $similarityData) {
                $score = $similarityData['score'];

                // Appliquer les filtres frontend
                if ($score >= $proximityThreshold &&
                    !in_array($similarPageId, $excludePages) &&
                    $suggestionCount < $maxSuggestions) {

                    $pagesSimilarityCount[$pageId]++;
                    $suggestionCount++;

                    // Éviter les doublons dans les paires
                    $pairKey = min($pageId, $similarPageId) . '-' . max($pageId, $similarPageId);
                    if (!isset($allPairs[$pairKey])) {
                        $allPairs[$pairKey] = [
                            'page1' => $pageId,
                            'page2' => $similarPageId,
                            'score' => $score
                        ];
                    }
                }
            }
        }

        // Trier les paires par score décroissant
        usort($allPairs, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Trier les pages par nombre de similarités décroissant
        arsort($pagesSimilarityCount);

        return [
            'topSimilarPairs' => array_slice($allPairs, 0, 5),
            'topSimilarPages' => array_slice($pagesSimilarityCount, 0, 5, true),
            'totalPairs' => count($allPairs),
            'totalPages' => count($results)
        ];
    }

    /**
     * Compte le nombre de similarités stockées pour une analyse (filtrées selon les paramètres frontend)
     */
    protected function getStoredSimilaritiesCount(int $rootPageId, float $proximityThreshold, array $excludePages = []): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');

        $query = $queryBuilder
            ->count('*')
            ->from('tx_semanticsuggestion_similarities')
            ->where(
                $queryBuilder->expr()->eq('root_page_id', $queryBuilder->createNamedParameter($rootPageId, ParameterType::INTEGER)),
                $queryBuilder->expr()->gte('similarity_score', $queryBuilder->createNamedParameter($proximityThreshold, ParameterType::STRING))
            );

        // Appliquer le filtre d'exclusion des pages si nécessaire
        if (!empty($excludePages)) {
            $query->andWhere(
                $queryBuilder->expr()->notIn('similar_page_id', $excludePages)
            );
        }

        return (int)$query->executeQuery()->fetchOne();
    }

    /**
     * Détermine le niveau de pertinence basé sur le score
     */
    protected function determineRelevanceLevel(float $score): string
    {
        if ($score >= 0.8) return 'high';
        if ($score >= 0.6) return 'medium';
        return 'low';
    }

    /**
     * Récupère le repository des pages
     */
    protected function getPageRepository(): PageRepository
    {
        return $this->pageRepository;
    }
}