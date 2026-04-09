<?php

declare(strict_types=1);

namespace Phgrep\Index;

final readonly class TextIndex
{
    /**
     * @param list<array{id: int, p: string, s: int, m: int, h: bool, g: bool, o: int}> $files
     * @param array<string, list<int>> $postings
     * @param array<int, list<string>> $forward
     * @param array<string, array<int, list<int>>> $wordPostings
     * @param array<int, array<string, list<int>>> $wordForward
     * @param array<int, list<int>> $lineOffsets
     */
    public function __construct(
        public string $rootPath,
        public string $indexPath,
        public int $version,
        public int $builtAt,
        public int $nextFileId,
        public array $files,
        public array $postings,
        public array $forward = [],
        public array $wordPostings = [],
        public array $wordForward = [],
        public array $lineOffsets = [],
    ) {
    }
}
