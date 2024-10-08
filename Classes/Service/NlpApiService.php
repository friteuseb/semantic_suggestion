<?php
namespace TalanHdf\SemanticSuggestion\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class NlpApiService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected Client $client;
    protected string $baseUrl = 'https://nlpservice.semantic-suggestion.com/api';

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function addTexts(array $items): array
    {
        try {
            $response = $this->client->post($this->baseUrl . '/add_texts', [
                'json' => ['items' => $items]
            ]);
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->error('Error adding texts to FAISS', ['exception' => $e->getMessage()]);
            return [];
        }
    }

    public function findSimilar(string $id, int $k): array
    {
        try {
            $response = $this->client->post($this->baseUrl . '/find_similar', [
                'json' => ['id' => $id, 'k' => $k]
            ]);
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->error('Error finding similar texts', ['exception' => $e->getMessage()]);
            return [];
        }
    }

    public function getStatus(): array
    {
        try {
            $response = $this->client->get($this->baseUrl . '/faiss_similarity_status');
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->error('Error getting FAISS status', ['exception' => $e->getMessage()]);
            return [];
        }
    }

    public function clearIndex(): bool
    {
        try {
            $response = $this->client->post($this->baseUrl . '/clear_faiss_index');
            $result = json_decode($response->getBody(), true);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            $this->logger->error('Error clearing FAISS index', ['exception' => $e->getMessage()]);
            return false;
        }
    }
}