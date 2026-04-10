<?php

declare(strict_types=1);

namespace Greph\Walker;

final readonly class WalkOptions
{
    /**
     * @param list<string> $globPatterns
     */
    public function __construct(
        public bool $respectIgnore = true,
        public bool $includeHidden = false,
        public bool $followSymlinks = false,
        public bool $skipBinaryFiles = false,
        public bool $includeGitDirectory = false,
        public ?FileTypeFilter $fileTypeFilter = null,
        public int $maxFileSizeBytes = 10485760,
        public array $globPatterns = [],
    ) {
    }
}
