<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TalanHdf\SemanticSuggestion\Service\SimilarityCalculator\SimilarityCalculatorInterface;
use TalanHdf\SemanticSuggestion\Service\SimilarityCalculator\PhpSimilarityCalculator;
use TalanHdf\SemanticSuggestion\Service\SimilarityCalculator\FaissSimilarityCalculator;

class SimilarityCalculatorFactory
{
    public function create(string $type, array $dependencies = []): SimilarityCalculatorInterface
    {
        switch ($type) {
            case 'php':
                return new PhpSimilarityCalculator();
            case 'faiss':
                if (!isset($dependencies['nlpApiService'])) {
                    throw new \InvalidArgumentException('NlpApiService is required for FAISS calculator');
                }
                return new FaissSimilarityCalculator($dependencies['nlpApiService']);
            default:
                throw new \InvalidArgumentException("Unknown similarity calculator type: $type");
        }
    }
}