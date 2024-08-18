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

            $this->logger->info('NLP analysis completed', ['result' => $result]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('NLP analysis failed', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->getDefaultAnalysis();
        }
    }

    public function calculateNlpSimilarity(array $nlpData1, array $nlpData2): float
    {
        $similarities = [];

        // Compare sentiments
        $similarities[] = $nlpData1['sentiment'] === $nlpData2['sentiment'] ? 1.0 : 0.0;

        // Compare categories
        $similarities[] = $nlpData1['category'] === $nlpData2['category'] ? 1.0 : 0.0;

        // Compare keyphrases
        $keywordSimilarity = $this->calculateJaccardSimilarity(
            $nlpData1['keyphrases'] ?? [],
            $nlpData2['keyphrases'] ?? []
        );
        $similarities[] = $keywordSimilarity;

        // Compare named entities
        $entitiesSimilarity = $this->calculateJaccardSimilarity(
            $nlpData1['named_entities'] ?? [],
            $nlpData2['named_entities'] ?? []
        );
        $similarities[] = $entitiesSimilarity;

        // Compare readability scores
        $readabilityDiff = abs(($nlpData1['readability_score'] ?? 0) - ($nlpData2['readability_score'] ?? 0));
        $readabilitySimilarity = 1 - min($readabilityDiff / 100, 1);
        $similarities[] = $readabilitySimilarity;

        // Calculate average similarity
        return array_sum($similarities) / count($similarities);
    }

    private function calculateJaccardSimilarity(array $set1, array $set2): float
    {
        $intersection = array_intersect($set1, $set2);
        $union = array_unique(array_merge($set1, $set2));
        
        if (empty($union)) {
            return 0.0;
        }
        
        return count($intersection) / count($union);
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