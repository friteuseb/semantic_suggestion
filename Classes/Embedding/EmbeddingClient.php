<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Embedding;

use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Client for any embeddings endpoint following the OpenAI format
 * (POST {model, input: [...]} -> {data: [{index, embedding}]}): Mistral, OpenAI,
 * and self-hosted servers such as Ollama or vLLM.
 */
class EmbeddingClient
{
    /**
     * Characters kept per text. Both default models accept 8,192 tokens per input;
     * 8,000 characters stay far below that in any language, and the start of a
     * page (title, description, introduction) is what describes it best.
     */
    public const MAX_TEXT_CHARACTERS = 8000;

    /**
     * Limits of one request. Mistral rejects a request whose inputs add up to too
     * many tokens; 24,000 characters keep a batch around 8,000 tokens at most.
     */
    private const MAX_BATCH_INPUTS = 32;
    private const MAX_BATCH_CHARACTERS = 24000;

    public function __construct(
        private readonly RequestFactory $requestFactory
    ) {
    }

    /**
     * Vectors of the given texts, in the same order and with the same keys.
     *
     * @param array<int|string, string> $texts non-empty texts
     * @return array<int|string, list<float>>
     * @throws EmbeddingException
     */
    public function embed(EmbeddingConfiguration $configuration, array $texts): array
    {
        $vectors = [];
        foreach ($this->buildBatches($texts) as $batch) {
            $batchVectors = $this->request($configuration, array_values($batch));
            foreach (array_keys($batch) as $position => $key) {
                $vectors[$key] = $batchVectors[$position];
            }
        }

        return $vectors;
    }

    /**
     * @param array<int|string, string> $texts
     * @return list<array<int|string, string>>
     */
    private function buildBatches(array $texts): array
    {
        $batches = [];
        $batch = [];
        $characters = 0;

        foreach ($texts as $key => $text) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARACTERS);
            $length = mb_strlen($text);
            if ($batch !== [] && (count($batch) >= self::MAX_BATCH_INPUTS || $characters + $length > self::MAX_BATCH_CHARACTERS)) {
                $batches[] = $batch;
                $batch = [];
                $characters = 0;
            }
            $batch[$key] = $text;
            $characters += $length;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * @param list<string> $inputs
     * @return list<list<float>>
     * @throws EmbeddingException
     */
    private function request(EmbeddingConfiguration $configuration, array $inputs): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($configuration->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $configuration->apiKey;
        }

        try {
            $response = $this->requestFactory->request($configuration->endpoint, 'POST', [
                'headers' => $headers,
                'body' => json_encode(['model' => $configuration->model, 'input' => $inputs], JSON_THROW_ON_ERROR),
                'timeout' => $configuration->timeout,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new EmbeddingException(
                sprintf('Embedding request to %s failed: %s', $configuration->endpoint, $e->getMessage()),
                1764064801,
                $e
            );
        }

        $body = (string)$response->getBody();
        if ($response->getStatusCode() !== 200) {
            throw new EmbeddingException(
                sprintf('Embedding API answered HTTP %d: %s', $response->getStatusCode(), mb_substr($body, 0, 300)),
                1764064802
            );
        }

        $data = json_decode($body, true);
        if (!is_array($data['data'] ?? null) || count($data['data']) !== count($inputs)) {
            throw new EmbeddingException('Embedding API answer has no "data" list of the expected length', 1764064803);
        }

        $vectors = [];
        foreach ($data['data'] as $position => $item) {
            $embedding = $item['embedding'] ?? null;
            if (!is_array($embedding) || $embedding === []) {
                throw new EmbeddingException('Embedding API answer holds an empty or invalid vector', 1764064804);
            }
            $vectors[(int)($item['index'] ?? $position)] = array_map('floatval', $embedding);
        }
        ksort($vectors);

        return array_values($vectors);
    }
}
