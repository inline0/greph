<?php

declare(strict_types=1);

namespace Phgrep\Text;

final readonly class LineMatch
{
    /**
     * @param array<int|string, string> $captures
     */
    public function __construct(
        public int $column,
        public string $matchedText,
        public array $captures = [],
    ) {
    }
}
