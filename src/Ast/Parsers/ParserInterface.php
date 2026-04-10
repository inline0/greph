<?php

declare(strict_types=1);

namespace Greph\Ast\Parsers;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

interface ParserInterface
{
    /**
     * @return list<Stmt>
     */
    public function parseStatements(string $code): array;

    public function parseExpression(string $code): Expr;
}
