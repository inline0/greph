<?php

declare(strict_types=1);

namespace Greph\Text;

final readonly class TextFileResult
{
    /**
     * @param list<TextMatch> $matches
     */
    public function __construct(
        public string $file,
        public array $matches,
        private ?int $explicitMatchCount = null,
    ) {
    }

    public function matchCount(): int
    {
        return $this->explicitMatchCount ?? count($this->matches);
    }

    public function hasMatches(): bool
    {
        return $this->matchCount() > 0;
    }
}
