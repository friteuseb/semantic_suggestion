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
        $this->apiUrl = $config['settings']['nlpApiUrl'] ?? 'https://nlpservice.semantic-suggestion.com/api/similarity';
        $this->method = $config['settings']['similarityMethod'] ?? 'cosine';
    }

    public function getSimilarity(string $text1, string $text2): float
    {
        try {
            $response = $this->requestFactory->request($this->apiUrl, 'POST', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'text1' => base64_encode($text1),
                    'text2' => base64_encode($text2),
                    'method' => $this->method
                ])
            ]);

            if ($response->getStatusCode() === 200) {
                $result = json_decode($response->getBody()->getContents(), true);
                return $result['similarity'] ?? 0;
            }

            $this->logger->error('Failed to get similarity from API', [
                'status' => $response->getStatusCode(),
                'body' => $response->getBody()->getContents()
            ]);
            return 0;
        } catch (\Exception $e) {
            $this->logger->error('Exception occurred while calling NLP API', ['exception' => $e->getMessage()]);
            return 0;
        }
    }
}