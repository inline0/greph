<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class AstPatternPrefilter
{
    private const COMMENT_AWARE_GAP_PATTERN = '(?:(?:\s+)|(?:\/\*.*?\*\/)|(?:\/\/[^\n]*(?:\n|$))|(?:#[^\n]*(?:\n|$)))*';

    /**
     * @return list<string>
     */
    public function extract(Node $node): array
    {
        $tokens = [];
        $this->collect($node, $tokens);

        $tokens = array_values(array_unique(array_filter(
            array_map(static fn (string $token): string => strtolower(trim($token)), $tokens),
            static fn (string $token): bool => $token !== '',
        )));

        usort(
            $tokens,
            static fn (string $left, string $right): int => [strlen($right), $left] <=> [strlen($left), $right]
        );

        return $tokens;
    }

    /**
     * @param list<string> $tokens
     */
    public function mayMatch(array $tokens, string $contents): bool
    {
        foreach ($tokens as $token) {
            if (stripos($contents, $token) === false) {
                return false;
            }
        }

        return true;
    }

    public function mayMatchPattern(Node $pattern, string $contents): bool
    {
        return match (true) {
            $pattern instanceof Expr\Array_ && $this->isLongArraySyntax($pattern) => $this->hasLongArraySyntax($contents),
            $pattern instanceof Expr\New_ && $pattern->args === [] => $this->hasZeroArgumentNewExpression($contents),
            default => true,
        };
    }

    /**
     * @param list<string> $tokens
     */
    private function collect(Node $node, array &$tokens): void
    {
        if (MetaVariable::singleName($node) !== null) {
            return;
        }

        foreach ($this->keywordsForNode($node) as $keyword) {
            $tokens[] = $keyword;
        }

        if ($node instanceof Node\Identifier) {
            $tokens[] = $node->toString();
        } elseif ($node instanceof Node\Name) {
            $tokens[] = $node->toString();
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @var mixed $subNode */
            $subNode = $node->$subNodeName;

            if ($subNode instanceof Node) {
                $this->collect($subNode, $tokens);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $childNode) {
                    if ($childNode instanceof Node) {
                        $this->collect($childNode, $tokens);
                    }
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function keywordsForNode(Node $node): array
    {
        return match (true) {
            $node instanceof Expr\Array_ && $node->getAttribute('kind') === Expr\Array_::KIND_LONG => ['array'],
            $node instanceof Expr\Empty_ => ['empty'],
            $node instanceof Expr\Include_ => [$this->includeKeyword($node)],
            $node instanceof Expr\Instanceof_ => ['instanceof'],
            $node instanceof Expr\Isset_ => ['isset'],
            $node instanceof Expr\New_ => ['new'],
            $node instanceof Stmt\If_ => ['if'],
            $node instanceof Stmt\ElseIf_ => ['elseif'],
            $node instanceof Stmt\For_ => ['for'],
            $node instanceof Stmt\Foreach_ => ['foreach'],
            $node instanceof Stmt\Switch_ => ['switch'],
            $node instanceof Stmt\While_ => ['while'],
            default => [],
        };
    }

    private function includeKeyword(Expr\Include_ $node): string
    {
        return match ($node->type) {
            Expr\Include_::TYPE_INCLUDE => 'include',
            Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
            Expr\Include_::TYPE_REQUIRE => 'require',
            Expr\Include_::TYPE_REQUIRE_ONCE => 'require_once',
            default => 'include',
        };
    }

    private function hasLongArraySyntax(string $contents): bool
    {
        if (stripos($contents, 'array') === false) {
            return false;
        }

        if (preg_match('/\barray' . self::COMMENT_AWARE_GAP_PATTERN . '\(/is', $contents) !== 1) {
            return false;
        }

        $tokens = token_get_all($contents);
        $tokenCount = count($tokens);

        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];

            if (!is_array($token) || $token[0] !== T_ARRAY) {
                continue;
            }

            $nextIndex = $this->nextSignificantTokenIndex($tokens, $index + 1);

            if ($nextIndex !== null && $tokens[$nextIndex] === '(') {
                return true;
            }
        }

        return false;
    }

    private function hasZeroArgumentNewExpression(string $contents): bool
    {
        if (stripos($contents, 'new') === false) {
            return false;
        }

        if (
            preg_match(
                '/\bnew\b(?:(?![;{]).)*?(?:\(\s*\)|(?=' . self::COMMENT_AWARE_GAP_PATTERN . '[;{]))/is',
                $contents,
            ) !== 1
        ) {
            return false;
        }

        return $this->tokensContainZeroArgumentNewExpression(token_get_all($contents));
    }

    private function isLongArraySyntax(Expr\Array_ $node): bool
    {
        $kind = $node->getAttribute('kind');

        if (is_int($kind)) {
            return $kind === Expr\Array_::KIND_LONG;
        }

        return property_exists($node, 'kind') && $node->kind === Expr\Array_::KIND_LONG;
    }

    /**
     * @param list<int|string|array{int, string, int}> $tokens
     */
    private function nextSignificantTokenIndex(array $tokens, int $startIndex): ?int
    {
        $tokenCount = count($tokens);

        for ($index = $startIndex; $index < $tokenCount; $index++) {
            if (!$this->isIgnorableToken($tokens[$index])) {
                return $index;
            }
        }

        return null;
    }

    private function isIgnorableToken(mixed $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param list<int|string|array{int, string, int}> $tokens
     */
    private function tokensContainZeroArgumentNewExpression(array $tokens): bool
    {
        $tokenCount = count($tokens);

        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];

            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $nextIndex = $this->nextSignificantTokenIndex($tokens, $index + 1);

            if ($nextIndex === null) {
                continue;
            }

            for ($cursor = $nextIndex; $cursor < $tokenCount; $cursor++) {
                $current = $tokens[$cursor];

                if ($this->isIgnorableToken($current)) {
                    continue;
                }

                if ($current === '(') {
                    $closingIndex = $this->nextSignificantTokenIndex($tokens, $cursor + 1);

                    if ($closingIndex !== null && $tokens[$closingIndex] === ')') {
                        return true;
                    }

                    continue 2;
                }

                if ($current === ';' || $current === '{') {
                    return true;
                }
            }
        }

        return false;
    }
}
