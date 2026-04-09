<?php

declare(strict_types=1);

namespace Phgrep\Parallel;

use Phgrep\Walker\FileList;

final class WorkSplitter
{
    private const EXTRA_CHUNK_MULTIPLIER = 4;
    private const MIN_FILES_PER_WORKER_FOR_EXTRA_CHUNKS = 64;

    /**
     * @return list<FileList>
     */
    public function split(FileList $files, int $workers): array
    {
        if ($workers < 1) {
            throw new \InvalidArgumentException('Worker count must be greater than zero.');
        }

        $paths = $files->paths();

        if ($paths === []) {
            return [];
        }

        $workerCount = min($workers, count($paths));
        $bucketCount = $this->bucketCount(count($paths), $workerCount);
        $buckets = array_fill(0, $bucketCount, ['size' => 0, 'paths' => []]);

        usort(
            $paths,
            static fn (string $left, string $right): int => (filesize($right) ?: 0) <=> (filesize($left) ?: 0)
        );

        foreach ($paths as $path) {
            $smallestBucketIndex = 0;

            for ($index = 1; $index < $bucketCount; $index++) {
                if ($buckets[$index]['size'] < $buckets[$smallestBucketIndex]['size']) {
                    $smallestBucketIndex = $index;
                }
            }

            $fileSize = filesize($path) ?: 0;
            $buckets[$smallestBucketIndex]['size'] += $fileSize;
            $buckets[$smallestBucketIndex]['paths'][] = $path;
        }

        $chunks = [];

        foreach ($buckets as $bucket) {
            /** @var list<string> $bucketPaths */
            $bucketPaths = $bucket['paths'];

            if ($bucketPaths !== []) {
                $chunks[] = new FileList($bucketPaths);
            }
        }

        return $chunks;
    }

    private function bucketCount(int $fileCount, int $workerCount): int
    {
        if ($fileCount < ($workerCount * self::MIN_FILES_PER_WORKER_FOR_EXTRA_CHUNKS)) {
            return $workerCount;
        }

        return min($fileCount, max($workerCount, $workerCount * self::EXTRA_CHUNK_MULTIPLIER));
    }
}
