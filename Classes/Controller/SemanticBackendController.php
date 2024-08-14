<?php
namespace TalanHdf\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SemanticBackendController extends ActionController
{
    protected $moduleTemplateFactory;
    protected $pageAnalysisService;
    protected $configurationManager;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory, 
        PageAnalysisService $pageAnalysisService,
        ConfigurationManagerInterface $configurationManager
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->pageAnalysisService = $pageAnalysisService;
        $this->configurationManager = $configurationManager;
    }

    public function indexAction(): ResponseInterface
{
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

    // Vérifier si l'analyse NLP est activée
    $nlpEnabled = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('semantic_suggestion_nlp');
    $nlpConfig = $nlpEnabled ? GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class)->get('semantic_suggestion_nlp') : [];
    $nlpEnabled = $nlpEnabled && ($nlpConfig['enableNlpAnalysis'] ?? false);

    $analysisData = $this->pageAnalysisService->analyzePages($parentPageId, $depth);

    $analysisResults = [];
    $performanceMetrics = [];
    $statistics = [];
    $nlpStatistics = [];

    if (is_array($analysisData) && isset($analysisData['results']) && is_array($analysisData['results'])) {
        $analysisResults = $analysisData['results'];
        
        // Filter out excluded pages from analysis results
        if (!empty($excludePages)) {
            $analysisResults = array_diff_key($analysisResults, array_flip($excludePages));
        }

        $statistics = $this->calculateStatistics($analysisResults, $proximityThreshold);
        
        if ($nlpEnabled) {
            // Appel du hook NLP pour obtenir les statistiques
            if (isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['semantic_suggestion']['nlpStatistics'])) {
                foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['semantic_suggestion']['nlpStatistics'] as $hookClass) {
                    $hookInstance = GeneralUtility::makeInstance($hookClass);
                    if (method_exists($hookInstance, 'getNlpStatistics')) {
                        $nlpStatistics = $hookInstance->getNlpStatistics($analysisResults);
                    }
                }
            }
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
        'nlpEnabled' => $nlpEnabled,
        'nlpStatistics' => $nlpStatistics,
    ]);

    $moduleTemplate->setContent($this->view->render());
    return $moduleTemplate->renderResponse();
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

private function calculateNlpStatistics(array $analysisResults): array
{
    $totalWordCount = 0;
    $totalUniqueWordCount = 0;
    $totalComplexity = 0;
    $allTopWords = [];
    $allSuggestions = [];

    foreach ($analysisResults as $pageData) {
        if (isset($pageData['nlp'])) {
            $nlpData = $pageData['nlp'];
            $totalWordCount += $nlpData['wordCount'] ?? 0;
            $totalUniqueWordCount += $nlpData['uniqueWordCount'] ?? 0;
            $totalComplexity += $nlpData['textComplexity'] ?? 0;
            
            if (isset($nlpData['topWords'])) {
                foreach ($nlpData['topWords'] as $word => $count) {
                    if (!isset($allTopWords[$word])) {
                        $allTopWords[$word] = 0;
                    }
                    $allTopWords[$word] += $count;
                }
            }

            if (isset($nlpData['suggestions'])) {
                $allSuggestions = array_merge($allSuggestions, $nlpData['suggestions']);
            }
        }
    }

    $pageCount = count($analysisResults);
    
    arsort($allTopWords);
    $allTopWords = array_slice($allTopWords, 0, 10, true);

    return [
        'averageWordCount' => $pageCount > 0 ? $totalWordCount / $pageCount : 0,
        'averageUniqueWordCount' => $pageCount > 0 ? $totalUniqueWordCount / $pageCount : 0,
        'averageComplexity' => $pageCount > 0 ? $totalComplexity / $pageCount : 0,
        'topWords' => $allTopWords,
        'commonSuggestions' => array_slice(array_count_values($allSuggestions), 0, 5, true),
    ];
}




}