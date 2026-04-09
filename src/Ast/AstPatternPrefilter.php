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
}
