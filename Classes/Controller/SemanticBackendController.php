<?php
namespace TalanHdf\SemanticSuggestion\Controller;

// --- Imports ---
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Log\LogManager;
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

        try {
            // Récupérer la configuration TypoScript (seulement pour l'affichage)
            $fullTypoScript = $this->configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
            );
            $extensionConfig = $fullTypoScript['plugin.']['tx_semanticsuggestion_suggestions.']['settings.'] ?? [];
            
            // Paramètres d'affichage frontend uniquement
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

            // Récupérer toutes les analyses disponibles depuis la base de données
            $availableAnalyses = $this->getAvailableAnalyses();
            
            // Si aucun rootPageId n'est fourni, prendre le premier disponible
            if ($rootPageId === null && !empty($availableAnalyses)) {
                $rootPageId = (int)$availableAnalyses[0]['root_page_id'];
            }

            // Vérifier si un rootPageId valide a été trouvé
            if ($rootPageId <= 0 || empty($availableAnalyses)) {
                $this->addFlashMessage(
                    'Aucune analyse de similarité trouvée. Veuillez configurer et exécuter la tâche Scheduler "Semantic Suggestion: Generate Similarities".',
                    'Aucune donnée disponible',
                    ContextualFeedbackSeverity::INFO
                );
                
                $moduleTemplate->assignMultiple([
                    'errorMessage' => 'Aucune analyse de similarité trouvée.',
                    'availableAnalyses' => [],
                    'showStatistics' => $showStatistics,
                    'showPerformanceMetrics' => $showPerformanceMetrics,
                ]);
                
                return $moduleTemplate->renderResponse('SemanticBackend/Index');
            }

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
                'Une erreur est survenue lors du chargement du module. Consultez les logs.',
                'Erreur',
                ContextualFeedbackSeverity::ERROR
            );

            $moduleTemplate->assign('errorMessage', 'Erreur lors du chargement du module.');
            return $moduleTemplate->renderResponse('SemanticBackend/Index');
        }
    }

    /**
     * Récupère toutes les analyses disponibles dans la base de données
     */
    protected function getAvailableAnalyses(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');
        
        $analyses = $queryBuilder
            ->select('root_page_id')
            ->addSelectLiteral('COUNT(DISTINCT page_id) as page_count')
            ->addSelectLiteral('COUNT(*) as pair_count')
            ->from('tx_semanticsuggestion_similarities')
            ->groupBy('root_page_id')
            ->orderBy('root_page_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        // Enrichir avec les informations des pages
        foreach ($analyses as &$analysis) {
            $pageInfo = $this->pageRepository->getPage((int)$analysis['root_page_id']);
            $analysis['title'] = $pageInfo['title'] ?? 'Page ' . $analysis['root_page_id'];
        }

        return $analyses;
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
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');
        
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
        return $this->pageRepository;
    }
}