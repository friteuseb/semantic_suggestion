<?php
namespace TalanHdf\SemanticSuggestion\Analyzer;

use TalanHdf\SemanticSuggestion\Service\ConfigurationService;
use TalanHdf\SemanticSuggestion\Service\LoggingService;
use TalanHdf\SemanticSuggestion\Service\DatabaseService;

class ContentAnalyzer implements AnalyzerInterface
{
    protected ConfigurationService $configurationService;
    protected LoggingService $loggingService;
    protected DatabaseService $databaseService;

    public function __construct(
        ConfigurationService $configurationService,
        LoggingService $loggingService,
        DatabaseService $databaseService
    ) {
        $this->configurationService = $configurationService;
        $this->loggingService = $loggingService;
        $this->databaseService = $databaseService;
    }

    public function analyze(string $content): array
    {
        $this->loggingService->logDebug('Starting content analysis');
        // Utilisez $this->configurationService->getSettings() pour accéder aux paramètres
        // Utilisez $this->databaseService->getQueryBuilder() si vous avez besoin d'accéder à la base de données
        // ...
    }

    private function calculateSimilarity(array $page1, array $page2): array
    {

    
        $words1 = $this->getWeightedWords($page1);
        $words2 = $this->getWeightedWords($page2);
    
        if (empty($words1) || empty($words2)) {
            $this->logger?->warning('One or both pages have no weighted words', [
                'page1' => $page1['uid'] ?? 'unknown',
                'page2' => $page2['uid'] ?? 'unknown'
            ]);
            return [
                'semanticSimilarity' => 0.0,
                'recencyBoost' => 0.0,
                'finalSimilarity' => 0.0
            ];
        }
    
        $allWords = array_unique(array_merge(array_keys($words1), array_keys($words2)));
    
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;
    
        foreach ($allWords as $word) {
            $weight1 = $words1[$word] ?? 0;
            $weight2 = $words2[$word] ?? 0;
            $dotProduct += $weight1 * $weight2;
            $magnitude1 += $weight1 * $weight1;
            $magnitude2 += $weight2 * $weight2;
        }
    

    
        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);
    
        if ($magnitude1 === 0 || $magnitude2 === 0) {
            $this->logger?->warning('Zero magnitude detected', [
                'magnitude1' => $magnitude1,
                'magnitude2' => $magnitude2
            ]);
            return [
                'semanticSimilarity' => 0.0,
                'recencyBoost' => 0.0,
                'finalSimilarity' => 0.0
            ];
        }
    
        $semanticSimilarity = $dotProduct / ($magnitude1 * $magnitude2);
    
        $recencyBoost = $this->calculateRecencyBoost($page1, $page2);
    
        $recencyWeight = $this->settings['recencyWeight'] ?? 0.2;
    
        $finalSimilarity = ($semanticSimilarity * (1 - $recencyWeight)) + ($recencyBoost * $recencyWeight);
    
        $fieldScores = [
            'title' => $this->calculateFieldSimilarity($page1['title'] ?? [], $page2['title'] ?? []),
            'description' => $this->calculateFieldSimilarity($page1['description'] ?? [], $page2['description'] ?? []),
            'keywords' => $this->calculateFieldSimilarity($page1['keywords'] ?? [], $page2['keywords'] ?? []),
            'content' => $this->calculateFieldSimilarity($page1['content'] ?? [], $page2['content'] ?? []),
        ];
  
        $this->logDebug('Similarity calculated', [
            'page1' => $page1['uid'],
            'page2' => $page2['uid'],
            'semanticSimilarity' => $semanticSimilarity,
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => $finalSimilarity
        ]);

