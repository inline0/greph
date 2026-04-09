<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class AstPatternPrefilter
{
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
        $length = strlen($contents);
        $index = 0;

        while ($index < $length) {
            if ($this->skipNonCode($contents, $index, $length)) {
                continue;
            }

            $identifier = $this->readIdentifier($contents, $index, $length);

            if ($identifier === null || strtolower($identifier) !== 'array') {
                if ($identifier === null) {
                    $index++;
                }

                continue;
            }

            $cursor = $this->skipGapTokens($contents, $index, $length);

            if ($cursor < $length && $contents[$cursor] === '(') {
                return true;
            }

            $index = $cursor;
        }

        return false;
    }

    private function hasZeroArgumentNewExpression(string $contents): bool
    {
        $length = strlen($contents);
        $index = 0;

        while ($index < $length) {
            if ($this->skipNonCode($contents, $index, $length)) {
                continue;
            }

            $identifier = $this->readIdentifier($contents, $index, $length);

            if ($identifier === null || strtolower($identifier) !== 'new') {
                if ($identifier === null) {
                    $index++;
                }

                continue;
            }

            if ($this->matchesZeroArgumentNewFrom($contents, $index, $length)) {
                return true;
            }
        }

        return false;
    }

    private function isLongArraySyntax(Expr\Array_ $node): bool
    {
        $kind = $node->getAttribute('kind');

        if (is_int($kind)) {
            return $kind === Expr\Array_::KIND_LONG;
        }

        return property_exists($node, 'kind') && $node->kind === Expr\Array_::KIND_LONG;
    }

    private function matchesZeroArgumentNewFrom(string $contents, int $startIndex, int $length): bool
    {
        $index = $this->skipGapTokens($contents, $startIndex, $length);

        while ($index < $length) {
            $index = $this->skipGapTokens($contents, $index, $length);

            if ($index >= $length) {
                return false;
            }

            $character = $contents[$index];

            if ($character === ';' || $character === '{') {
                return true;
            }

            if ($character === '(') {
                $closingIndex = $this->skipGapTokens($contents, $index + 1, $length);

                return $closingIndex < $length && $contents[$closingIndex] === ')';
            }

            if ($character === '$') {
                $index++;
                $this->consumeIdentifierTail($contents, $index, $length);
                continue;
            }

            if ($character === '\\') {
                $index++;
                continue;
            }

            if ($character === ':' && ($contents[$index + 1] ?? '') === ':') {
                $index += 2;
                continue;
            }

            if ($character === '-' && ($contents[$index + 1] ?? '') === '>') {
                $index += 2;
                continue;
            }

            if ($this->readIdentifier($contents, $index, $length) !== null) {
                continue;
            }

            $index++;
        }

        return false;
    }

    private function skipGapTokens(string $contents, int $startIndex, int $length): int
    {
        $index = $startIndex;

        while ($index < $length) {
            $character = $contents[$index];

            if (ctype_space($character)) {
                $index++;
                continue;
            }

            if ($character === '/' && ($contents[$index + 1] ?? '') === '/') {
                $index += 2;

                while ($index < $length && $contents[$index] !== "\n") {
                    $index++;
                }

                continue;
            }

            if ($character === '#') {
                $index++;

                while ($index < $length && $contents[$index] !== "\n") {
                    $index++;
                }

                continue;
            }

            if ($character === '/' && ($contents[$index + 1] ?? '') === '*') {
                $index += 2;

                while ($index + 1 < $length && !($contents[$index] === '*' && $contents[$index + 1] === '/')) {
                    $index++;
                }

                $index = min($length, $index + 2);
                continue;
            }

            break;
        }

        return $index;
    }

    private function skipNonCode(string $contents, int &$index, int $length): bool
    {
        $start = $index;
        $index = $this->skipGapTokens($contents, $index, $length);

        if ($index !== $start) {
            return true;
        }

        $character = $contents[$index] ?? null;

        if ($character === null) {
            return false;
        }

        if ($character === '\'' || $character === '"' || $character === '`') {
            $this->skipQuotedString($contents, $index, $length, $character);

            return true;
        }

        return false;
    }

    private function skipQuotedString(string $contents, int &$index, int $length, string $quote): void
    {
        $index++;

        while ($index < $length) {
            if ($contents[$index] === '\\') {
                $index += 2;
                continue;
            }

            if ($contents[$index] === $quote) {
                $index++;

                return;
            }

            $index++;
        }
    }

    private function readIdentifier(string $contents, int &$index, int $length): ?string
    {
        if (!$this->isIdentifierStart($contents[$index] ?? null)) {
            return null;
        }

        $start = $index;
        $index++;
        $this->consumeIdentifierTail($contents, $index, $length);

        return substr($contents, $start, $index - $start);
    }

    private function consumeIdentifierTail(string $contents, int &$index, int $length): void
    {
        while ($index < $length && $this->isIdentifierPart($contents[$index])) {
            $index++;
        }
    }

    private function isIdentifierStart(?string $character): bool
    {
        return $character !== null && ($character === '_' || ctype_alpha($character));
    }

    private function isIdentifierPart(string $character): bool
    {
        return $character === '_' || ctype_alnum($character);
    }
}
