<?php

declare(strict_types=1);

namespace Phgrep\Index;

final class AstFactExtractor
{
    private const DECLARATION_TOKENS = [
        T_CLASS,
        T_INTERFACE,
        T_TRAIT,
    ];

    /**
     * @return array{
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
    public function extract(string $contents): array
    {
        $tokens = token_get_all($contents);

        $facts = [
            'zero_arg_new' => false,
            'long_array' => false,
            'function_calls' => [],
            'method_calls' => [],
            'static_calls' => [],
            'new_targets' => [],
            'classes' => [],
            'interfaces' => [],
            'traits' => [],
        ];

        $tokenCount = count($tokens);

        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];

            if (!is_array($token)) {
                continue;
            }

            $tokenId = $token[0];

            if ($tokenId === T_ARRAY && $this->hasOpeningParenthesis($tokens, $index + 1)) {
                $facts['long_array'] = true;
                continue;
            }

            if ($tokenId === T_NEW) {
                if ($this->hasZeroArgumentNewExpression($tokens, $index)) {
                    $facts['zero_arg_new'] = true;
                }

                $newTarget = $this->newTargetName($tokens, $index);

                if ($newTarget !== null) {
                    $facts['new_targets'][$newTarget] = true;
                }

                continue;
            }

            if (in_array($tokenId, self::DECLARATION_TOKENS, true)) {
                $name = $this->nameAt($tokens, $this->nextSignificantTokenIndex($tokens, $index + 1));

                if ($name === null) {
                    continue;
                }

                match ($tokenId) {
                    T_CLASS => $facts['classes'][$name] = true,
                    T_INTERFACE => $facts['interfaces'][$name] = true,
                    T_TRAIT => $facts['traits'][$name] = true,
                };

                continue;
            }

            $name = $this->nameAt($tokens, $index);

            if ($name === null) {
                continue;
            }

            $previousIndex = $this->previousSignificantTokenIndex($tokens, $index - 1);
            $nextIndex = $this->nextSignificantTokenIndex($tokens, $index + 1);
            $previousToken = $previousIndex !== null ? $tokens[$previousIndex] : null;
            $nextToken = $nextIndex !== null ? $tokens[$nextIndex] : null;

            if ($this->isObjectOperator($previousToken)) {
                $facts['method_calls'][$name] = true;
                continue;
            }

            if ($this->tokenId($previousToken) === T_DOUBLE_COLON) {
                $facts['static_calls'][$name] = true;
                continue;
            }

            if (
                $nextToken === '('
                && !$this->blocksFunctionCallClassification($previousToken)
            ) {
                $facts['function_calls'][$name] = true;
            }
        }

        return [
            'zero_arg_new' => $facts['zero_arg_new'],
            'long_array' => $facts['long_array'],
            'function_calls' => array_keys($facts['function_calls']),
            'method_calls' => array_keys($facts['method_calls']),
            'static_calls' => array_keys($facts['static_calls']),
            'new_targets' => array_keys($facts['new_targets']),
            'classes' => array_keys($facts['classes']),
            'interfaces' => array_keys($facts['interfaces']),
            'traits' => array_keys($facts['traits']),
        ];
    }

    /**
     * @param list<int|string|array{int, string, int}> $tokens
     */
    private function hasOpeningParenthesis(array $tokens, int $startIndex): bool
    {
        $nextIndex = $this->nextSignificantTokenIndex($tokens, $startIndex);

        return $nextIndex !== null && $tokens[$nextIndex] === '(';
    }

    /**
     * @param list<int|string|array{int, string, int}> $tokens
     */
    private function hasZeroArgumentNewExpression(array $tokens, int $newIndex): bool
    {
        $tokenCount = count($tokens);
        $cursor = $this->nextSignificantTokenIndex($tokens, $newIndex + 1);

        if ($cursor === null) {
            return false;
        }

        for (; $cursor < $tokenCount; $cursor++) {
            $token = $tokens[$cursor];

            if ($this->isIgnorableToken($token)) {
                continue;
            }

            if ($token === '(') {
                $closingIndex = $this->nextSignificantTokenIndex($tokens, $cursor + 1);

                return $closingIndex !== null && $tokens[$closingIndex] === ')';
            }

            if ($token === ';' || $token === '{') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int|string|array{int, string, int}> $tokens
     */
    private function newTargetName(array $tokens, int $newIndex): ?string
    {
        $tokenCount = count($tokens);

        for ($cursor = $newIndex + 1; $cursor < $tokenCount; $cursor++) {
            $token = $tokens[$cursor];

            if ($this->isIgnorableToken($token)) {
                continue;
            }

            if ($token === '(' || $token === ';' || $token === '{') {
                return null;
            }

            $name = $this->nameAt($tokens, $cursor);

            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param list<int|string|array{int, string, int}> $tokens
     */
    private function nameAt(array $tokens, ?int $index): ?string
    {
        if ($index === null || !isset($tokens[$index])) {
            return null;
        }

        $token = $tokens[$index];

        if (!is_array($token)) {
            return null;
        }

        $tokenId = $token[0];

        if (
            $tokenId === T_STRING
            || (defined('T_NAME_QUALIFIED') && $tokenId === T_NAME_QUALIFIED)
            || (defined('T_NAME_FULLY_QUALIFIED') && $tokenId === T_NAME_FULLY_QUALIFIED)
        ) {
            return strtolower(ltrim($token[1], '\\'));
        }

        return null;
    }

    /**
     * @param list<int|string|array{int, string, int}> $tokens
     */
    private function nextSignificantTokenIndex(array $tokens, int $startIndex): ?int
    {
        $tokenCount = count($tokens);

        for ($index = $startIndex; $index < $tokenCount; $index++) {
            if (!$this->isIgnorableToken($tokens[$index])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<int|string|array{int, string, int}> $tokens
     */
    private function previousSignificantTokenIndex(array $tokens, int $startIndex): ?int
    {
        for ($index = $startIndex; $index >= 0; $index--) {
            if (!$this->isIgnorableToken($tokens[$index])) {
                return $index;
            }
        }

        return null;
    }

    private function isIgnorableToken(mixed $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private function isObjectOperator(mixed $token): bool
    {
        if (!is_array($token)) {
            return false;
        }

        if ($token[0] === T_OBJECT_OPERATOR) {
            return true;
        }

        return defined('T_NULLSAFE_OBJECT_OPERATOR') && $token[0] === T_NULLSAFE_OBJECT_OPERATOR;
    }

    private function tokenId(mixed $token): ?int
    {
        return is_array($token) ? $token[0] : null;
    }

    private function blocksFunctionCallClassification(mixed $previousToken): bool
    {
        $tokenId = $this->tokenId($previousToken);

        if ($tokenId === null) {
            return false;
        }

        return in_array(
            $tokenId,
            [
                T_FUNCTION,
                T_FN,
                T_NEW,
                T_CLASS,
                T_INTERFACE,
                T_TRAIT,
                T_CONST,
                T_USE,
            ],
            true,
        ) || $this->isObjectOperator($previousToken) || $tokenId === T_DOUBLE_COLON;
    }
}
