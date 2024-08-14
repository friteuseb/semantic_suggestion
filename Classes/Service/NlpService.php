<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class NlpService implements SingletonInterface
{
    protected $enabled;

    public function __construct()
    {
        $this->enabled = (bool)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['semantic_suggestion']['enableNlp'] ?? false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function analyzeContent(string $content): array
    {
        if (!$this->enabled) {
            return [];
        }

        return [
            'keywords' => $this->extractKeywords($content),
            'namedEntities' => $this->extractNamedEntities($content),
            'sentiment' => $this->analyzeSentiment($content),
            'category' => $this->classifyText($content),
        ];
    }

    protected function extractKeywords(string $content): array
    {
        // Implémentation simple de l'extraction de mots-clés
        $words = str_word_count(strtolower($content), 1);
        $wordCounts = array_count_values($words);
        arsort($wordCounts);
        return array_slice(array_keys($wordCounts), 0, 5);
    }

    protected function extractNamedEntities(string $content): array
    {
        // Implémentation simple de l'extraction d'entités nommées
        // Vous pouvez améliorer cela avec une bibliothèque NLP plus avancée
        $entities = [];
        if (preg_match_all('/[A-Z][a-z]+ (?:[A-Z][a-z]+)+/', $content, $matches)) {
            $entities = array_unique($matches[0]);
        }
        return array_slice($entities, 0, 5);
    }

    protected function analyzeSentiment(string $content): string
    {
        // Implémentation simple de l'analyse de sentiment
        $positiveWords = ['good', 'great', 'excellent', 'amazing', 'wonderful'];
        $negativeWords = ['bad', 'poor', 'terrible', 'awful', 'horrible'];
        
        $positiveCount = 0;
        $negativeCount = 0;
        
        $words = str_word_count(strtolower($content), 1);
        foreach ($words as $word) {
            if (in_array($word, $positiveWords)) $positiveCount++;
            if (in_array($word, $negativeWords)) $negativeCount++;
        }
        
        if ($positiveCount > $negativeCount) return 'positive';
        if ($negativeCount > $positiveCount) return 'negative';
        return 'neutral';
    }

    protected function classifyText(string $content): string
    {
        // Implémentation simple de la classification de texte
        $categories = [
            'Technology' => ['computer', 'software', 'internet', 'digital', 'tech'],
            'Science' => ['research', 'experiment', 'theory', 'scientific', 'discovery'],
            'Politics' => ['government', 'election', 'policy', 'politician', 'vote'],
            'Entertainment' => ['movie', 'music', 'celebrity', 'film', 'concert']
        ];
        
        $scores = array_fill_keys(array_keys($categories), 0);
        $words = str_word_count(strtolower($content), 1);
        
        foreach ($words as $word) {
            foreach ($categories as $category => $keywords) {
                if (in_array($word, $keywords)) $scores[$category]++;
            }
        }
        
        arsort($scores);
        return key($scores);
    }

    public function calculateNlpSimilarity(array $nlpData1, array $nlpData2): float
    {
        if (!$this->enabled || empty($nlpData1) || empty($nlpData2)) {
            return 0.0;
        }

        $keywordSimilarity = $this->calculateJaccardSimilarity($nlpData1['keywords'], $nlpData2['keywords']);
        $entitySimilarity = $this->calculateJaccardSimilarity($nlpData1['namedEntities'], $nlpData2['namedEntities']);
        $sentimentSimilarity = $nlpData1['sentiment'] === $nlpData2['sentiment'] ? 1.0 : 0.0;
        $categorySimilarity = $nlpData1['category'] === $nlpData2['category'] ? 1.0 : 0.0;

        return ($keywordSimilarity + $entitySimilarity + $sentimentSimilarity + $categorySimilarity) / 4;
    }

    protected function calculateJaccardSimilarity(array $set1, array $set2): float
    {
        $intersection = count(array_intersect($set1, $set2));
        $union = count(array_unique(array_merge($set1, $set2)));
        return $union > 0 ? $intersection / $union : 0.0;
    }
}