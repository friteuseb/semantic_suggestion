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
                $nlpStatistics = $this->calculateNlpStatistics($analysisResults);
                $sentimentDistribution = $this->calculateSentimentDistribution($analysisResults);
                $pagesNeedingAttention = $this->getPagesNeedingAttention($analysisResults);
            } else {
                // Fournir des données vides si NLP n'est pas activé
                $nlpStatistics = [];
                $sentimentDistribution = [];
                $pagesNeedingAttention = [];
            }
            $moduleTemplate->assign('sentimentDistribution', $sentimentDistribution);
            $moduleTemplate->assign('pagesNeedingAttention', $pagesNeedingAttention);
            $moduleTemplate->assign('nlpStatistics', $nlpStatistics);
        
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
    
        $moduleTemplate->assign('nlpEnabled', $nlpEnabled);
    
        $moduleTemplate->assignMultiple([
            'parentPageId' => $parentPageId,
            'depth' => $depth,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
            'excludePages' => implode(', ', $excludePages),
            'statistics' => $statistics,
            'analysisResults' => $analysisResults,
            'performanceMetrics' => $performanceMetrics,
            'nlpStatistics' => $nlpStatistics,
            'nlpEnabled' => $nlpEnabled,
            'nlpStatistics' => $nlpStatistics,
            'sentimentDistribution' => $sentimentDistribution ?? [],
            'pagesNeedingAttention' => $pagesNeedingAttention ?? [],
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
    $totalReadabilityScore = 0;
    $totalWordCount = 0;
    $totalSentenceCount = 0;
    $totalSemanticCoherence = 0;
    $languages = [];
    $sentiments = [];
    $lowReadabilityPages = [];
    $lowCoherencePages = [];

    foreach ($analysisResults as $pageId => $result) {
        if (!isset($result['nlp'])) continue;
        
        $nlp = $result['nlp'];
        $totalReadabilityScore += $nlp['readability_score'];
        $totalWordCount += $nlp['word_count'];
        $totalSentenceCount += $nlp['sentence_count'];
        $totalSemanticCoherence += $nlp['semantic_coherence'];
        $languages[$nlp['language']] = ($languages[$nlp['language']] ?? 0) + 1;
        $sentiments[$nlp['sentiment']] = ($sentiments[$nlp['sentiment']] ?? 0) + 1;

        if ($nlp['readability_score'] < 130) {
            $lowReadabilityPages[$pageId] = $nlp['readability_score'];
        }
        if ($nlp['semantic_coherence'] < 0.1) {
            $lowCoherencePages[$pageId] = $nlp['semantic_coherence'];
        }
    }

    $count = count($analysisResults);

    return [
        'averageReadabilityScore' => $count > 0 ? round($totalReadabilityScore / $count, 2) : 0,
        'averageWordCount' => $count > 0 ? round($totalWordCount / $count) : 0,
        'averageSentenceCount' => $count > 0 ? round($totalSentenceCount / $count, 1) : 0,
        'averageSemanticCoherence' => $count > 0 ? round($totalSemanticCoherence / $count, 3) : 0,
        'languageDistribution' => $languages,
        'sentimentDistribution' => $sentiments,
        'lowReadabilityPages' => $lowReadabilityPages,
        'lowCoherencePages' => $lowCoherencePages,
    ];
}


private function calculateSentimentDistribution(array $analysisResults): array
{
    $distribution = ['Très négatif' => 0, 'Négatif' => 0, 'Neutre' => 0, 'Positif' => 0, 'Très positif' => 0];
    foreach ($analysisResults as $pageData) {
        if (isset($pageData['nlp']['sentiment'])) {
            $sentiment = $this->convertSentimentToText($pageData['nlp']['sentiment']);
            $distribution[$sentiment]++;
        }
    }
    return $distribution;
}

private function getPagesNeedingAttention(array $analysisResults): array
{
    $pagesNeedingAttention = [];
    foreach ($analysisResults as $pageId => $pageData) {
        if (isset($pageData['nlp'])) {
            $readabilityScore = $pageData['nlp']['readability_score'] ?? 0;
            $semanticCoherence = $pageData['nlp']['semantic_coherence'] ?? 0;
            if ($readabilityScore < 0.5 || $semanticCoherence < 0.5) {
                $pagesNeedingAttention[] = [
                    'id' => $pageId,
                    'title' => $pageData['title']['content'] ?? 'Page ' . $pageId,
                    'readabilityScore' => $readabilityScore,
                    'semanticCoherence' => $semanticCoherence
                ];
            }
        }
    }
    return $pagesNeedingAttention;
}

private function convertSentimentToText(string $sentiment): string
{
    switch ($sentiment) {
        case '1 stars':
            return 'Très négatif';
        case '2 stars':
            return 'Négatif';
        case '3 stars':
            return 'Neutre';
        case '4 stars':
            return 'Positif';
        case '5 stars':
            return 'Très positif';
        default:
            return 'Non défini';
    }
}



}