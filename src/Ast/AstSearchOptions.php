<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use Phgrep\Walker\FileTypeFilter;
use Phgrep\Walker\WalkOptions;

final readonly class AstSearchOptions
{
    public function __construct(
        public string $language = 'php',
        public int $jobs = 1,
        public bool $respectIgnore = true,
        public bool $includeHidden = false,
        public bool $followSymlinks = false,
        public bool $skipBinaryFiles = true,
        public bool $includeGitDirectory = false,
        public ?FileTypeFilter $fileTypeFilter = null,
        public int $maxFileSizeBytes = 10485760,
        public bool $skipParseErrors = true,
        public bool $dryRun = false,
        public bool $interactive = false,
        public bool $jsonOutput = false,
    ) {
        if ($this->jobs < 1) {
            throw new \InvalidArgumentException('Job count must be greater than zero.');
        }
    }

    public function walkOptions(): WalkOptions
    {
        $fileTypeFilter = $this->fileTypeFilter ?? new FileTypeFilter(['php']);

        return new WalkOptions(
            respectIgnore: $this->respectIgnore,
            includeHidden: $this->includeHidden,
            followSymlinks: $this->followSymlinks,
            skipBinaryFiles: $this->skipBinaryFiles,
            includeGitDirectory: $this->includeGitDirectory,
            fileTypeFilter: $fileTypeFilter,
            maxFileSizeBytes: $this->maxFileSizeBytes,
        );
    }
}
