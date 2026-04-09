<?php

declare(strict_types=1);

namespace Phgrep\Benchmarks;

final class BenchmarkAggregator
{
    /**
     * @param list<list<BenchmarkResult>> $runs
     * @return list<BenchmarkResult>
     */
    public function aggregate(array $runs): array
    {
        $grouped = [];

        foreach ($runs as $run) {
            foreach ($run as $result) {
                $key = $this->key($result);

                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'prototype' => $result,
                        'durations' => [],
                        'memory' => [],
                        'files' => [],
                        'matches' => [],
                        'skip_reasons' => [],
                        'non_skipped' => 0,
                    ];
                }

                if ($result->skipped) {
                    if ($result->skipReason !== null && $result->skipReason !== '') {
                        $grouped[$key]['skip_reasons'][$result->skipReason] = true;
                    }

                    continue;
                }

                $grouped[$key]['durations'][] = $result->durationMs;
                $grouped[$key]['memory'][] = $result->memoryBytes;
                $grouped[$key]['files'][] = $result->fileCount;
                $grouped[$key]['matches'][] = $result->matchCount;
                $grouped[$key]['non_skipped']++;
            }
        }

        $aggregated = [];

        foreach ($grouped as $entry) {
            /** @var BenchmarkResult $prototype */
            $prototype = $entry['prototype'];

            if ($entry['non_skipped'] === 0) {
                $aggregated[] = new BenchmarkResult(
                    category: $prototype->category,
                    suite: $prototype->suite,
                    operation: $prototype->operation,
                    corpus: $prototype->corpus,
                    tool: $prototype->tool,
                    durationMs: 0.0,
                    memoryBytes: 0,
                    fileCount: 0,
                    matchCount: 0,
                    skipped: true,
                    skipReason: implode('; ', array_keys($entry['skip_reasons'])),
                );

                continue;
            }

            $aggregated[] = new BenchmarkResult(
                category: $prototype->category,
                suite: $prototype->suite,
                operation: $prototype->operation,
                corpus: $prototype->corpus,
                tool: $prototype->tool,
                durationMs: $this->medianFloat($entry['durations']),
                memoryBytes: $this->medianInt($entry['memory']),
                fileCount: $this->medianInt($entry['files']),
                matchCount: $this->medianInt($entry['matches']),
            );
        }

        usort(
            $aggregated,
            static fn (BenchmarkResult $left, BenchmarkResult $right): int => [
                $left->corpus,
                $left->category,
                $left->suite,
                $left->operation,
                $left->tool,
            ] <=> [
                $right->corpus,
                $right->category,
                $right->suite,
                $right->operation,
                $right->tool,
            ],
        );

        return $aggregated;
    }

    private function key(BenchmarkResult $result): string
    {
        return implode("\0", [
            $result->corpus,
            $result->category,
            $result->suite,
            $result->operation,
            $result->tool,
        ]);
    }

    /**
     * @param list<float> $values
     */
    private function medianFloat(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        if (count($values) % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param list<int> $values
     */
    private function medianInt(array $values): int
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        if (count($values) % 2 === 1) {
            return $values[$middle];
        }

        return (int) floor(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
