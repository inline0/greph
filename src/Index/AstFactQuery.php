<?php

declare(strict_types=1);

namespace Greph\Index;

use Greph\Ast\Pattern;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * @phpstan-type FactSet array{
 *   zero_arg_new: bool,
 *   long_array: bool,
 *   function_calls: list<string>,
 *   method_calls: list<string>,
 *   static_calls: list<string>,
 *   new_targets: list<string>,
 *   classes: list<string>,
 *   interfaces: list<string>,
 *   traits: list<string>
 * }
 */
final class AstFactQuery
{
    /**
     * @param array<int, FactSet> $factsByFileId
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
     * @return (callable(FactSet): bool)|null
     */
    public function predicate(Node $root): ?callable
    {
        if ($root instanceof Expr\Array_ && $this->isLongArraySyntax($root)) {
            return self::longArrayPredicate();
        }

        if ($root instanceof Expr\New_ && $root->args === []) {
            $targetName = $root->class instanceof Name ? strtolower($root->class->toString()) : null;

            return self::zeroArgNewPredicate($targetName);
        }

        if ($root instanceof Expr\FuncCall && $root->name instanceof Name) {
            return self::functionCallPredicate(strtolower($root->name->toString()));
        }

        if (($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) && $root->name instanceof Identifier) {
            return self::methodCallPredicate(strtolower($root->name->toString()));
        }

        if ($root instanceof Expr\StaticCall && $root->name instanceof Identifier) {
            return self::staticCallPredicate(strtolower($root->name->toString()));
        }

        if ($root instanceof Stmt\Class_ && $root->name instanceof Identifier) {
            return self::classPredicate(strtolower($root->name->toString()));
        }

        if ($root instanceof Stmt\Interface_ && $root->name instanceof Identifier) {
            return self::interfacePredicate(strtolower($root->name->toString()));
        }

        if ($root instanceof Stmt\Trait_ && $root->name instanceof Identifier) {
            return self::traitPredicate(strtolower($root->name->toString()));
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

    /** @return callable(FactSet): bool */
    private static function longArrayPredicate(): callable
    {
        return self::wrapPredicate(static fn (array $facts): bool => (bool) ($facts['long_array'] ?? false));
    }

    /** @return callable(FactSet): bool */
    private static function zeroArgNewPredicate(?string $targetName): callable
    {
        return self::wrapPredicate(static function (array $facts) use ($targetName): bool {
            if (!($facts['zero_arg_new'] ?? false)) {
                return false;
            }

            if ($targetName === null) {
                return true;
            }

            $newTargets = $facts['new_targets'] ?? [];

            return is_array($newTargets) && in_array($targetName, $newTargets, true);
        });
    }

    /** @return callable(FactSet): bool */
    private static function functionCallPredicate(string $name): callable
    {
        return self::haystackPredicate($name, 'function_calls');
    }

    /** @return callable(FactSet): bool */
    private static function methodCallPredicate(string $name): callable
    {
        return self::haystackPredicate($name, 'method_calls');
    }

    /** @return callable(FactSet): bool */
    private static function staticCallPredicate(string $name): callable
    {
        return self::haystackPredicate($name, 'static_calls');
    }

    /** @return callable(FactSet): bool */
    private static function classPredicate(string $name): callable
    {
        return self::haystackPredicate($name, 'classes');
    }

    /** @return callable(FactSet): bool */
    private static function interfacePredicate(string $name): callable
    {
        return self::haystackPredicate($name, 'interfaces');
    }

    /** @return callable(FactSet): bool */
    private static function traitPredicate(string $name): callable
    {
        return self::haystackPredicate($name, 'traits');
    }

    /** @return callable(FactSet): bool */
    private static function haystackPredicate(string $needle, string $factKey): callable
    {
        return self::wrapPredicate(static function (array $facts) use ($needle, $factKey): bool {
            $haystack = $facts[$factKey] ?? [];

            return is_array($haystack) && in_array($needle, $haystack, true);
        });
    }

    /**
     * @param callable(array<array-key, mixed>): bool $predicate
     * @return callable(FactSet): bool
     */
    private static function wrapPredicate(callable $predicate): callable
    {
        return static fn (array $facts): bool => $predicate($facts);
    }
}
