<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Pairs of similar pages that do not link to each other yet.
 *
 * The analysis says which pages are close; the reference index says which pages
 * already link to which. An opportunity is a stored pair page -> similar page,
 * among the first page's NEIGHBOURS_PER_PAGE closest pages at or above the display
 * threshold, where no content element of the first page links to the second. Pages that receive no link at all (orphans) come first:
 * a link to them is worth more than one more link between well-connected pages.
 *
 * Default language only. Similarity rows store default-language page uids and
 * translated content usually mirrors the default links, so mixing languages
 * would count the same missing link several times.
 */
final class LinkOpportunityService
{
    private const SIMILARITIES = 'tx_semanticsuggestion_similarities';

    /**
     * Only the closest pages of each page count. Above the display threshold a page
     * can still be loosely related to dozens of others; on 42 French test pages,
     * 0.1 let through 676 pairs, most of them across topics. A link is worth
     * suggesting to one of a page's few nearest neighbours, not to all of them.
     */
    public const NEIGHBOURS_PER_PAGE = 5;

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    /**
     * @param int[] $allowedPageIds Pages the backend user may see; the result never names another
     * @param int[] $excludePages   Pages never suggested, as in the frontend
     * @return array{opportunities: list<array{source: int, target: int, score: float, targetIncoming: int, targetOrphan: bool}>, summary: array<string, int>, incoming: array<int, int>}
     */
    public function findOpportunities(int $rootPageId, array $allowedPageIds, float $threshold, array $excludePages = [], int $limit = 20): array
    {
        $allowed = array_fill_keys(array_map('intval', $allowedPageIds), true);
        $excluded = array_fill_keys(array_map('intval', $excludePages), true);

        $pairs = [];
        $neighbours = [];
        // Rows come sorted by score, so the first NEIGHBOURS_PER_PAGE kept for a
        // page are its closest ones.
        foreach ($this->fetchPairs($rootPageId, $threshold) as $row) {
            $source = (int)$row['page_id'];
            $target = (int)$row['similar_page_id'];
            if ($source === $target || !isset($allowed[$source], $allowed[$target]) || isset($excluded[$target])) {
                continue;
            }
            if (($neighbours[$source] ?? 0) >= self::NEIGHBOURS_PER_PAGE) {
                continue;
            }
            $neighbours[$source] = ($neighbours[$source] ?? 0) + 1;
            $pairs[] = ['source' => $source, 'target' => $target, 'score' => (float)$row['similarity_score']];
        }

        $links = $this->fetchLinks(array_keys($allowed));

        $incoming = array_fill_keys(array_keys($allowed), 0);
        foreach ($links as $source => $targets) {
            foreach ($targets as $target => $unused) {
                if (isset($incoming[$target]) && $target !== $source) {
                    $incoming[$target]++;
                }
            }
        }

        $opportunities = [];
        $alreadyLinked = 0;
        foreach ($pairs as $pair) {
            if (isset($links[$pair['source']][$pair['target']])) {
                $alreadyLinked++;
                continue;
            }
            $pair['targetIncoming'] = $incoming[$pair['target']] ?? 0;
            $pair['targetOrphan'] = $pair['targetIncoming'] === 0;
            $opportunities[] = $pair;
        }

        usort($opportunities, static fn(array $a, array $b): int =>
            [$b['targetOrphan'], $b['score']] <=> [$a['targetOrphan'], $a['score']]);

        $orphans = array_keys(array_filter($incoming, static fn(int $count): bool => $count === 0));
        $orphansWithOpportunity = array_unique(array_column(
            array_filter($opportunities, static fn(array $o): bool => $o['targetOrphan']),
            'target'
        ));

        return [
            'opportunities' => array_slice($opportunities, 0, $limit),
            // Links received by every allowed page, for the module's page list.
            'incoming' => $incoming,
            'summary' => [
                'pairs' => count($pairs),
                'alreadyLinked' => $alreadyLinked,
                'missing' => count($opportunities),
                'orphanPages' => count($orphans),
                'orphansWithOpportunity' => count($orphansWithOpportunity),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPairs(int $rootPageId, float $threshold): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::SIMILARITIES);

        return $queryBuilder
            ->select('page_id', 'similar_page_id', 'similarity_score')
            ->from(self::SIMILARITIES)
            ->where(
                $queryBuilder->expr()->eq('root_page_id', $queryBuilder->createNamedParameter($rootPageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('source', $queryBuilder->createNamedParameter('analysis')),
                $queryBuilder->expr()->gte('similarity_score', $queryBuilder->createNamedParameter($threshold))
            )
            ->orderBy('similarity_score', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Existing links between pages, from the reference index: which pages each
     * visible default-language content element of a page points to.
     *
     * @param int[] $pageIds
     * @return array<int, array<int, true>> source page => [target page => true]
     */
    private function fetchLinks(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('c.pid', 'r.ref_uid')
            ->from('sys_refindex', 'r')
            ->join('r', 'tt_content', 'c', $queryBuilder->expr()->eq('c.uid', $queryBuilder->quoteIdentifier('r.recuid')))
            ->where(
                $queryBuilder->expr()->eq('r.tablename', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('r.ref_table', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('r.workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('c.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('c.hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('c.sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('c.pid', $queryBuilder->createNamedParameter(array_map('intval', $pageIds), Connection::PARAM_INT_ARRAY))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $links = [];
        foreach ($rows as $row) {
            $links[(int)$row['pid']][(int)$row['ref_uid']] = true;
        }

        return $links;
    }
}
