<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Service;

/**
 * View data of the backend module, built once from the stored analysis so that the
 * TYPO3 12 and the TYPO3 13/14 controllers render the same thing.
 *
 * Everything here applies the frontend filters (display threshold, excludePages,
 * maxSuggestions per page), so the module shows what visitors actually get.
 */
final class BackendOverviewService
{
    public const MAX_PAIRS = 50;

    /**
     * @param array{results?: array<int, array>} $analysisData As built by the controllers
     * @param array<int, int>|null $incoming Links received per page, from LinkOpportunityService
     * @return array{pairs: list<array>, pages: list<array>, totals: array<string, int>}
     */
    public function build(array $analysisData, float $threshold, array $excludePages, int $maxSuggestions, ?array $incoming): array
    {
        $results = $analysisData['results'] ?? [];
        $excluded = array_fill_keys(array_map('intval', $excludePages), true);
        $title = static fn(int $uid): string => (string)($results[$uid]['title']['content'] ?? ('Page ' . $uid));

        $pairs = [];
        $pages = [];
        foreach ($results as $pageId => $pageData) {
            $pageId = (int)$pageId;
            $similarities = $pageData['similarities'] ?? [];
            uasort($similarities, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

            $shown = [];
            foreach ($similarities as $similarPageId => $similarity) {
                $similarPageId = (int)$similarPageId;
                if ($similarity['score'] < $threshold || isset($excluded[$similarPageId])) {
                    continue;
                }
                $shown[] = [
                    'uid' => $similarPageId,
                    'title' => $title($similarPageId),
                    'score' => (float)$similarity['score'],
                    'relevance' => self::relevance((float)$similarity['score']),
                ];

                $key = min($pageId, $similarPageId) . '-' . max($pageId, $similarPageId);
                if (!isset($pairs[$key])) {
                    $pairs[$key] = [
                        'page1' => $pageId,
                        'title1' => $title($pageId),
                        'page2' => $similarPageId,
                        'title2' => $title($similarPageId),
                        'score' => (float)$similarity['score'],
                        'relevance' => self::relevance((float)$similarity['score']),
                    ];
                }
                if (count($shown) >= $maxSuggestions) {
                    break;
                }
            }

            $pages[] = [
                'uid' => $pageId,
                'title' => $title($pageId),
                'suggestions' => $shown,
                'suggestionCount' => count($shown),
                'incoming' => $incoming[$pageId] ?? null,
                'orphan' => $incoming !== null && ($incoming[$pageId] ?? 0) === 0,
            ];
        }

        usort($pairs, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        // Pages that need attention first: no suggestion shown, or no link received.
        usort($pages, static fn(array $a, array $b): int =>
            [$a['suggestionCount'] > 0, !$a['orphan'], mb_strtolower($a['title'])]
            <=> [$b['suggestionCount'] > 0, !$b['orphan'], mb_strtolower($b['title'])]);

        return [
            'pairs' => array_slice(array_values($pairs), 0, self::MAX_PAIRS),
            'pages' => $pages,
            'totals' => [
                'pages' => count($pages),
                'pairs' => count($pairs),
                'pagesWithoutSuggestion' => count(array_filter($pages, static fn(array $p): bool => $p['suggestionCount'] === 0)),
            ],
        ];
    }

    /**
     * high, medium or low, on the 5.0 score scale.
     */
    public static function relevance(float $score): string
    {
        if ($score >= SuggestionService::RELEVANCE_HIGH) {
            return 'high';
        }

        return $score >= SuggestionService::RELEVANCE_MEDIUM ? 'medium' : 'low';
    }
}
