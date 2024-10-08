<?php
namespace TalanHdf\SemanticSuggestion\Service\SimilarityCalculator;

interface SimilarityCalculatorInterface
{
    public function calculateSimilarity(array $pages): array;
}