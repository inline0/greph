<?php

declare(strict_types=1);

namespace Greph\Index;

use Greph\Ast\Pattern;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final class AstFactQuery
{
    /**
     * @param array<int, array{
     *   zero_arg_new: bool,
     *   long_array: bool,
     *   function_calls: list<string>,
     *   method_calls: list<string>,
     *   static_calls: list<string>,
     *   new_targets: list<string>,
     *   classes: list<string>,
     *   interfaces: list<string>,
     *   traits: list<string>
     * }> $factsByFileId
     * @return array<int, true>|null
     */
    public function candidateIds(array $factsByFileId, Pattern $pattern): ?array
    {
        $predicate = $this->predicate($pattern->root);

        if ($predicate === null) {
            return null;
        }

        $candidateIds = [];

        foreach ($factsByFileId as $fileId => $facts) {
            if ($predicate($facts)) {
                $candidateIds[$fileId] = true;
            }
        }

        return $candidateIds;
    }

    /**
     * @return (callable(array{
     *   zero_arg_new: bool,
     *   long_array: bool,
     *   function_calls: list<string>,
     *   method_calls: list<string>,
     *   static_calls: list<string>,
     *   new_targets: list<string>,
     *   classes: list<string>,
     *   interfaces: list<string>,
     *   traits: list<string>
     * }): bool)|null
     */
    public function predicate(Node $root): ?callable
    {
        if ($root instanceof Expr\Array_ && $this->isLongArraySyntax($root)) {
            return static fn (array $facts): bool => $facts['long_array'];
        }

        if ($root instanceof Expr\New_ && $root->args === []) {
            $targetName = $root->class instanceof Name ? strtolower($root->class->toString()) : null;

            return static function (array $facts) use ($targetName): bool {
                if (!$facts['zero_arg_new']) {
                    return false;
                }

                if ($targetName === null) {
                    return true;
                }

                return in_array($targetName, $facts['new_targets'], true);
            };
        }

        if ($root instanceof Expr\FuncCall && $root->name instanceof Name) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['function_calls'], true);
        }

        if (($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) && $root->name instanceof Identifier) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['method_calls'], true);
        }

        if ($root instanceof Expr\StaticCall && $root->name instanceof Identifier) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['static_calls'], true);
        }

        if ($root instanceof Stmt\Class_ && $root->name instanceof Identifier) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['classes'], true);
        }

        if ($root instanceof Stmt\Interface_ && $root->name instanceof Identifier) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['interfaces'], true);
        }

        if ($root instanceof Stmt\Trait_ && $root->name instanceof Identifier) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['traits'], true);
        }

        return null;
    }

    private function isLongArraySyntax(Expr\Array_ $node): bool
    {
        $kind = $node->getAttribute('kind');

        if (is_int($kind)) {
            return $kind === Expr\Array_::KIND_LONG;
        }

        return property_exists($node, 'kind') && $node->kind === Expr\Array_::KIND_LONG;
    }
}
