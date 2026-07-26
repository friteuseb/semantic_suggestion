<?php
namespace TalanHdf\SemanticSuggestion\Controller;

// --- Imports utilisés ---
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TalanHdf\SemanticSuggestion\Service\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class LegacySemanticBackendController extends ActionController
{
    // --- Propriétés injectées via Constructeur ---
    protected ModuleTemplateFactory $moduleTemplateFactory;
    protected PageAnalysisService $pageAnalysisService;
    protected LoggerInterface $logger;
    protected LanguageService $languageService;
    protected FlashMessageService $flashMessageService;

    // --- Autres propriétés ---
    protected ?PageRepository $pageRepository = null;

    // --- Constructeur ---
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        PageAnalysisService $pageAnalysisService,
        LogManager $logManager,
        LanguageService $languageService,
        FlashMessageService $flashMessageService
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->pageAnalysisService = $pageAnalysisService;
        $this->logger = $logManager->getLogger(__CLASS__);
        $this->languageService = $languageService;
        $this->flashMessageService = $flashMessageService;
    }

    // --- Méthode pour le logging (simplifiée) ---
    private function logDebug(string $message, array $context = []): void
    {
        // Vérifier si le mode debug TYPO3 est activé avant d'écrire les logs
        if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['debug'] ?? false) {
            $this->logger->debug($message, $context);
        }
    }

    // --- Action Index (Logique métier principale) ---
    public function indexAction(): ResponseInterface
    {
        // Récupérer le rootPageId depuis les paramètres de la requête
        $rootPageId = null;
        $queryParams = $this->request->getQueryParams();
        
        if (isset($queryParams['rootPageId']) && is_numeric($queryParams['rootPageId'])) {
            $rootPageId = (int)$queryParams['rootPageId'];
        }
        
        // Fallback
        if ($rootPageId === null && isset($_GET['rootPageId'])) {
            $rootPageId = (int)$_GET['rootPageId'];
        }
        
        $this->logDebug('Retrieved rootPageId from request', [
            'rootPageId' => $rootPageId,
            'GET' => $_GET
        ]);
        
        $this->logDebug('Début de indexAction (Legacy Controller)');
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $startTime = microtime(true);

        // 🔍 DEBUG : Ajouter ces lignes pour diagnostiquer
        $this->logDebug('Received rootPageId parameter', ['rootPageId' => $rootPageId]);
        $this->logDebug('GET parameters', ['GET' => $_GET]);
        $this->logDebug('Request parameters', ['params' => $this->request->getQueryParams()]);

        // 🎯 Masquer le pagetree avec CSS pour TYPO3 v12
        $pageRenderer = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\PageRenderer::class);
        $pageRenderer->addCssInlineBlock(
            'hide-pagetree-v12',
            '
            .scaffold-content-navigation,
            .scaffold-content-navigation-component,
            .module-docheader-bar-navigation,
            .typo3-module-navigation,
            .navigation-component-frame,
            .scaffold-content-navigation-expanded,
            .module-navigation {
                display: none !important;
            }
            .scaffold-content-module,
            .module-body,
            .scaffold-content-module-wrapper {
                margin-left: 0 !important;
                width: 100% !important;
            }
            .module-docheader {
                margin-left: 0 !important;
            }
            .scaffold {
                grid-template-columns: 1fr !important;
            }
            '
        );
        try {
            // Récupérer la configuration via le service PageAnalysisService
            $extensionConfig = $this->pageAnalysisService->getSettings();
            
            // Récupérer toutes les analyses disponibles depuis la base de données
            $availableAnalyses = $this->getAvailableAnalyses();
            
            // Si aucun rootPageId n'est fourni, prendre le premier disponible
            if ($rootPageId === null && !empty($availableAnalyses)) {
                $rootPageId = (int)$availableAnalyses[0]['root_page_id'];
            }

            // rootPageId is user-supplied and must stay within what this user may see.
            if ($rootPageId !== null && $rootPageId > 0) {
                $permittedRootPageIds = array_map(
                    static fn(array $analysis): int => (int)$analysis['root_page_id'],
                    $availableAnalyses
                );

                if (!in_array($rootPageId, $permittedRootPageIds, true)) {
                    $rootPageId = $permittedRootPageIds[0] ?? 0;
                }
            }

            // Vérifier si un rootPageId valide a été trouvé
            if ($rootPageId <= 0 || empty($availableAnalyses)) {
                // Utiliser les constantes FlashMessage pour v12
                $this->addFlashMessage(
                    LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.flash.no_analysis_found', 'semantic_suggestion') ?? 'No similarity analysis found. Please configure and run the "Semantic Suggestion: Generate Similarities" scheduler task.',
                    LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.flash.no_data_available', 'semantic_suggestion') ?? 'No data available',
                    FlashMessage::INFO // Utiliser la constante FlashMessage
                );
                
                $moduleTemplate->assignMultiple([
                    'errorMessage' => LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.error.no_analysis.message', 'semantic_suggestion') ?? 'No similarity analysis found. Please configure and run the "Semantic Suggestion: Generate Similarities" scheduler task.',
                    'availableAnalyses' => [],
                    'showStatistics' => true,
                    'showPerformanceMetrics' => true,
                ]);
                
                return $moduleTemplate->renderResponse('LegacySemanticBackend/Index');
            }

            // Paramètres d'affichage frontend
            $proximityThreshold = (float)($extensionConfig['proximityThreshold'] ?? 0.5);
            $maxSuggestions = (int)($extensionConfig['maxSuggestions'] ?? 3);
            $excludePages = GeneralUtility::intExplode(',', $extensionConfig['excludePages'] ?? '', true);
            $excerptLength = (int)($extensionConfig['excerptLength'] ?? 100);
            
            // Paramètres de visibilité du module
            $showStatistics = (bool)($extensionConfig['showStatistics'] ?? true);
            $showPerformanceMetrics = (bool)($extensionConfig['showPerformanceMetrics'] ?? true);
            $showLanguageStatistics = (bool)($extensionConfig['showLanguageStatistics'] ?? true);
            $showTopSimilarPairs = (bool)($extensionConfig['showTopSimilarPairs'] ?? true);
            $showDistributionScores = (bool)($extensionConfig['showDistributionScores'] ?? true);
            $showTopSimilarPages = (bool)($extensionConfig['showTopSimilarPages'] ?? true);

            $this->logDebug('Using config (Legacy)', $extensionConfig);

            // Récupérer les données d'analyse depuis la base
            $analysisData = $this->getAnalysisFromDatabase($rootPageId);
            $statistics = $this->calculateStatistics($analysisData, $proximityThreshold);

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
                'storedSimilarities' => $this->getStoredSimilaritiesCount($rootPageId),
            ];

            // Récupérer les langues du site
            $siteLanguages = [];
            try {
                $siteLanguages = $this->languageService->getSiteLanguages($rootPageId);
            } catch (\Exception $e) {
                $this->logger->warning('No site configuration found for page ID ' . $rootPageId);
                // Utiliser la constante FlashMessage pour v12
                $this->addFlashMessage(
                    'Site configuration not found for page ' . $rootPageId,
                    'Config Warning',
                    FlashMessage::WARNING // Utiliser la constante FlashMessage
                );
            }

            // Statistiques de langue basées sur les pages dans les résultats
            $pageIdsInResults = array_keys($analysisData['results'] ?? []);
            $languageStatistics = [];
            if (!empty($pageIdsInResults) && !empty($siteLanguages)) {
                $pagesForLangStats = $this->getPageRepository()->getMenuForPages($pageIdsInResults, 'uid, sys_language_uid');
                $languageStatistics = $this->languageService->getLanguageStatistics($pagesForLangStats, $siteLanguages);
            }

            // Récupérer et préparer les messages Flash (V12)
            $flashMessageQueue = $this->flashMessageService->getMessageQueueByIdentifier('core.template.flashMessages');
            $flashMessages = $flashMessageQueue->renderFlashMessages();

            // Assigner les variables à la vue Fluid
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

                // Messages
                'flashMessages' => $flashMessages,
            ]);

            // Rendre la réponse
            return $moduleTemplate->renderResponse('LegacySemanticBackend/Index');

        } catch (\Exception $e) {
            $this->logger->error('Error in indexAction (Legacy)', [
                'exception' => $e->getMessage(), 
                'trace' => $e->getTraceAsString()
            ]);
            
            // Utiliser la constante FlashMessage pour v12
            $this->addFlashMessage(
                sprintf(
                    LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.flash.module_error', 'semantic_suggestion') ?? 'An error occurred while loading the module. Check the logs: %s',
                    $e->getMessage()
                ),
                LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.flash.error', 'semantic_suggestion') ?? 'Error',
                FlashMessage::ERROR // Utiliser la constante FlashMessage
            );

            $flashMessageQueue = $this->flashMessageService->getMessageQueueByIdentifier('core.template.flashMessages');
            $flashMessages = $flashMessageQueue->renderFlashMessages();
            
            $moduleTemplate->assignMultiple([
                'flashMessages' => $flashMessages,
                'errorMessage' => LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:backend.error.no_analysis.message', 'semantic_suggestion') ?? 'An error occurred while loading the module.',
                'availableAnalyses' => [],
                'showStatistics' => true,
                'showPerformanceMetrics' => true,
            ]);
            
            return $moduleTemplate->renderResponse('LegacySemanticBackend/Index');
        }
    }

    /**
     * Récupère toutes les analyses disponibles dans la base de données
     */
    protected function getAvailableAnalyses(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_semanticsuggestion_similarities');

        $queryBuilder
            ->select('root_page_id')
            ->addSelectLiteral('COUNT(DISTINCT page_id) as page_count')
            ->addSelectLiteral('COUNT(*) as pair_count')
            ->from('tx_semanticsuggestion_similarities')
            ->groupBy('root_page_id')
            ->orderBy('root_page_id', 'ASC');

        // Same webmount restriction as the v13 controller (see #22).
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
            $pageInfo = $this->getPageRepository()->getPage((int)$analysis['root_page_id']);
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
                continue;
            }
        }

        return array_values(array_unique($rootPageIds));
    }

    /**
     * Récupère les données d'analyse depuis la base de données
     */
    protected function getAnalysisFromDatabase(int $rootPageId): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_semanticsuggestion_similarities');
        
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
            $pageDataRecords = $this->getPageRepository()->getMenuForPages($pageIds, 'uid, title');
            
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
    protected function calculateStatistics(array $analysisData, float $proximityThreshold): array
    {
        $results = $analysisData['results'] ?? [];
        $allPairs = [];
        $pagesSimilarityCount = [];

        foreach ($results as $pageId => $pageData) {
            $similarities = $pageData['similarities'] ?? [];
            $pagesSimilarityCount[$pageId] = 0;

            foreach ($similarities as $similarPageId => $similarityData) {
                $score = $similarityData['score'];
                
                if ($score >= $proximityThreshold) {
                    $pagesSimilarityCount[$pageId]++;
                    
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
     * Compte le nombre de similarités stockées pour une analyse
     */
    protected function getStoredSimilaritiesCount(int $rootPageId): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_semanticsuggestion_similarities');
        
        return (int)$queryBuilder
            ->count('*')
            ->from('tx_semanticsuggestion_similarities')
            ->where(
                $queryBuilder->expr()->eq('root_page_id', $queryBuilder->createNamedParameter($rootPageId, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();
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
        if ($this->pageRepository === null) {
            $this->pageRepository = GeneralUtility::makeInstance(PageRepository::class);
        }
        return $this->pageRepository;
    }

    /**
     * Méthode addFlashMessage compatible avec TYPO3 v12
     * Signature compatible avec TYPO3 v12 ActionController.
     */
    public function addFlashMessage(
        string $messageBody,
        $messageTitle = '',
        $severity = FlashMessage::OK, // Utiliser les constantes FlashMessage pour v12
        $storeInSession = true
    ) {
        // Conversion sûre vers l'enum pour créer le FlashMessage
        $severityEnum = match($severity) {
            FlashMessage::ERROR => ContextualFeedbackSeverity::ERROR,
            FlashMessage::WARNING => ContextualFeedbackSeverity::WARNING,
            FlashMessage::NOTICE => ContextualFeedbackSeverity::NOTICE,
            FlashMessage::INFO => ContextualFeedbackSeverity::INFO,
            default => ContextualFeedbackSeverity::OK,
        };

        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $messageBody,
            (string)$messageTitle,
            $severityEnum,
            (bool)$storeInSession
        );
        
        $this->flashMessageService
            ->getMessageQueueByIdentifier('core.template.flashMessages')
            ->enqueue($flashMessage);
    }
}