<?php

declare(strict_types=1);

namespace Phgrep\Benchmarks;

final class BenchmarkReport
{
    /**
     * @param list<BenchmarkResult> $results
     */
    public function render(array $results): string
    {
        $lines = [
            'phgrep Benchmark Report',
            '=======================',
            '',
        ];

        $grouped = [];

        foreach ($results as $result) {
            $grouped[$result->corpus][$result->category][] = $result;
        }

        ksort($grouped);

        foreach ($grouped as $corpus => $categories) {
            $lines[] = sprintf('Corpus: %s', $corpus);

            foreach ($categories as $category => $categoryResults) {
                $lines[] = sprintf('%s:', ucfirst($category));

                foreach ($categoryResults as $result) {
                    $duration = $result->skipped
                        ? sprintf('skipped (%s)', $result->skipReason ?? 'n/a')
                        : sprintf('%.2fms', $result->durationMs);

                    $lines[] = sprintf(
                        '  %-24s %-8s %s',
                        $result->operation,
                        '[' . $result->tool . ']',
                        $duration,
                    );
                }

                $lines[] = '';
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
