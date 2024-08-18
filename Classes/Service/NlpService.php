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
            $this->logger->info('Starting NLP analysis', ['content_length' => strlen($content)]);
            
            $response = $this->client->post($this->apiUrl, [
                'json' => ['content' => base64_encode($content)]
            ]);

            $result = json_decode($response->getBody(), true);

            if (isset($result['error'])) {
                $this->logger->error('NLP analysis failed', ['error' => $result['error']]);
                return $this->getDefaultAnalysis();
            }

            // Assurez-vous que tous les champs attendus sont présents et du bon type
            $processedResult = [
                'sentiment' => $this->ensureString($result['sentiment'] ?? 'neutral'),
                'keyphrases' => $this->ensureArray($result['keyphrases'] ?? []),
                'category' => $this->ensureString($result['category'] ?? 'Non catégorisé'),
                'named_entities' => $this->ensureArray($result['named_entities'] ?? []),
                'readability_score' => $this->ensureFloat($result['readability_score'] ?? 0.0),
                'word_count' => $this->ensureInt($result['word_count'] ?? 0),
                'sentence_count' => $this->ensureInt($result['sentence_count'] ?? 0),
                'average_sentence_length' => $this->ensureFloat($result['average_sentence_length'] ?? 0.0),
                'language' => $this->ensureString($result['language'] ?? 'unknown')
            ];

            $this->logger->info('NLP analysis completed', ['result' => $processedResult]);

            return $processedResult;
        } catch (\Exception $e) {
            $this->logger->error('NLP analysis failed', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->getDefaultAnalysis();
        }
    }


 
    
    public function calculateNlpSimilarity(array $nlpData1, array $nlpData2): float
    {
        $similarities = [];

        // Sentiment similarity (weight: 0.2)
        $similarities[] = 0.2 * ($this->ensureString($nlpData1['sentiment'] ?? '') === $this->ensureString($nlpData2['sentiment'] ?? '') ? 1.0 : 0.0);

        // Keyphrases similarity (weight: 0.3)
        $similarities[] = 0.3 * $this->calculateJaccardSimilarity(
            $this->ensureArray($nlpData1['keyphrases'] ?? []),
            $this->ensureArray($nlpData2['keyphrases'] ?? [])
        );

        // Category similarity (weight: 0.2)
        $similarities[] = 0.2 * ($this->ensureString($nlpData1['category'] ?? '') === $this->ensureString($nlpData2['category'] ?? '') ? 1.0 : 0.0);

        // Named entities similarity (weight: 0.2)
        $similarities[] = 0.2 * $this->calculateJaccardSimilarity(
            $this->extractEntityTexts($this->ensureArray($nlpData1['named_entities'] ?? [])),
            $this->extractEntityTexts($this->ensureArray($nlpData2['named_entities'] ?? []))
        );

        // Readability score similarity (weight: 0.1)
        $readabilityDiff = abs($this->ensureFloat($nlpData1['readability_score'] ?? 0) - $this->ensureFloat($nlpData2['readability_score'] ?? 0));
        $similarities[] = 0.1 * (1 - min($readabilityDiff / 100, 1));

        $this->logger->debug('NLP similarity calculation', [
            'similarities' => $similarities,
            'total' => array_sum($similarities)
        ]);

        return array_sum($similarities);
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