<?php

declare(strict_types=1);

namespace Phgrep\Ast;

final readonly class AstSourceBuffer
{
    public function __construct(private string $source)
    {
    }

    public function slice(int $startFilePos, int $endFilePos): string
    {
        return substr($this->source, $startFilePos, ($endFilePos - $startFilePos) + 1);
    }
}
