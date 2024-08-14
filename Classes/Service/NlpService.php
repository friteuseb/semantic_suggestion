<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use NlpTools\Tokenizers\WhitespaceTokenizer;
use NlpTools\Similarity\CosineSimilarity;
use NlpTools\Similarity\JaccardIndex;
use NlpTools\Stemmers\PorterStemmer;
use NlpTools\Classifiers\MultinomialNBClassifier;
use NlpTools\Models\FeatureBasedNB;
use NlpTools\Documents\TrainingSet;
use NlpTools\Documents\TokensDocument;

class NlpService implements SingletonInterface
{
    protected $enabled;
    protected $tokenizer;
    protected $stemmer;
    protected $cosineSimilarity;
    protected $jaccardIndex;

    public function __construct()
    {
        $this->enabled = (bool)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['semantic_suggestion']['enableNlp'] ?? false);
        $this->tokenizer = new WhitespaceTokenizer();
        $this->stemmer = new PorterStemmer();
        $this->cosineSimilarity = new CosineSimilarity();
        $this->jaccardIndex = new JaccardIndex();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function analyzeContent(string $content): array
    {
        if (empty(trim($content))) {
            return [
                'keywords' => [],
                'namedEntities' => [],
                'sentiment' => 'neutral',
                'category' => 'uncategorized',
                'readabilityScore' => 0
            ];
        }

        return [
            'keywords' => $this->extractKeywords($content),
            'namedEntities' => $this->extractNamedEntities($content),
            'sentiment' => $this->analyzeSentiment($content),
            'category' => $this->classifyText($content),
            'readabilityScore' => $this->calculateReadabilityScore($content)
        ];
    }



    protected function extractKeywords(string $content): array
    {
        $tokens = $this->tokenizer->tokenize($content);
        $stemmedTokens = array_map([$this->stemmer, 'stem'], $tokens);
        $wordFrequency = array_count_values($stemmedTokens);
        arsort($wordFrequency);
        return array_slice(array_keys($wordFrequency), 0, 5);
    }

    protected function extractNamedEntities(string $content): array
    {
        // Note: nlp-tools doesn't have a built-in NER, so we'll use a simple regex approach
        // For a more robust NER, consider using additional libraries or APIs
        preg_match_all('/\b(?:[A-Z][a-z]+ ){1,}\b/', $content, $matches);
        return array_slice(array_unique($matches[0]), 0, 5);
    }

    protected function analyzeSentiment(string $content): string
    {
        // For sentiment analysis, we'd need to train a classifier
        // This is a simplified version using keyword matching
        $positiveWords = ['good', 'great', 'excellent', 'amazing', 'wonderful'];
        $negativeWords = ['bad', 'poor', 'terrible', 'awful', 'horrible'];
        
        $tokens = $this->tokenizer->tokenize(strtolower($content));
        $positiveCount = count(array_intersect($tokens, $positiveWords));
        $negativeCount = count(array_intersect($tokens, $negativeWords));
        
        if ($positiveCount > $negativeCount) return 'positive';
        if ($negativeCount > $positiveCount) return 'negative';
        return 'neutral';
    }

    protected function classifyText(string $content): string
    {
        // For text classification, we'd need to train a classifier
        // This is a simplified version using keyword matching
        $categories = [
            'Technology' => ['computer', 'software', 'internet', 'digital', 'tech'],
            'Science' => ['research', 'experiment', 'theory', 'scientific', 'discovery'],
            'Politics' => ['government', 'election', 'policy', 'politician', 'vote'],
            'Entertainment' => ['movie', 'music', 'celebrity', 'film', 'concert']
        ];
        
        $tokens = $this->tokenizer->tokenize(strtolower($content));
        $scores = [];
        
        foreach ($categories as $category => $keywords) {
            $scores[$category] = count(array_intersect($tokens, $keywords));
        }
        
        arsort($scores);
        return key($scores);
    }

    public function calculateNlpSimilarity(array $nlpData1, array $nlpData2): float
    {
        $keywordSimilarity = $this->safeJaccardSimilarity($nlpData1['keywords'] ?? [], $nlpData2['keywords'] ?? []);
        $entitySimilarity = $this->safeJaccardSimilarity($nlpData1['namedEntities'] ?? [], $nlpData2['namedEntities'] ?? []);
        $sentimentSimilarity = ($nlpData1['sentiment'] ?? '') === ($nlpData2['sentiment'] ?? '') ? 1.0 : 0.0;
        $categorySimilarity = ($nlpData1['category'] ?? '') === ($nlpData2['category'] ?? '') ? 1.0 : 0.0;

        return ($keywordSimilarity + $entitySimilarity + $sentimentSimilarity + $categorySimilarity) / 4;
    }

    protected function safeJaccardSimilarity(array $set1, array $set2): float
    {
        if (empty($set1) && empty($set2)) {
            return 1.0; // Considérer deux ensembles vides comme identiques
        }
        if (empty($set1) || empty($set2)) {
            return 0.0; // Si un ensemble est vide et l'autre non, ils sont complètement différents
        }
        return $this->jaccardIndex->similarity($set1, $set2);
    }

    protected function calculateReadabilityScore(string $content): float
    {
        $sentences = preg_split('/[.!?]+/', $content);
        $wordCount = str_word_count($content);
        
        if ($wordCount === 0 || empty($sentences)) {
            return 0.0;
        }
        
        $syllableCount = $this->countSyllables($content);
        
        $averageWordsPerSentence = $wordCount / count($sentences);
        $averageSyllablesPerWord = $syllableCount / $wordCount;
        
        // Flesch-Kincaid Grade Level
        return 0.39 * $averageWordsPerSentence + 11.8 * $averageSyllablesPerWord - 15.59;
    }

    protected function countSyllables(string $word): int
    {
        $word = strtolower($word);
        $word = preg_replace('/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word);
        $word = preg_replace('/^y/', '', $word);
        return max(1, preg_match_all('/[aeiouy]{1,2}/', $word));
    }
}