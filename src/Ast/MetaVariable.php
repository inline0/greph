<?php

declare(strict_types=1);

namespace Greph\Ast;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;

final class MetaVariable
{
    public const IDENTIFIER_PREFIX = '__greph_ident_';
    public const VARIADIC_PREFIX = '__greph_variadic_';

    public static function preprocess(string $pattern): string
    {
        $pattern = preg_replace_callback(
            '/\$\$\$([A-Za-z_][A-Za-z0-9_]*)/',
            static fn (array $matches): string => '...$' . self::VARIADIC_PREFIX . $matches[1],
            $pattern,
        ) ?? $pattern;

        $pattern = preg_replace_callback(
            '/\b(function|class|interface|trait|enum)\s+\$([A-Za-z_][A-Za-z0-9_]*)/',
            static fn (array $matches): string => $matches[1] . ' ' . self::IDENTIFIER_PREFIX . $matches[2],
            $pattern,
        ) ?? $pattern;

        return $pattern;
    }

    public static function singleName(Node $node): ?string
    {
        if ($node instanceof Variable && is_string($node->name) && !str_starts_with($node->name, self::VARIADIC_PREFIX)) {
            return $node->name;
        }

        if ($node instanceof Identifier && str_starts_with($node->name, self::IDENTIFIER_PREFIX)) {
            return substr($node->name, strlen(self::IDENTIFIER_PREFIX));
        }

        if ($node instanceof Name) {
            $name = $node->toString();

            if (str_starts_with($name, self::IDENTIFIER_PREFIX)) {
                return substr($name, strlen(self::IDENTIFIER_PREFIX));
            }
        }

        return null;
    }

    public static function isNonCapturing(string $name): bool
    {
        return $name === '_';
    }

    public static function variadicName(mixed $node): ?string
    {
        if ($node instanceof Arg && $node->unpack && $node->value instanceof Variable && is_string($node->value->name)) {
            return self::stripVariadicPrefix($node->value->name);
        }

        if ($node instanceof ArrayItem && $node->unpack && $node->value instanceof Variable && is_string($node->value->name)) {
            return self::stripVariadicPrefix($node->value->name);
        }

        if ($node instanceof Param && $node->variadic && $node->var instanceof Variable && is_string($node->var->name)) {
            return self::stripVariadicPrefix($node->var->name);
        }

        return null;
    }

    private static function stripVariadicPrefix(string $name): ?string
    {
        if (!str_starts_with($name, self::VARIADIC_PREFIX)) {
            return null;
        }

        return substr($name, strlen(self::VARIADIC_PREFIX));
    }
}
