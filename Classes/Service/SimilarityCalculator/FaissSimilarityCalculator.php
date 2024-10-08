<?php
namespace TalanHdf\SemanticSuggestion\Service\SimilarityCalculator;

use TalanHdf\SemanticSuggestion\Service\NlpApiService;

class FaissSimilarityCalculator implements SimilarityCalculatorInterface
{
    private $nlpApiService;

    public function __construct(NlpApiService $nlpApiService)
    {
        $this->nlpApiService = $nlpApiService;
    }

    public function calculateSimilarity(array $pages): array
    {
        $textPairs = $this->prepareTextPairs($pages);
        $apiResults = $this->nlpApiService->getBatchSimilarity($textPairs);
        
        return $this->processBatchResults($apiResults, $pages);
    }

    private function prepareTextPairs(array $pages): array
    {
        $textPairs = [];
        foreach ($pages as $pageId => $pageData) {
            foreach ($pages as $comparisonPageId => $comparisonPageData) {
                if ($pageId !== $comparisonPageId) {
                    $textPairs[] = [
                        'text1' => $pageData['content'],
                        'text2' => $comparisonPageData['content'],
                        'pageId1' => $pageId,
                        'pageId2' => $comparisonPageId
                    ];
                }
            }
        }
        return $textPairs;
    }

    private function processBatchResults(array $apiResults, array $pages): array
    {
        $similarities = [];
        foreach ($apiResults as $result) {
            $pageId = $result['pageId1'];
            $comparisonPageId = $result['pageId2'];
            $similarity = $result['similarity'];

            $similarities[$pageId][$comparisonPageId] = [
                'score' => $similarity,
                'semanticSimilarity' => $similarity,
                'recencyBoost' => 0, // À implémenter si nécessaire
                'relevance' => $this->determineRelevance($similarity)
            ];
        }
        return $similarities;
    }

    private function determineRelevance(float $similarity): string
    {
        if ($similarity > 0.7) return 'High';
        if ($similarity > 0.4) return 'Medium';
        return 'Low';
    }
}