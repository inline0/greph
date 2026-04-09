<?php

declare(strict_types=1);

namespace Phgrep\Text;

interface TextMatcher
{
    public function match(string $line): ?LineMatch;

    public function mayMatchContents(string $contents): bool;
}
