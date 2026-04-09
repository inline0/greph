<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use PhpParser\Node;

final class AstCandidateFinder
{
    /**
     * @param list<Node> $nodes
     * @return list<Node>
     */
    public function find(array $nodes, Pattern $pattern): array
    {
        $targetClass = MetaVariable::singleName($pattern->root) === null
            ? $pattern->root::class
            : null;
        $stack = array_reverse($nodes);
        $candidates = [];

        while ($stack !== []) {
            /** @var Node $node */
            $node = array_pop($stack);

            if ($targetClass === null || $node::class === $targetClass) {
                $candidates[] = $node;
            }

            $childNodes = [];

            foreach ($node->getSubNodeNames() as $subNodeName) {
                /** @var mixed $subNode */
                $subNode = $node->$subNodeName;

                if ($subNode instanceof Node) {
                    $childNodes[] = $subNode;
                } elseif (is_array($subNode)) {
                    foreach ($subNode as $childNode) {
                        if ($childNode instanceof Node) {
                            $childNodes[] = $childNode;
                        }
                    }
                }
            }

            foreach (array_reverse($childNodes) as $childNode) {
                $stack[] = $childNode;
            }
        }

        return $candidates;
    }
}
