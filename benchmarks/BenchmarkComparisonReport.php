<?php

declare(strict_types=1);

namespace Greph\Benchmarks;

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
            'Thresholds:',
            '- `win` <= -3.00%',
            '- `regression` >= +3.00%',
            '- otherwise `noise`',
            '- spread shown as `min..max / n` from the repeated measured runs',
            '',
            '## greph delta',
            '',
            '| Signal | Corpus | Category | Operation | Base | Base spread | Head | Head spread | Delta | Change |',
            '| --- | --- | --- | --- | ---: | --- | ---: | --- | ---: | ---: |',
        ];

        $baseMap = $this->index($base);
        $headMap = $this->index($head);
        $keys = array_values(array_unique(array_merge(array_keys($baseMap), array_keys($headMap))));
        sort($keys);

        foreach ($keys as $key) {
            $baseResult = $baseMap[$key] ?? null;
            $headResult = $headMap[$key] ?? null;

            if ($baseResult === null || $headResult === null) {
                continue;
            }

            if ($baseResult->tool !== 'greph') {
                continue;
            }

            $delta = $headResult->durationMs - $baseResult->durationMs;
            $deltaPercent = $baseResult->durationMs > 0
                ? ($delta / $baseResult->durationMs) * 100
                : 0.0;

            $lines[] = sprintf(
                '| %s | %s | %s | %s | %.2fms | %s | %.2fms | %s | %+.2fms | %+.2f%% |',
                $this->signal($deltaPercent),
                $headResult->corpus,
                $headResult->category,
                $headResult->operation,
                $baseResult->durationMs,
                $this->spread($baseResult),
                $headResult->durationMs,
                $this->spread($headResult),
                $delta,
                $deltaPercent,
            );
        }

        $lines[] = '';
        $lines[] = '## Head Snapshot vs external tools';
        $lines[] = '';
        $lines[] = '| Corpus | Category | Operation | greph | Fastest external | Gap |';
        $lines[] = '| --- | --- | --- | ---: | ---: | ---: |';

        foreach ($this->groupHeadRows($head) as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %.2fms | %.2fms (%s) | %+.2f%% |',
                $row['corpus'],
                $row['category'],
                $row['operation'],
                $row['greph']->durationMs,
                $row['external']->durationMs,
                $row['external']->tool,
                (($row['greph']->durationMs - $row['external']->durationMs) / $row['external']->durationMs) * 100,
            );
        }

        $lines[] = '';
        $lines[] = '## Head Memory Snapshot';
        $lines[] = '';
        $lines[] = '| Corpus | Category | Operation | greph peak memory |';
        $lines[] = '| --- | --- | --- | ---: |';

        foreach ($this->headMemoryRows($head) as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %.2fMB |',
                $row->corpus,
                $row->category,
                $row->operation,
                $row->memoryBytes / 1_048_576,
            );
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function signal(float $deltaPercent): string
    {
        return match (true) {
            $deltaPercent <= -3.0 => 'win',
            $deltaPercent >= 3.0 => 'regression',
            default => 'noise',
        };
    }

    private function spread(BenchmarkResult $result): string
    {
        if ($result->durationMinMs === null || $result->durationMaxMs === null || $result->sampleCount === null) {
            return 'n/a';
        }

        return sprintf('%.2f..%.2fms / %d', $result->durationMinMs, $result->durationMaxMs, $result->sampleCount);
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
     * @return list<array{corpus: string, category: string, operation: string, greph: BenchmarkResult, external: BenchmarkResult}>
     */
    private function groupHeadRows(array $results): array
    {
        /** @var array<string, array{corpus: string, category: string, operation: string, greph?: BenchmarkResult, external?: BenchmarkResult}> $grouped */
        $grouped = [];

        foreach ($results as $result) {
            if ($result->skipped) {
                continue;
            }

            $key = implode("\0", [$result->corpus, $result->category, $result->suite, $result->operation]);
            $grouped[$key]['corpus'] = $result->corpus;
            $grouped[$key]['category'] = $result->category;
            $grouped[$key]['operation'] = $result->operation;

            if ($result->tool === 'greph') {
                $grouped[$key]['greph'] = $result;
                continue;
            }

            if (!isset($grouped[$key]['external']) || $result->durationMs < $grouped[$key]['external']->durationMs) {
                $grouped[$key]['external'] = $result;
            }
        }

        /** @var list<array{corpus: string, category: string, operation: string, greph: BenchmarkResult, external: BenchmarkResult}> $rows */
        $rows = [];

        foreach ($grouped as $group) {
            if (!isset($group['greph'], $group['external'])) {
                continue;
            }

            /** @var BenchmarkResult $greph */
            $greph = $group['greph'];
            /** @var BenchmarkResult $external */
            $external = $group['external'];

            $rows[] = [
                'corpus' => $group['corpus'],
                'category' => $group['category'],
                'operation' => $group['operation'],
                'greph' => $greph,
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

    /**
     * @param list<BenchmarkResult> $results
     * @return list<BenchmarkResult>
     */
    private function headMemoryRows(array $results): array
    {
        $rows = array_values(array_filter(
            $results,
            static fn (BenchmarkResult $result): bool => !$result->skipped && $result->tool === 'greph',
        ));

        usort(
            $rows,
            static fn (BenchmarkResult $left, BenchmarkResult $right): int => [
                $left->corpus,
                $left->category,
                $left->operation,
            ] <=> [
                $right->corpus,
                $right->category,
                $right->operation,
            ],
        );

        return $rows;
    }
}
