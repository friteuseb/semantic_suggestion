<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class NlpApiService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected RequestFactory $requestFactory;
    protected string $apiUrl;
    protected string $method;
    protected int $maxRetries = 3;

    public function __construct(RequestFactory $requestFactory, ExtensionConfiguration $extensionConfiguration)
    {
        $this->requestFactory = $requestFactory;
        $config = $extensionConfiguration->get('semantic_suggestion');
        $this->apiUrl = $config['settings']['nlpApiUrl'] ?? 'https://nlpservice.semantic-suggestion.com/api/batch_similarity';
        $this->method = $config['settings']['similarityMethod'] ?? 'cosine';
    }

    public function getBatchSimilarity(array $textPairs): array
    {
        $data = [
            'text_pairs' => array_map(function($pair) {
                return [
                    'text1' => base64_encode($pair['text1']),
                    'text2' => base64_encode($pair['text2'])
                ];
            }, $textPairs),
            'method' => $this->method
        ];

        $result = $this->callApiWithRetry($data);
        
        if ($result === null) {
            $this->logger->info('Using fallback similarity calculation');
            $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['semantic_suggestion']['usedFallback'] = true;
            return $this->calculateLocalSimilarity($textPairs);
        }
        
        return $result['results'] ?? [];
    }

    private function callApiWithRetry($data)
    {
        $attempt = 0;
        while ($attempt < $this->maxRetries) {
            try {
                $startTime = microtime(true);
                $response = $this->requestFactory->request($this->apiUrl, 'POST', [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => json_encode($data),
                    'timeout' => 30 // Timeout de 30 secondes
                ]);
                $endTime = microtime(true);

                $this->logger->debug('API call details', [
                    'attempt' => $attempt + 1,
                    'responseTime' => $endTime - $startTime,
                    'dataSize' => strlen(json_encode($data)),
                    'status' => $response->getStatusCode()
                ]);

                if ($response->getStatusCode() === 200) {
                    return json_decode($response->getBody()->getContents(), true);
                }

                $this->logger->warning('API request failed, retrying...', [
                    'attempt' => $attempt + 1,
                    'status' => $response->getStatusCode()
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Exception in API call', ['exception' => $e->getMessage()]);
            }

            $attempt++;
            sleep(1); // Attendre 1 seconde avant de réessayer
        }

        return null; // Retourner null si toutes les tentatives échouent
    }

    private function calculateLocalSimilarity(array $textPairs): array
    {
        $results = [];
        foreach ($textPairs as $index => $pair) {
            $similarity = $this->cosineSimilarity($pair['text1'], $pair['text2']);
            $results[] = [
                'similarity' => $similarity,
                'pageId1' => $pair['pageId1'] ?? $index,
                'pageId2' => $pair['pageId2'] ?? $index
            ];
        }
        return $results;
    }

    private function cosineSimilarity(string $text1, string $text2): float
    {
        $vector1 = $this->textToVector($text1);
        $vector2 = $this->textToVector($text2);

        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        foreach (array_unique(array_merge(array_keys($vector1), array_keys($vector2))) as $word) {
            $dotProduct += ($vector1[$word] ?? 0) * ($vector2[$word] ?? 0);
            $magnitude1 += pow($vector1[$word] ?? 0, 2);
            $magnitude2 += pow($vector2[$word] ?? 0, 2);
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 > 0 && $magnitude2 > 0) {
            return $dotProduct / ($magnitude1 * $magnitude2);
        }

        return 0;
    }

    private function textToVector(string $text): array
    {
        $words = preg_split('/\W+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return array_count_values($words);
    }
}