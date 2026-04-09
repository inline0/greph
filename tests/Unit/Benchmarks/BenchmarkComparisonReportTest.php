<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Benchmarks;

use Phgrep\Benchmarks\BenchmarkComparisonReport;
use Phgrep\Benchmarks\BenchmarkResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkComparisonReportTest extends TestCase
{
    #[Test]
    public function itRendersBranchAndExternalComparisonTables(): void
    {
        $report = (new BenchmarkComparisonReport())->render(
            [
                $this->benchmarkResult('phgrep', 120.0),
                $this->benchmarkResult('rg', 40.0),
            ],
            [
                $this->benchmarkResult('phgrep', 90.0),
                $this->benchmarkResult('rg', 35.0),
            ],
            'main',
            'feature',
        );

        $this->assertStringContainsString('Comparing `main` to `feature`', $report);
        $this->assertStringContainsString('`win` <= -3.00%', $report);
        $this->assertStringContainsString('| win | wordpress | text | literal | 120.00ms | 90.00ms | -30.00ms | -25.00% |', $report);
        $this->assertStringContainsString('| wordpress | text | literal | 90.00ms | 35.00ms (rg) | +157.14% |', $report);
    }

    private function benchmarkResult(string $tool, float $durationMs): BenchmarkResult
    {
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
        );
    }
}
