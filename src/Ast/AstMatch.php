<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use PhpParser\Node;

final readonly class AstMatch
{
    /**
     * @param array<string, mixed> $captures
     */
    public function __construct(
        public string $file,
        public Node $node,
        public array $captures,
        public int $startLine,
        public int $endLine,
        public int $startFilePos,
        public int $endFilePos,
        public string $code,
    ) {
    }
}
