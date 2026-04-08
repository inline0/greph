<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use PhpParser\Node;

final readonly class Pattern
{
    public function __construct(
        public Node $root,
        public bool $isExpression,
    ) {
    }
}
