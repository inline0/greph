<?php

declare(strict_types=1);

namespace Phgrep\Parallel;

use Phgrep\Walker\FileList;

final class WorkSplitter
{
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
        $buckets = array_fill(0, $workerCount, ['size' => 0, 'paths' => []]);

        usort(
            $paths,
            static fn (string $left, string $right): int => (filesize($right) ?: 0) <=> (filesize($left) ?: 0)
        );

        foreach ($paths as $path) {
            $smallestBucketIndex = 0;

            for ($index = 1; $index < $workerCount; $index++) {
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
}
