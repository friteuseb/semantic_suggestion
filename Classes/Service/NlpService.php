<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use GuzzleHttp\Client;

class NlpService implements SingletonInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $client;
    protected $apiUrl;
    protected $enabled;

    public function __construct(array $config = null)
    {
        if ($config === null) {
            $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
            $config = $extensionConfiguration->get('semantic_suggestion');
        }
        
        $this->enabled = (bool)($config['enableNlpAnalysis'] ?? false);
        
        // Utiliser host.docker.internal si nous sommes dans DDEV
        if (getenv('IS_DDEV_PROJECT') === 'true') {
            $this->apiUrl = str_replace('localhost', 'host.docker.internal', $config['pythonApiUrl'] ?? 'http://localhost:5000/analyze');
        } else {
            $this->apiUrl = $config['pythonApiUrl'] ?? 'http://0.0.0.0:5000/analyze';
        }

        if ($this->enabled) {
            $this->client = new Client();
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getApiUrl(): string
{
    return $this->apiUrl;
}


    public function analyzeContent(string $content): array
    {
        if (!$this->isEnabled() || strlen(trim($content)) < 20) {
            $this->logger->info('Content too short or NLP disabled, returning default analysis', ['content_length' => strlen($content)]);
            return $this->getDefaultAnalysis();
        }

        try {
            $this->logger->info('Starting enhanced NLP analysis', ['content_length' => strlen($content)]);
            
            $response = $this->client->post($this->apiUrl, [
                'json' => [
                    'content' => base64_encode($content),
                    'analyze_lexical_diversity' => true,
                    'analyze_top_n_grams' => true,
                    'analyze_semantic_coherence' => true,
                    'analyze_sentiment_distribution' => true
                ]
            ]);

            $result = json_decode($response->getBody(), true);

            if (isset($result['error'])) {
                $this->logger->error('NLP analysis failed', ['error' => $result['error']]);
                return $this->getDefaultAnalysis();
            }

            $enhancedResult = [
                'sentiment' => $result['sentiment'] ?? 'neutral',
                'keyphrases' => $result['keyphrases'] ?? [],
                'category' => $result['category'] ?? 'Non catégorisé',
                'named_entities' => $result['named_entities'] ?? [],
                'readability_score' => $result['readability_score'] ?? 0.0,
                'word_count' => $result['word_count'] ?? 0,
                'sentence_count' => $result['sentence_count'] ?? 0,
                'average_sentence_length' => $result['average_sentence_length'] ?? 0.0,
                'language' => $result['language'] ?? 'unknown',
                'lexical_diversity' => $result['lexical_diversity'] ?? 0.0,
                'top_n_grams' => $result['top_n_grams'] ?? [],
                'semantic_coherence' => $result['semantic_coherence'] ?? 0.0,
                'sentiment_distribution' => $result['sentiment_distribution'] ?? []
            ];

            $this->logger->info('Enhanced NLP analysis completed', ['result' => $enhancedResult]);

            return $enhancedResult;
        } catch (\Exception $e) {
            $this->logger->error('NLP analysis failed', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->getDefaultAnalysis();
        }
    }


 
    public function calculateNlpSimilarity(array $nlpData1, array $nlpData2): float
    {
        $similarities = [];

        // Existing similarities
        $similarities[] = 0.15 * ($this->ensureString($nlpData1['sentiment'] ?? '') === $this->ensureString($nlpData2['sentiment'] ?? '') ? 1.0 : 0.0);
        $similarities[] = 0.2 * $this->calculateJaccardSimilarity($this->ensureArray($nlpData1['keyphrases'] ?? []), $this->ensureArray($nlpData2['keyphrases'] ?? []));
        $similarities[] = 0.1 * ($this->ensureString($nlpData1['category'] ?? '') === $this->ensureString($nlpData2['category'] ?? '') ? 1.0 : 0.0);
        $similarities[] = 0.1 * $this->calculateJaccardSimilarity($this->extractEntityTexts($this->ensureArray($nlpData1['named_entities'] ?? [])), $this->extractEntityTexts($this->ensureArray($nlpData2['named_entities'] ?? [])));

        // New similarities
        $similarities[] = 0.1 * (1 - abs($this->ensureFloat($nlpData1['lexical_diversity'] ?? 0) - $this->ensureFloat($nlpData2['lexical_diversity'] ?? 0)));
        $similarities[] = 0.15 * $this->calculateJaccardSimilarity($this->ensureArray($nlpData1['top_n_grams'] ?? []), $this->ensureArray($nlpData2['top_n_grams'] ?? []));
        $similarities[] = 0.1 * (1 - abs($this->ensureFloat($nlpData1['semantic_coherence'] ?? 0) - $this->ensureFloat($nlpData2['semantic_coherence'] ?? 0)));
        $similarities[] = 0.1 * $this->calculateCosineSimilarity($this->ensureArray($nlpData1['sentiment_distribution'] ?? []), $this->ensureArray($nlpData2['sentiment_distribution'] ?? []));

        $this->logger->debug('NLP similarity calculation', [
            'similarities' => $similarities,
            'total' => array_sum($similarities)
        ]);

        return array_sum($similarities);
    }

    private function calculateCosineSimilarity(array $vector1, array $vector2): float
    {
        if (empty($vector1) || empty($vector2)) {
            $this->logger->warning('Cosine similarity calculation attempted with empty vector(s)', [
                'vector1' => $vector1,
                'vector2' => $vector2
            ]);
            return 0.0;
        }

        $keys = array_unique(array_merge(array_keys($vector1), array_keys($vector2)));
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        foreach ($keys as $key) {
            $v1 = $this->ensureFloat($vector1[$key] ?? 0);
            $v2 = $this->ensureFloat($vector2[$key] ?? 0);
            $dotProduct += $v1 * $v2;
            $magnitude1 += $v1 * $v1;
            $magnitude2 += $v2 * $v2;
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 === 0.0 && $magnitude2 === 0.0) {
            $this->logger->info('Both vectors have zero magnitude, returning similarity of 1.0', [
                'vector1' => $vector1,
                'vector2' => $vector2
            ]);
            return 1.0;  // Les deux vecteurs sont identiquement nuls, on peut les considérer comme similaires
        } elseif ($magnitude1 === 0.0 || $magnitude2 === 0.0) {
            $this->logger->info('One vector has zero magnitude, returning similarity of 0.0', [
                'magnitude1' => $magnitude1,
                'magnitude2' => $magnitude2
            ]);
            return 0.0;  // Un des vecteurs est nul, l'autre non, ils sont donc différents
        }

        $similarity = $dotProduct / ($magnitude1 * $magnitude2);

        // Ensure the result is between 0 and 1
        return max(0, min(1, $similarity));
    }

    private function calculateJaccardSimilarity(array $set1, array $set2): float
    {
        if (empty($set1) && empty($set2)) {
            return 1.0;  // Two empty sets are considered identical
        }

        $intersection = array_intersect($set1, $set2);
        $union = array_unique(array_merge($set1, $set2));
        
        if (empty($union)) {
            return 0.0;
        }
        
        return count($intersection) / count($union);
    }

    private function extractEntityTexts(array $entities): array
    {
        return array_map(function($entity) {
            return is_array($entity) ? ($entity['text'] ?? '') : $entity;
        }, $entities);
    }

    private function ensureArray($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return (json_last_error() === JSON_ERROR_NONE) ? $decoded : [$value];
        }
        return is_array($value) ? $value : [$value];
    }

    private function ensureString($value): string
    {
        return is_array($value) ? json_encode($value) : (string)$value;
    }

    private function ensureFloat($value): float
    {
        return is_numeric($value) ? (float)$value : 0.0;
    }


    private function ensureInt($value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }


 
    protected function getDefaultAnalysis(): array
    {
        return [
            'sentiment' => 'neutral',
            'keyphrases' => [],
            'category' => 'Non catégorisé',
            'named_entities' => [],
            'readability_score' => 0.0,
            'word_count' => 0,
            'sentence_count' => 0,
            'average_sentence_length' => 0,
            'language' => 'unknown'
        ];
    }
}