<?php

declare(strict_types=1);

namespace Greph\Ast;

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

        if ($patternRoot->getType() !== $candidate->getType()) {
            return false;
        }

        return match (true) {
            $patternRoot instanceof Expr\Array_ && $candidate instanceof Expr\Array_ => $this->matchesArrayKind($patternRoot, $candidate),
            $patternRoot instanceof Expr\FuncCall && $candidate instanceof Expr\FuncCall => $this->matchesNameNode($patternRoot->name, $candidate->name),
            $patternRoot instanceof Expr\New_ && $candidate instanceof Expr\New_ => $this->matchesNameNode($patternRoot->class, $candidate->class)
                && $this->matchesEmptyArgumentList($patternRoot->args, $candidate->args),
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

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $patternArgs
     * @param array<Node\Arg|Node\VariadicPlaceholder> $candidateArgs
     */
    private function matchesEmptyArgumentList(array $patternArgs, array $candidateArgs): bool
    {
        if ($patternArgs !== []) {
            return true;
        }

        return $candidateArgs === [];
    }

    private function arrayKind(Expr\Array_ $node): ?int
    {
        $kind = $node->getAttribute('kind');

        if (is_int($kind)) {
            return $kind;
        }

        return property_exists($node, 'kind') && is_int($node->kind) ? $node->kind : null;
    }
}
