<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Tests\Unit;

use TalanHdf\SemanticSuggestion\Embedding\EmbeddingConfiguration;
use TalanHdf\SemanticSuggestion\Embedding\EmbeddingSimilarity;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class EmbeddingTest extends UnitTestCase
{
    public function testTfidfIsTheDefault(): void
    {
        $configuration = EmbeddingConfiguration::fromExtensionConfiguration([]);

        self::assertFalse($configuration->isEnabled());
        self::assertSame('tfidf', $configuration->getIdentifier());
    }

    public function testHostedProviderNeedsAKey(): void
    {
        $withoutKey = EmbeddingConfiguration::fromExtensionConfiguration(['embedding' => ['provider' => 'mistral']]);
        $withKey = EmbeddingConfiguration::fromExtensionConfiguration(['embedding' => ['provider' => 'mistral', 'apiKey' => 'k']]);

        self::assertFalse($withoutKey->isEnabled());
        self::assertTrue($withKey->isEnabled());
        self::assertSame('https://api.mistral.ai/v1/embeddings', $withKey->endpoint);
        self::assertSame('mistral:mistral-embed', $withKey->getIdentifier());
    }

    public function testHostedProviderIgnoresTheCustomEndpoint(): void
    {
        $configuration = EmbeddingConfiguration::fromExtensionConfiguration(['embedding' => [
            'provider' => 'openai',
            'apiKey' => 'k',
            'endpoint' => 'http://elsewhere/v1/embeddings',
        ]]);

        self::assertSame('https://api.openai.com/v1/embeddings', $configuration->endpoint);
        self::assertSame('text-embedding-3-small', $configuration->model);
    }

    public function testApiKeyCanComeFromTheEnvironment(): void
    {
        putenv('SEMANTIC_SUGGESTION_TEST_KEY=from-env');
        $configuration = EmbeddingConfiguration::fromExtensionConfiguration(['embedding' => [
            'provider' => 'mistral',
            'apiKey' => 'env:SEMANTIC_SUGGESTION_TEST_KEY',
        ]]);
        putenv('SEMANTIC_SUGGESTION_TEST_KEY');

        self::assertSame('from-env', $configuration->apiKey);
    }

    public function testCustomProviderNeedsEndpointAndModel(): void
    {
        $configuration = EmbeddingConfiguration::fromExtensionConfiguration(['embedding' => [
            'provider' => 'custom',
            'endpoint' => 'http://localhost:11434/v1/embeddings',
        ]]);

        self::assertFalse($configuration->isEnabled());
    }

    /**
     * Two topics of four pages each, every vector sharing a large common part as
     * real embeddings do: pairs within a topic score, pairs across topics do not.
     */
    public function testScoresSeparateTopicsDespiteACommonComponent(): void
    {
        $vectors = [];
        foreach ([1, 2, 3, 4] as $i) {
            $vectors[$i] = [5.0, 1.0, 0.0, 0.1 * $i, 0.0];
            $vectors[$i + 10] = [5.0, 0.0, 1.0, 0.0, 0.1 * $i];
        }

        $scores = (new EmbeddingSimilarity())->computeScores($vectors);

        self::assertGreaterThan(0.5, $scores[1][2]);
        self::assertGreaterThan(0.5, $scores[11][12]);
        self::assertArrayNotHasKey(11, $scores[1]);
        self::assertSame($scores[1][2], $scores[2][1]);
        foreach ($scores as $row) {
            foreach ($row as $score) {
                self::assertLessThanOrEqual(1.0, $score);
            }
        }
    }
}
