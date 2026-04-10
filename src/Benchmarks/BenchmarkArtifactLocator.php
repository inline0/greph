<?php

declare(strict_types=1);

namespace Phgrep\Benchmarks;

final class BenchmarkArtifactLocator
{
    public function headArtifactName(string|int $runId): string
    {
        return 'benchmark-head-' . $runId;
    }

    public function baseArtifactName(string|int $runId): string
    {
        return 'benchmark-base-' . $runId;
    }

    public function findComparisonReport(string $rootPath): string
    {
        return $this->findSingle($rootPath, 'comparison.md');
    }

    public function findHeadSeries(string $rootPath): string
    {
        return $this->findSingle($rootPath, 'head-series.json');
    }

    public function findBaseSeries(string $rootPath): string
    {
        return $this->findSingle($rootPath, 'base-series.json');
    }

    private function findSingle(string $rootPath, string $filename): string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getFilename() === $filename) {
                return $entry->getPathname();
            }
        }

        throw new \RuntimeException(sprintf('Failed to locate %s in benchmark artifact: %s', $filename, $rootPath));
    }
}
