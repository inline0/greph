<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Benchmarks;

use Greph\Benchmarks\BenchmarkComparisonReport;
use Greph\Benchmarks\BenchmarkResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkComparisonReportTest extends TestCase
{
    #[Test]
    public function itRendersBranchAndExternalComparisonTables(): void
    {
        $report = (new BenchmarkComparisonReport())->render(
            [
                $this->benchmarkResult('greph', 120.0, 110.0, 130.0, 5),
                $this->benchmarkResult('rg', 40.0, 38.0, 42.0, 5),
            ],
            [
                $this->benchmarkResult('greph', 90.0, 88.0, 95.0, 5),
                $this->benchmarkResult('rg', 35.0, 34.0, 36.0, 5),
            ],
            'main',
            'feature',
        );

        $this->assertStringContainsString('Comparing `main` to `feature`', $report);
        $this->assertStringContainsString('`win` <= -3.00%', $report);
        $this->assertStringContainsString('spread shown as `min..max / n`', $report);
        $this->assertStringContainsString('| win | wordpress | text | literal | 120.00ms | 110.00..130.00ms / 5 | 90.00ms | 88.00..95.00ms / 5 | -30.00ms | -25.00% |', $report);
        $this->assertStringContainsString('| wordpress | text | literal | 90.00ms | 35.00ms (rg) | +157.14% |', $report);
        $this->assertStringContainsString('## Head Memory Snapshot', $report);
        $this->assertStringContainsString('| wordpress | text | literal | 0.00MB |', $report);
    }

    private function benchmarkResult(
        string $tool,
        float $durationMs,
        ?float $durationMinMs = null,
        ?float $durationMaxMs = null,
        ?int $sampleCount = null,
    ): BenchmarkResult {
        return new BenchmarkResult(
            category: 'text',
            suite: 'text-literal',
            operation: 'literal',
            corpus: 'wordpress',
            tool: $tool,
            durationMs: $durationMs,
            memoryBytes: 0,
            fileCount: 10,
            matchCount: 20,
            durationMinMs: $durationMinMs,
            durationMaxMs: $durationMaxMs,
            sampleCount: $sampleCount,
        );
    }
}
