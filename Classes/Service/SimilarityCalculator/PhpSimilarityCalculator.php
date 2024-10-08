<?php
namespace TalanHdf\SemanticSuggestion\Service\SimilarityCalculator;

class PhpSimilarityCalculator implements SimilarityCalculatorInterface
{
    public function calculateSimilarity(array $pages): array
    {
        $similarities = [];
        foreach ($pages as $pageId => $pageData) {
            $similarities[$pageId] = [];
            foreach ($pages as $comparisonPageId => $comparisonPageData) {
                if ($pageId !== $comparisonPageId) {
                    $similarities[$pageId][$comparisonPageId] = $this->calculatePairSimilarity($pageData, $comparisonPageData);
                }
            }
        }
        return $similarities;
    }

    private function calculatePairSimilarity(array $page1, array $page2): array
    {
        // Implémentez ici votre logique de calcul de similarité PHP
        // Ceci est un exemple simplifié
        $similarity = $this->cosineSimilarity($page1['content'], $page2['content']);
        return [
            'score' => $similarity,
            'semanticSimilarity' => $similarity,
            'recencyBoost' => 0, // À implémenter si nécessaire
            'relevance' => $this->determineRelevance($similarity)
        ];
    }

    private function cosineSimilarity(string $text1, string $text2): float
    {
        // Implémentez ici le calcul de similarité cosinus
        // Ceci est un exemple très simplifié
        $words1 = array_count_values(str_word_count(strtolower($text1), 1));
        $words2 = array_count_values(str_word_count(strtolower($text2), 1));
        
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        foreach (array_unique(array_merge(array_keys($words1), array_keys($words2))) as $word) {
            $dotProduct += ($words1[$word] ?? 0) * ($words2[$word] ?? 0);
            $magnitude1 += pow($words1[$word] ?? 0, 2);
            $magnitude2 += pow($words2[$word] ?? 0, 2);
        }

        $magnitude = sqrt($magnitude1) * sqrt($magnitude2);

        return $magnitude ? $dotProduct / $magnitude : 0;
    }

    private function determineRelevance(float $similarity): string
    {
        if ($similarity > 0.7) return 'High';
        if ($similarity > 0.4) return 'Medium';
        return 'Low';
    }
}