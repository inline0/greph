<?php

declare(strict_types=1);

namespace Phgrep\Index;

final readonly class IndexBuildResult
{
    public function __construct(
        public string $rootPath,
        public string $indexPath,
        public int $fileCount,
        public int $trigramCount,
        public int $addedFiles,
        public int $updatedFiles,
        public int $deletedFiles,
        public int $unchangedFiles,
    ) {
    }
}
