<?php

declare(strict_types=1);

namespace Greph\Index;

final readonly class AstCacheBuildResult
{
    public function __construct(
        public string $rootPath,
        public string $indexPath,
        public int $fileCount,
        public int $cachedTreeCount,
        public float $buildDurationMs,
        public int $addedFiles,
        public int $updatedFiles,
        public int $deletedFiles,
        public int $unchangedFiles,
    ) {
    }
}
