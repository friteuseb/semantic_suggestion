<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Embedding;

/**
 * The embedding API could not deliver vectors: network error, HTTP error,
 * invalid key, quota, or an answer of unexpected shape.
 */
final class EmbeddingException extends \RuntimeException
{
}
