<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;

final class AstRootMatcher
{
    public function mayMatch(Node $patternRoot, Node $candidate): bool
    {
        if (MetaVariable::singleName($patternRoot) !== null) {
            return true;
        }

        if ($patternRoot::class !== $candidate::class) {
            return false;
        }

        if (!$this->matchesFixedArrayArity($patternRoot, $candidate)) {
            return false;
        }

        return match (true) {
            $patternRoot instanceof Expr\Array_ && $candidate instanceof Expr\Array_ => $this->matchesArrayKind($patternRoot, $candidate),
            $patternRoot instanceof Expr\FuncCall && $candidate instanceof Expr\FuncCall => $this->matchesNameNode($patternRoot->name, $candidate->name),
            $patternRoot instanceof Expr\New_ && $candidate instanceof Expr\New_ => $this->matchesNameNode($patternRoot->class, $candidate->class),
            $patternRoot instanceof Expr\MethodCall && $candidate instanceof Expr\MethodCall => $this->matchesIdentifierNode($patternRoot->name, $candidate->name),
            $patternRoot instanceof Expr\StaticCall && $candidate instanceof Expr\StaticCall => $this->matchesIdentifierNode($patternRoot->name, $candidate->name),
            $patternRoot instanceof Expr\PropertyFetch && $candidate instanceof Expr\PropertyFetch => $this->matchesIdentifierNode($patternRoot->name, $candidate->name),
            $patternRoot instanceof Expr\StaticPropertyFetch && $candidate instanceof Expr\StaticPropertyFetch => $this->matchesIdentifierNode($patternRoot->name, $candidate->name),
            $patternRoot instanceof Expr\ClassConstFetch && $candidate instanceof Expr\ClassConstFetch => $this->matchesIdentifierNode($patternRoot->name, $candidate->name),
            default => true,
        };
    }

    private function matchesNameNode(Node $pattern, Node $candidate): bool
    {
        if (MetaVariable::singleName($pattern) !== null) {
            return true;
        }

        if ($pattern instanceof Name && $candidate instanceof Name) {
            return strtolower($pattern->toString()) === strtolower($candidate->toString());
        }

        return true;
    }

    private function matchesIdentifierNode(Node $pattern, Node $candidate): bool
    {
        if (MetaVariable::singleName($pattern) !== null) {
            return true;
        }

        if ($pattern instanceof \PhpParser\Node\Identifier && $candidate instanceof \PhpParser\Node\Identifier) {
            return strtolower($pattern->name) === strtolower($candidate->name);
        }

        return true;
    }

    private function matchesArrayKind(Expr\Array_ $pattern, Expr\Array_ $candidate): bool
    {
        $patternKind = $this->arrayKind($pattern);
        $candidateKind = $this->arrayKind($candidate);

        if ($patternKind === null || $candidateKind === null) {
            return true;
        }

        return $patternKind === $candidateKind;
    }

    private function arrayKind(Expr\Array_ $node): ?int
    {
        $kind = $node->getAttribute('kind');

        if (is_int($kind)) {
            return $kind;
        }

        return property_exists($node, 'kind') && is_int($node->kind) ? $node->kind : null;
    }

    private function matchesFixedArrayArity(Node $pattern, Node $candidate): bool
    {
        foreach ($pattern->getSubNodeNames() as $subNodeName) {
            /** @var mixed $patternValue */
            $patternValue = $pattern->$subNodeName;
            /** @var mixed $candidateValue */
            $candidateValue = $candidate->$subNodeName;

            if (
                is_array($patternValue)
                && is_array($candidateValue)
                && !$this->containsVariadicMetaVariable($patternValue)
                && count($patternValue) !== count($candidateValue)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $values
     */
    private function containsVariadicMetaVariable(array $values): bool
    {
        foreach ($values as $value) {
            if (MetaVariable::variadicName($value) !== null) {
                return true;
            }
        }

        return false;
    }
}
