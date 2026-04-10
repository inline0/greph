<?php

declare(strict_types=1);

namespace Greph\Text;

final class LiteralExtractor
{
    /**
     * @return array{type: 'literal'|'prefix'|'suffix'|'full-line', literal: string}|null
     */
    public function extractRegexLiteralPlan(string $pattern): ?array
    {
        $anchoredStart = false;
        $anchoredEnd = false;
        $length = strlen($pattern);

        if ($length > 0 && $pattern[0] === '^') {
            $anchoredStart = true;
            $pattern = substr($pattern, 1);
            $length--;
        }

        if ($length > 0 && $this->endsWithUnescapedDollar($pattern)) {
            $anchoredEnd = true;
            $pattern = substr($pattern, 0, -1);
        }

        $literal = $this->decodeLiteralRegexBody($pattern);

        if ($literal === null) {
            return null;
        }

        return match (true) {
            $anchoredStart && $anchoredEnd => ['type' => 'full-line', 'literal' => $literal],
            $anchoredStart => ['type' => 'prefix', 'literal' => $literal],
            $anchoredEnd => ['type' => 'suffix', 'literal' => $literal],
            default => ['type' => 'literal', 'literal' => $literal],
        };
    }

    public function extract(string $pattern): ?string
    {
        $segments = $this->extractSegments($pattern);

        if ($segments === []) {
            return null;
        }

        return $segments[0];
    }

    /**
     * @return list<string>
     */
    public function extractSegments(string $pattern): array
    {
        $segments = [];
        $segment = '';
        $length = strlen($pattern);
        $inCharacterClass = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];

            if ($character === '\\') {
                if (isset($pattern[$index + 1])) {
                    $next = $pattern[$index + 1];

                    if (ctype_alnum($next)) {
                        $this->flushSegment($segments, $segment);
                    } else {
                        $segment .= $next;
                    }

                    $index++;
                }

                continue;
            }

            if ($character === '[') {
                $inCharacterClass = true;
                $this->flushSegment($segments, $segment);
                continue;
            }

            if ($character === ']') {
                $inCharacterClass = false;
                continue;
            }

            if ($inCharacterClass) {
                continue;
            }

            if (str_contains('.*+?()|{}^$', $character)) {
                $this->flushSegment($segments, $segment);
                continue;
            }

            $segment .= $character;
        }

        $this->flushSegment($segments, $segment);

        $segments = array_values(array_unique($segments));
        usort(
            $segments,
            static fn (string $left, string $right): int => [strlen($right), $right] <=> [strlen($left), $left]
        );

        return $segments;
    }

    /**
     * @param list<string> $segments
     */
    private function flushSegment(array &$segments, string &$segment): void
    {
        if ($segment !== '') {
            $segments[] = $segment;
            $segment = '';
        }
    }

    private function endsWithUnescapedDollar(string $pattern): bool
    {
        if ($pattern === '' || !str_ends_with($pattern, '$')) {
            return false;
        }

        $backslashes = 0;

        for ($index = strlen($pattern) - 2; $index >= 0 && $pattern[$index] === '\\'; $index--) {
            $backslashes++;
        }

        return $backslashes % 2 === 0;
    }

    private function decodeLiteralRegexBody(string $pattern): ?string
    {
        $literal = '';
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];

            if ($character === '\\') {
                if (!isset($pattern[$index + 1])) {
                    return null;
                }

                $next = $pattern[$index + 1];

                if (ctype_alnum($next)) {
                    return null;
                }

                $literal .= $next;
                $index++;
                continue;
            }

            if (str_contains('.*+?()|[]{}^$', $character)) {
                return null;
            }

            $literal .= $character;
        }

        return $literal;
    }
}
