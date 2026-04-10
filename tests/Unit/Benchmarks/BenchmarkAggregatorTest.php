<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Benchmarks;

use Greph\Benchmarks\BenchmarkAggregator;
use Greph\Benchmarks\BenchmarkResult;
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
        $this->assertSame(100.0, $aggregated[0]->durationMinMs);
        $this->assertSame(140.0, $aggregated[0]->durationMaxMs);
        $this->assertSame(3, $aggregated[0]->sampleCount);
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
        $this->assertSame(0, $aggregated[0]->sampleCount);
    }

    private function benchmarkResult(float $durationMs, int $memoryBytes, bool $skipped = false, ?string $skipReason = null): BenchmarkResult
    {
        return new BenchmarkResult(
            category: 'text',
            suite: 'text-literal',
            operation: 'literal',
            corpus: 'wordpress',
            tool: 'greph',
            durationMs: $durationMs,
            memoryBytes: $memoryBytes,
            fileCount: 10,
            matchCount: 20,
            skipped: $skipped,
            skipReason: $skipReason,
        );
    }
}
