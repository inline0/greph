<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Benchmarks;

use Phgrep\Benchmarks\BenchmarkArtifactLocator;
use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkArtifactLocatorTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('benchmark-artifact-locator');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itFindsComparisonAndSeriesFilesInsideDownloadedArtifacts(): void
    {
        Workspace::writeFile($this->workspace, 'artifact/build/benchmarks/comparison.md', '# Comparison');
        Workspace::writeFile($this->workspace, 'artifact/build/benchmarks/head-series.json', '{}');
        Workspace::writeFile($this->workspace, '_temp/phgrep-base/build/benchmarks/base-series.json', '{}');
        $locator = new BenchmarkArtifactLocator();

        $this->assertStringEndsWith('/artifact/build/benchmarks/comparison.md', $locator->findComparisonReport($this->workspace));
        $this->assertStringEndsWith('/artifact/build/benchmarks/head-series.json', $locator->findHeadSeries($this->workspace));
        $this->assertStringEndsWith('/_temp/phgrep-base/build/benchmarks/base-series.json', $locator->findBaseSeries($this->workspace));
    }

    #[Test]
    public function itThrowsWhenRequiredArtifactFilesAreMissing(): void
    {
        $locator = new BenchmarkArtifactLocator();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to locate comparison.md');
        $locator->findComparisonReport($this->workspace);
    }
}
