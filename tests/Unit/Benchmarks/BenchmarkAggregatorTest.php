<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Benchmarks;

use Phgrep\Benchmarks\BenchmarkAggregator;
use Phgrep\Benchmarks\BenchmarkResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkAggregatorTest extends TestCase
{
    #[Test]
    public function itAggregatesResultsByMedian(): void
    {
        $aggregator = new BenchmarkAggregator();
        $runs = [
            [$this->benchmarkResult(120.0, 10_000)],
            [$this->benchmarkResult(100.0, 20_000)],
            [$this->benchmarkResult(140.0, 30_000)],
        ];

        $aggregated = $aggregator->aggregate($runs);

        $this->assertCount(1, $aggregated);
        $this->assertSame(120.0, $aggregated[0]->durationMs);
        $this->assertSame(20_000, $aggregated[0]->memoryBytes);
    }

    #[Test]
    public function itKeepsSkippedResultsWhenEveryRunIsSkipped(): void
    {
        $aggregator = new BenchmarkAggregator();
        $runs = [
            [$this->benchmarkResult(0.0, 0, true, 'tool missing')],
            [$this->benchmarkResult(0.0, 0, true, 'tool missing')],
        ];

        $aggregated = $aggregator->aggregate($runs);

        $this->assertTrue($aggregated[0]->skipped);
        $this->assertSame('tool missing', $aggregated[0]->skipReason);
    }

    private function benchmarkResult(float $durationMs, int $memoryBytes, bool $skipped = false, ?string $skipReason = null): BenchmarkResult
    {
        return new BenchmarkResult(
            category: 'text',
            suite: 'text-literal',
            operation: 'literal',
            corpus: 'wordpress',
            tool: 'phgrep',
            durationMs: $durationMs,
            memoryBytes: $memoryBytes,
            fileCount: 10,
            matchCount: 20,
            skipped: $skipped,
            skipReason: $skipReason,
        );
    }
}
