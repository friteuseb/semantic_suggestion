<?php
namespace TalanHdf\SemanticSuggestion\Analyzer;

interface AnalyzerInterface
{
    /**
     * @param string $content Le contenu à analyser
     * @return array Un tableau associatif représentant l'analyse (par exemple, des mots-clés pondérés)
     */
    public function analyze(string $content): array;

    /**
     * @param array $analysis1 Résultat d'une première analyse
     * @param array $analysis2 Résultat d'une seconde analyse
     * @return float Un score de similarité entre 0 et 1
     */
    public function calculateSimilarity(array $analysis1, array $analysis2): float;
}