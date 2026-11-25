<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Embedding;

/**
 * Turns page embeddings into similarity scores on the same scale as TF-IDF.
 *
 * Raw cosines of embeddings are not comparable with TF-IDF scores. On the
 * 39-page demo site, the best three suggestions of each page scored 0.59 to 0.79
 * with bge-m3 and 0.67 to 0.89 with nomic-embed-text, against 0.10 to 0.48 with
 * TF-IDF: every pair, even unrelated, would pass the 0.1 quality level and most
 * would be labelled "High". Two steps bring them back to the TF-IDF scale:
 *
 * 1. Centering. The mean vector of the site is subtracted from each vector. What
 *    all pages share (same language, same site, same boilerplate) no longer counts,
 *    and unrelated pages fall below 0.
 * 2. Rescaling against the site's own distribution. The median of all pair scores
 *    plus one standard deviation becomes 0, and 1 stays 1. Models spread their
 *    scores differently; this baseline follows the spread of the model in use.
 *
 * Measured on the 24 articles of the demo site (4 topics of 6, English), pairs scoring
 * 0.1 or more: TF-IDF 144, of which 102 within the same topic (71 %); bge-m3 72,
 * of which 62 (86 %); nomic-embed-text 124, of which 108 (87 %). French and German
 * with bge-m3: 82 % and 87 %. Every page keeps about three suggestions, and the
 * quality level and relevance labels keep their meaning.
 */
class EmbeddingSimilarity
{
    /**
     * Below this many pages, the mean vector and the percentile mean little and
     * the analysis falls back to TF-IDF.
     */
    public const MINIMUM_PAGES = 8;

    /**
     * Calibrated score of every pair, in both directions. Pairs at 0 are left out.
     *
     * @param array<int|string, list<float>> $vectors
     * @return array<int|string, array<int|string, float>>
     */
    public function computeScores(array $vectors): array
    {
        $ids = array_keys($vectors);
        $count = count($ids);
        if ($count < 2) {
            return [];
        }

        $centered = $this->center(array_values($vectors));

        $raw = [];
        $all = [];
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $score = self::dot($centered[$i], $centered[$j]);
                $raw[$i][$j] = $score;
                $all[] = $score;
            }
        }

        $baseline = self::median($all) + self::standardDeviation($all);
        $range = 1.0 - $baseline;

        $scores = [];
        if ($range <= 0.0) {
            return $scores;
        }
        foreach ($raw as $i => $row) {
            foreach ($row as $j => $score) {
                $calibrated = ($score - $baseline) / $range;
                if ($calibrated > 0.0) {
                    $scores[$ids[$i]][$ids[$j]] = min(1.0, $calibrated);
                    $scores[$ids[$j]][$ids[$i]] = min(1.0, $calibrated);
                }
            }
        }

        return $scores;
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Population standard deviation.
     *
     * @param list<float> $values
     */
    private static function standardDeviation(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return sqrt($variance / count($values));
    }

    /**
     * Unit vectors, minus their mean, normalised again.
     *
     * @param list<list<float>> $vectors
     * @return list<list<float>>
     */
    private function center(array $vectors): array
    {
        $vectors = array_map(self::normalize(...), $vectors);
        $dimensions = count($vectors[0]);
        $count = count($vectors);

        $mean = array_fill(0, $dimensions, 0.0);
        foreach ($vectors as $vector) {
            for ($d = 0; $d < $dimensions; $d++) {
                $mean[$d] += ($vector[$d] ?? 0.0) / $count;
            }
        }

        return array_map(static function (array $vector) use ($mean, $dimensions): array {
            $centered = [];
            for ($d = 0; $d < $dimensions; $d++) {
                $centered[] = ($vector[$d] ?? 0.0) - $mean[$d];
            }
            return self::normalize($centered);
        }, $vectors);
    }

    /**
     * @param list<float> $vector
     * @return list<float>
     */
    private static function normalize(array $vector): array
    {
        $norm = sqrt(self::dot($vector, $vector));
        if ($norm == 0.0) {
            return $vector;
        }

        return array_map(static fn(float $value): float => $value / $norm, $vector);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $d => $value) {
            $sum += $value * $b[$d];
        }

        return $sum;
    }
}
