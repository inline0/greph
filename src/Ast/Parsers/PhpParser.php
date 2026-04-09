<?php

declare(strict_types=1);

namespace Phgrep\Ast\Parsers;

use Phgrep\Exceptions\ParseException;
use PhpParser\Error;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class PhpParser implements ParserInterface
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @return list<Stmt>
     */
    public function parseStatements(string $code): array
    {
        try {
            $source = $this->hasPhpOpenTag($code)
                ? $code
                : '<?php ' . $code;

            $statements = $this->parser->parse($source);
        } catch (Error $error) {
            throw new ParseException($error->getMessage(), 0, $error);
        }

        if ($statements === null) {
            throw new ParseException('Parser returned no statements.');
        }

        return array_values($statements);
    }

    public function parseExpression(string $code): Expr
    {
        $statements = $this->parseStatements($code . ';');

        if (count($statements) !== 1 || !$statements[0] instanceof Stmt\Expression) {
            throw new ParseException('Pattern is not a single expression.');
        }

        return $statements[0]->expr;
    }

    private function hasPhpOpenTag(string $code): bool
    {
        $length = strlen($code);
        $offset = 0;

        while ($offset < $length) {
            $byte = $code[$offset];

            if ($byte !== ' ' && $byte !== "\t" && $byte !== "\n" && $byte !== "\r" && $byte !== "\f" && $byte !== "\v") {
                break;
            }

            $offset++;
        }

        if ($offset >= $length || $code[$offset] !== '<') {
            return false;
        }

        return strncmp(substr($code, $offset, 5), '<?php', 5) === 0
            || strncmp(substr($code, $offset, 3), '<?=', 3) === 0;
    }
}