        return [
            'semanticSimilarity' => $semanticSimilarity,
            'recencyBoost' => $recencyBoost,
            'finalSimilarity' => min($finalSimilarity, 1.0)
        ];
    }


    protected function getWeightedWords(array $pageData): array
    {
        $weightedWords = [];
        $language = $this->getCurrentLanguage();
    
        if ($this->settings['debugMode']) {
            $this->logDebug('Starting getWeightedWords', ['pageData' => $pageData, 'language' => $language]);
        }
    
        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            if (!isset($pageData[$field]['content']) || !is_string($pageData[$field]['content'])) {
                if ($this->settings['debugMode']) {
                    $this->logger->warning('Invalid or missing field data', ['field' => $field]);
                }
                continue;
            }
    
            $content = $pageData[$field]['content'];
            
            if ($this->settings['debugMode']) {
                $this->logDebug('Content for field', ['field' => $field, 'content' => $content]);
            }
    
            $words = array_count_values(str_word_count(strtolower($content), 1));
            
            if ($this->settings['debugMode']) {
                $this->logDebug('Word count', ['field' => $field, 'words' => $words]);
            }
    
            foreach ($words as $word => $count) {
                $weightedWords[$word] = ($weightedWords[$word] ?? 0) + ($count * $weight);
            }
        }
    
        if ($this->settings['debugMode']) {
            $this->logDebug('Final weighted words result', ['weightedWords' => $weightedWords]);
        }
    
        return $weightedWords;
    }


 

    private function calculateRecencyBoost(array $page1, array $page2): float
        {
            $now = time();
            $maxAge = 30 * 24 * 3600; // 30 jours en secondes
            $age1 = min($now - ($page1['content_modified_at'] ?? $now), $maxAge);
            $age2 = min($now - ($page2['content_modified_at'] ?? $now), $maxAge);
            
            // Normaliser les âges entre 0 et 1
            $normalizedAge1 = 1 - ($age1 / $maxAge);
            $normalizedAge2 = 1 - ($age2 / $maxAge);
            
            // Calculer la différence de récence
            return abs($normalizedAge1 - $normalizedAge2);
        }

    private function calculateFieldSimilarity($field1, $field2): float
    {
        if (!isset($field1['content']) || !isset($field2['content'])) {
            return 0.0;
        }
    
        $words1 = array_count_values(str_word_count(strtolower($field1['content']), 1));
        $words2 = array_count_values(str_word_count(strtolower($field2['content']), 1));
    
        $allWords = array_unique(array_merge(array_keys($words1), array_keys($words2)));
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;
    
        foreach ($allWords as $word) {
            $count1 = $words1[$word] ?? 0;
            $count2 = $words2[$word] ?? 0;
            $dotProduct += $count1 * $count2;
            $magnitude1 += $count1 * $count1;
            $magnitude2 += $count2 * $count2;
        }
    
        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);
    
        return ($magnitude1 > 0 && $magnitude2 > 0) ? $dotProduct / ($magnitude1 * $magnitude2) : 0.0;
    }


    private function findCommonKeywords(array $page1, array $page2): array
    {
        $keywords1 = isset($page1['keywords']['content']) ? array_map('trim', explode(',', strtolower($page1['keywords']['content']))) : [];
        $keywords2 = isset($page2['keywords']['content']) ? array_map('trim', explode(',', strtolower($page2['keywords']['content']))) : [];
    
        $commonKeywords = array_intersect($keywords1, $keywords2);
    
        $this->logDebug('Common keywords found', [
            'page1' => $page1['uid'] ?? 'unknown',
            'page2' => $page2['uid'] ?? 'unknown',
            'keywords1' => $keywords1,
            'keywords2' => $keywords2,
            'commonKeywords' => $commonKeywords
        ]);
    
        return $commonKeywords;
    }

    private function determineRelevance($similarity): string
    {
        if (is_array($similarity)) {
            $similarityValue = $similarity['finalSimilarity'] ?? 0;
        } else {
            $similarityValue = (float)$similarity;
        }

        if ($similarityValue > 0.7) {
            return 'High';
        } elseif ($similarityValue > 0.4) {
            return 'Medium';
        } else {
            return 'Low';
        }
    }

    // Déplacez ici d'autres méthodes utiles de votre ancien PageAnalysisService
    // comme findCommonKeywords, determineRelevance, etc.
}