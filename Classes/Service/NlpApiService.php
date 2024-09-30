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

    public function __construct(RequestFactory $requestFactory, ExtensionConfiguration $extensionConfiguration)
    {
        $this->requestFactory = $requestFactory;
        $config = $extensionConfiguration->get('semantic_suggestion');
        $this->apiUrl = $config['settings']['nlpApiUrl'] ?? 'https://nlpservice.semantic-suggestion.com/api/batch_similarity';
        $this->method = $config['settings']['similarityMethod'] ?? 'cosine';
    }

    public function getBatchSimilarity(array $textPairs): array
    {
        try {
            $data = [
                'text_pairs' => array_map(function($pair) {
                    return [
                        'text1' => base64_encode($pair['text1']),
                        'text2' => base64_encode($pair['text2'])
                    ];
                }, $textPairs),
                'method' => $this->method
            ];

            $response = $this->requestFactory->request($this->apiUrl, 'POST', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($data)
            ]);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }

            $this->logger->error('Failed to get batch similarity from API', [
                'status' => $response->getStatusCode(),
                'body' => $response->getBody()->getContents()
            ]);
            return [];
        } catch (\Exception $e) {
            $this->logger->error('Exception occurred while calling NLP API', ['exception' => $e->getMessage()]);
            return [];
        }
    }
}