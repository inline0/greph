<?php

declare(strict_types=1);

namespace Phgrep\Text;

final readonly class TextMatch
{
    /**
     * @param array<int|string, string> $captures
     * @param list<array{line: int, content: string}> $beforeContext
     * @param list<array{line: int, content: string}> $afterContext
     */
    public function __construct(
        public string $file,
        public int $line,
        public int $column,
        public string $content,
        public string $matchedText = '',
        public array $captures = [],
        public array $beforeContext = [],
        public array $afterContext = [],
    ) {
    }
}
