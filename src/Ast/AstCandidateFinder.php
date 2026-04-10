<?php

declare(strict_types=1);

namespace Greph\Ast;

use PhpParser\Node;

final class AstCandidateFinder
{
    /**
     * @param list<Node> $nodes
     * @return list<Node>
     */
    public function find(array $nodes, Pattern $pattern, ?AstRootMatcher $rootMatcher = null): array
    {
        return iterator_to_array($this->iterate($nodes, $pattern, $rootMatcher), false);
    }

    /**
     * @param list<Node> $nodes
     * @return \Generator<int, Node>
     */
    public function iterate(array $nodes, Pattern $pattern, ?AstRootMatcher $rootMatcher = null): \Generator
    {
        $patternRoot = $pattern->root;
        $targetClass = MetaVariable::singleName($patternRoot) === null
            ? $patternRoot::class
            : null;
        $stack = array_reverse($nodes);

        while ($stack !== []) {
            /** @var Node $node */
            $node = array_pop($stack);

            if (
                ($targetClass === null || $node::class === $targetClass)
                && ($rootMatcher === null || $rootMatcher->mayMatch($patternRoot, $node))
            ) {
                yield $node;
            }

            $subNodeNames = $node->getSubNodeNames();

            for ($subNodeIndex = count($subNodeNames) - 1; $subNodeIndex >= 0; $subNodeIndex--) {
                $subNodeName = $subNodeNames[$subNodeIndex];
                /** @var mixed $subNode */
                $subNode = $node->$subNodeName;

                if ($subNode instanceof Node) {
                    $stack[] = $subNode;
                } elseif (is_array($subNode)) {
                    for ($childIndex = count($subNode) - 1; $childIndex >= 0; $childIndex--) {
                        $childNode = $subNode[$childIndex] ?? null;

                        if ($childNode instanceof Node) {
                            $stack[] = $childNode;
                        }
                    }
                }
            }
        }
    }
}
