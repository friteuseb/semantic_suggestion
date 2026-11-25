<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Embedding;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Page vectors from the embedding API, cached in tx_semanticsuggestion_embeddings.
 *
 * A page is sent to the API only when its text, or the model, changed since its
 * vector was stored. A weekly run on an unchanged site makes no API call at all.
 */
class PageEmbeddingService
{
    private const TABLE = 'tx_semanticsuggestion_embeddings';

    private ?EmbeddingConfiguration $configuration = null;

    public function __construct(
        private readonly EmbeddingClient $client,
        private readonly ConnectionPool $connectionPool,
        private readonly ExtensionConfiguration $extensionConfiguration
    ) {
    }

    public function getConfiguration(): EmbeddingConfiguration
    {
        if ($this->configuration === null) {
            try {
                $settings = $this->extensionConfiguration->get('semantic_suggestion');
            } catch (\Throwable $e) {
                $settings = [];
            }
            $this->configuration = EmbeddingConfiguration::fromExtensionConfiguration(is_array($settings) ? $settings : []);
        }

        return $this->configuration;
    }

    public function isEnabled(): bool
    {
        return $this->getConfiguration()->isEnabled();
    }

    /**
     * Vector of each page. Pages are keyed by UID; a page whose text is empty is
     * left out of the result.
     *
     * @param array<int, array{languageUid: int, text: string}> $pages
     * @return array<int, list<float>>
     * @throws EmbeddingException when the API fails; nothing is stored then
     */
    public function getPageVectors(array $pages): array
    {
        $configuration = $this->getConfiguration();
        $model = $configuration->getIdentifier();

        $hashes = [];
        foreach ($pages as $pageId => $page) {
            $text = trim($page['text']);
            if ($text !== '') {
                $hashes[$pageId] = hash('sha256', mb_substr($text, 0, EmbeddingClient::MAX_TEXT_CHARACTERS));
            }
        }

        $stored = $this->fetchStored(array_keys($hashes), $model);

        $vectors = [];
        $missing = [];
        foreach ($hashes as $pageId => $hash) {
            $key = $pageId . '_' . $pages[$pageId]['languageUid'];
            $vector = isset($stored[$key]) && $stored[$key]['content_hash'] === $hash
                ? self::decode($stored[$key]['vector'])
                : [];
            if ($vector !== []) {
                $vectors[$pageId] = $vector;
            } else {
                $missing[$pageId] = trim($pages[$pageId]['text']);
            }
        }

        if ($missing !== []) {
            foreach ($this->client->embed($configuration, $missing) as $pageId => $vector) {
                $vectors[$pageId] = $vector;
                $this->store(
                    (int)$pageId,
                    $pages[$pageId]['languageUid'],
                    $model,
                    $hashes[$pageId],
                    $vector,
                    isset($stored[$pageId . '_' . $pages[$pageId]['languageUid']])
                );
            }
        }

        return $vectors;
    }

    /**
     * @param list<int> $pageIds
     * @return array<string, array{content_hash: string, vector: string}> keyed "<pageId>_<languageUid>"
     */
    private function fetchStored(array $pageIds, string $model): array
    {
        $rows = [];
        foreach (array_chunk($pageIds, 500) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $result = $queryBuilder
                ->select('page_id', 'sys_language_uid', 'content_hash', 'vector')
                ->from(self::TABLE)
                ->where(
                    $queryBuilder->expr()->in('page_id', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('model', $queryBuilder->createNamedParameter($model))
                )
                ->executeQuery();

            while ($row = $result->fetchAssociative()) {
                $rows[$row['page_id'] . '_' . $row['sys_language_uid']] = [
                    'content_hash' => (string)$row['content_hash'],
                    'vector' => (string)$row['vector'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param list<float> $vector
     */
    private function store(int $pageId, int $languageUid, string $model, string $hash, array $vector, bool $exists): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $values = ['content_hash' => $hash, 'vector' => self::encode($vector), 'tstamp' => time()];
        $identifier = ['page_id' => $pageId, 'sys_language_uid' => $languageUid, 'model' => $model];

        if ($exists) {
            $connection->update(self::TABLE, $values, $identifier);
        } else {
            $connection->insert(self::TABLE, $identifier + $values);
        }
    }

    /**
     * Float32, little endian, base64: 5.5 KB for 1,024 dimensions instead of
     * some 20 KB as JSON, in a plain text column every DBMS handles alike.
     *
     * @param list<float> $vector
     */
    private static function encode(array $vector): string
    {
        return base64_encode(pack('g*', ...$vector));
    }

    /**
     * @return list<float>
     */
    private static function decode(string $encoded): array
    {
        $values = unpack('g*', (string)base64_decode($encoded, true));

        return $values === false ? [] : array_values($values);
    }
}
