<?php

declare(strict_types=1);

namespace Phgrep\Ast;

final readonly class RewriteResult
{
    public function __construct(
        public string $file,
        public string $originalContents,
        public string $rewrittenContents,
        public int $replacementCount,
    ) {
    }

    public function changed(): bool
    {
        return $this->originalContents !== $this->rewrittenContents;
    }
}
