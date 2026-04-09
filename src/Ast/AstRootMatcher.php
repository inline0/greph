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

        return match (true) {
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
}
