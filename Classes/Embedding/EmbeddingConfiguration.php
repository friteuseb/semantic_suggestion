<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Embedding;

/**
 * Embedding provider settings, read from the extension configuration
 * (Settings > Extension Configuration > semantic_suggestion > embedding).
 *
 * Never from TypoScript: the API key must not end up in a template record.
 */
final class EmbeddingConfiguration
{
    public const PROVIDER_TFIDF = 'tfidf';
    public const PROVIDER_MISTRAL = 'mistral';
    public const PROVIDER_OPENAI = 'openai';
    public const PROVIDER_CUSTOM = 'custom';

    private const DEFAULTS = [
        self::PROVIDER_MISTRAL => [
            'endpoint' => 'https://api.mistral.ai/v1/embeddings',
            'model' => 'mistral-embed',
        ],
        self::PROVIDER_OPENAI => [
            'endpoint' => 'https://api.openai.com/v1/embeddings',
            'model' => 'text-embedding-3-small',
        ],
    ];

    public function __construct(
        public readonly string $provider,
        public readonly string $endpoint,
        public readonly string $model,
        public readonly string $apiKey,
        public readonly int $timeout
    ) {
    }

    /**
     * @param array<string, mixed> $extensionConfiguration whole configuration of the extension
     */
    public static function fromExtensionConfiguration(array $extensionConfiguration): self
    {
        $settings = is_array($extensionConfiguration['embedding'] ?? null) ? $extensionConfiguration['embedding'] : [];

        $provider = strtolower(trim((string)($settings['provider'] ?? '')));
        if (!in_array($provider, [self::PROVIDER_MISTRAL, self::PROVIDER_OPENAI, self::PROVIDER_CUSTOM], true)) {
            $provider = self::PROVIDER_TFIDF;
        }

        $endpoint = trim((string)($settings['endpoint'] ?? ''));
        $model = trim((string)($settings['model'] ?? ''));
        if (isset(self::DEFAULTS[$provider])) {
            // The endpoint field only applies to a custom provider.
            $endpoint = self::DEFAULTS[$provider]['endpoint'];
            $model = $model !== '' ? $model : self::DEFAULTS[$provider]['model'];
        }

        return new self(
            $provider,
            $endpoint,
            $model,
            self::resolveApiKey(trim((string)($settings['apiKey'] ?? ''))),
            max(1, (int)($settings['timeout'] ?? 30))
        );
    }

    /**
     * True when similarities must come from an embedding API rather than TF-IDF.
     * A hosted provider without key, or a custom one without endpoint or model,
     * counts as not configured.
     */
    public function isEnabled(): bool
    {
        return match ($this->provider) {
            self::PROVIDER_MISTRAL, self::PROVIDER_OPENAI => $this->apiKey !== '',
            self::PROVIDER_CUSTOM => $this->endpoint !== '' && $this->model !== '',
            default => false,
        };
    }

    /**
     * Identifies the vector space. Vectors from two different identifiers must
     * never be compared, so it keys both the vector cache and the analysis cache.
     */
    public function getIdentifier(): string
    {
        return $this->isEnabled() ? $this->provider . ':' . $this->model : self::PROVIDER_TFIDF;
    }

    /**
     * "env:MISTRAL_API_KEY" reads the key from that environment variable, so the
     * key itself stays out of config/system/settings.php, which is often versioned.
     */
    private static function resolveApiKey(string $value): string
    {
        if (str_starts_with($value, 'env:')) {
            $fromEnvironment = getenv(substr($value, 4));
            return is_string($fromEnvironment) ? trim($fromEnvironment) : '';
        }

        return $value;
    }
}
