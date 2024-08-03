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

        $analysisResults = $this->pageAnalysisService->analyzePages($parentPageId, $depth);

        // Filter out excluded pages from analysis results
        $analysisResults = array_diff_key($analysisResults, array_flip($excludePages));

        $statistics = $this->calculateStatistics($analysisResults, $proximityThreshold);

        $moduleTemplate->assignMultiple([
            'parentPageId' => $parentPageId,
            'depth' => $depth,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
            'excludePages' => implode(', ', $excludePages),
            'statistics' => $statistics,
            'analysisResults' => $analysisResults,
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


}