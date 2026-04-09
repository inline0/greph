<?php

declare(strict_types=1);

namespace Phgrep\Benchmarks;

final class BenchmarkComparisonReport
{
    /**
     * @param list<BenchmarkResult> $base
     * @param list<BenchmarkResult> $head
     */
    public function render(array $base, array $head, string $baseLabel = 'base', string $headLabel = 'head'): string
    {
        $lines = [
            '# Benchmark Comparison',
            '',
            sprintf('Comparing `%s` to `%s` using median benchmark durations.', $baseLabel, $headLabel),
            '',
            '## phgrep delta',
            '',
            '| Corpus | Category | Operation | Base | Head | Delta | Change |',
            '| --- | --- | --- | ---: | ---: | ---: | ---: |',
        ];

        $baseMap = $this->index($base);
        $headMap = $this->index($head);
        $keys = array_values(array_unique(array_merge(array_keys($baseMap), array_keys($headMap))));
        sort($keys);

        foreach ($keys as $key) {
            $baseResult = $baseMap[$key] ?? null;
            $headResult = $headMap[$key] ?? null;

            if (($baseResult?->tool ?? $headResult?->tool) !== 'phgrep') {
                continue;
            }

            if ($baseResult === null || $headResult === null) {
                continue;
            }

            $delta = $headResult->durationMs - $baseResult->durationMs;
            $deltaPercent = $baseResult->durationMs > 0
                ? ($delta / $baseResult->durationMs) * 100
                : 0.0;

            $lines[] = sprintf(
                '| %s | %s | %s | %.2fms | %.2fms | %+.2fms | %+.2f%% |',
                $headResult->corpus,
                $headResult->category,
                $headResult->operation,
                $baseResult->durationMs,
                $headResult->durationMs,
                $delta,
                $deltaPercent,
            );
        }

        $lines[] = '';
        $lines[] = '## Head Snapshot vs external tools';
        $lines[] = '';
        $lines[] = '| Corpus | Category | Operation | phgrep | Fastest external | Gap |';
        $lines[] = '| --- | --- | --- | ---: | ---: | ---: |';

        foreach ($this->groupHeadRows($head) as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %.2fms | %.2fms (%s) | %+.2f%% |',
                $row['corpus'],
                $row['category'],
                $row['operation'],
                $row['phgrep']->durationMs,
                $row['external']->durationMs,
                $row['external']->tool,
                (($row['phgrep']->durationMs - $row['external']->durationMs) / $row['external']->durationMs) * 100,
            );
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param list<BenchmarkResult> $results
     * @return array<string, BenchmarkResult>
     */
    private function index(array $results): array
    {
        $indexed = [];

        foreach ($results as $result) {
            $indexed[$this->key($result)] = $result;
        }

        return $indexed;
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
     * @param list<BenchmarkResult> $results
     * @return list<array{corpus: string, category: string, operation: string, phgrep: BenchmarkResult, external: BenchmarkResult}>
     */
    private function groupHeadRows(array $results): array
    {
        $grouped = [];

        foreach ($results as $result) {
            if ($result->skipped) {
                continue;
            }

            $key = implode("\0", [$result->corpus, $result->category, $result->suite, $result->operation]);
            $grouped[$key]['corpus'] = $result->corpus;
            $grouped[$key]['category'] = $result->category;
            $grouped[$key]['operation'] = $result->operation;

            if ($result->tool === 'phgrep') {
                $grouped[$key]['phgrep'] = $result;
                continue;
            }

            if (!isset($grouped[$key]['external']) || $result->durationMs < $grouped[$key]['external']->durationMs) {
                $grouped[$key]['external'] = $result;
            }
        }

        $rows = [];

        foreach ($grouped as $group) {
            if (!isset($group['phgrep'], $group['external'])) {
                continue;
            }

            /** @var BenchmarkResult $phgrep */
            $phgrep = $group['phgrep'];
            /** @var BenchmarkResult $external */
            $external = $group['external'];

            $rows[] = [
                'corpus' => $group['corpus'],
                'category' => $group['category'],
                'operation' => $group['operation'],
                'phgrep' => $phgrep,
                'external' => $external,
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => [
                $left['corpus'],
                $left['category'],
                $left['operation'],
            ] <=> [
                $right['corpus'],
                $right['category'],
                $right['operation'],
            ],
        );

        return $rows;
    }
}
