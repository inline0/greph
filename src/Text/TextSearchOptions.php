<?php

declare(strict_types=1);

namespace Greph\Text;

use Greph\Walker\FileTypeFilter;
use Greph\Walker\WalkOptions;

final readonly class TextSearchOptions
{
    /**
     * @param list<string> $globPatterns
     */
    public function __construct(
        public bool $fixedString = false,
        public bool $caseInsensitive = false,
        public bool $wholeWord = false,
        public bool $invertMatch = false,
        public ?int $maxCount = null,
        public int $beforeContext = 0,
        public int $afterContext = 0,
        public bool $countOnly = false,
        public bool $filesWithMatches = false,
        public bool $filesWithoutMatches = false,
        public bool $quiet = false,
        public bool $jsonOutput = false,
        public bool $collectCaptures = true,
        public bool $tracePlan = false,
        public int $jobs = 1,
        public bool $respectIgnore = true,
        public bool $includeHidden = false,
        public bool $followSymlinks = false,
        public bool $skipBinaryFiles = true,
        public bool $includeGitDirectory = false,
        public ?FileTypeFilter $fileTypeFilter = null,
        public int $maxFileSizeBytes = 10485760,
        public array $globPatterns = [],
        public bool $showLineNumbers = true,
        public bool $showFileNames = true,
    ) {
        if ($this->jobs < 1) {
            throw new \InvalidArgumentException('Job count must be greater than zero.');
        }

        if ($this->beforeContext < 0 || $this->afterContext < 0) {
            throw new \InvalidArgumentException('Context values must not be negative.');
        }

        if ($this->maxCount !== null && $this->maxCount < 1) {
            throw new \InvalidArgumentException('Max count must be greater than zero when provided.');
        }
    }

    public function walkOptions(): WalkOptions
    {
        return new WalkOptions(
            respectIgnore: $this->respectIgnore,
            includeHidden: $this->includeHidden,
            followSymlinks: $this->followSymlinks,
            skipBinaryFiles: $this->skipBinaryFiles,
            includeGitDirectory: $this->includeGitDirectory,
            fileTypeFilter: $this->fileTypeFilter,
            maxFileSizeBytes: $this->maxFileSizeBytes,
            globPatterns: $this->globPatterns,
        );
    }
}
