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

    public function extract(string $pattern, bool $caseInsensitive = false): ?string
    {
        $segments = $this->extractSegments($pattern, $caseInsensitive);

        if ($segments === []) {
            return null;
        }

        return $segments[0];
    }

    /**
     * @return list<string>
     */
    public function extractSegments(string $pattern, bool $caseInsensitive = false): array
    {
        $position = 0;
        $segments = $this->requiredSegments($pattern, $position, false);

        if ($segments === null || $position !== strlen($pattern)) {
            return [];
        }

        if ($caseInsensitive) {
            $segments = array_filter($segments, self::isAsciiCaseFoldSafe(...));
        }

        $segments = array_values(array_unique($segments));
        usort(
            $segments,
            static fn (string $left, string $right): int => [strlen($right), $right] <=> [strlen($left), $left]
        );

        return $segments;
    }

    public static function isAsciiCaseFoldSafe(string $literal): bool
    {
        return preg_match('/[^\x00-\x7F]|[ksKS]/', $literal) !== 1;
    }

    /**
     * @return list<string>|null
     */
    private function requiredSegments(string $pattern, int &$position, bool $inGroup): ?array
    {
        $segments = [];
        $segment = '';
        $length = strlen($pattern);
        $alternation = false;

        while ($position < $length) {
            $character = $pattern[$position];

            if ($character === ')') {
                if (!$inGroup) {
                    return null;
                }

                break;
            }

            if ($character === '|') {
                $alternation = true;
                $this->flushSegment($segments, $segment);
                $position++;
                continue;
            }

            $atom = $this->readAtom($pattern, $position);

            if ($atom === null) {
                return null;
            }

            $quantifier = $this->readQuantifier($pattern, $position);

            if ($quantifier === false) {
                return null;
            }

            if ($atom['literal'] !== null) {
                if ($quantifier === 0) {
                    $this->flushSegment($segments, $segment);
                    continue;
                }

                $segment .= $atom['literal'];

                if ($quantifier !== null) {
                    $this->flushSegment($segments, $segment);
                }

                continue;
            }

            $this->flushSegment($segments, $segment);

            if ($quantifier !== 0) {
                foreach ($atom['segments'] as $groupSegment) {
                    $segments[] = $groupSegment;
                }
            }
        }

        $this->flushSegment($segments, $segment);

        return $alternation ? [] : $segments;
    }

    /**
     * @return array{literal: string|null, segments: list<string>}|null
     */
    private function readAtom(string $pattern, int &$position): ?array
    {
        $length = strlen($pattern);
        $character = $pattern[$position];
        $opaque = ['literal' => null, 'segments' => []];

        if ($character === '\\') {
            if ($position + 1 >= $length) {
                return null;
            }

            $next = $pattern[$position + 1];
            $position += 2;

            if (ord($next) >= 0x80) {
                return null;
            }

            if (ctype_alnum($next)) {
                return str_contains('dDwWsSbBAzZGhHvVRXKntrfea', $next) ? $opaque : null;
            }

            return ['literal' => $next, 'segments' => []];
        }

        if ($character === '[') {
            $end = $this->characterClassEnd($pattern, $position);

            if ($end === null) {
                return null;
            }

            $position = $end + 1;

            return $opaque;
        }

        if ($character === '(') {
            return $this->readGroup($pattern, $position);
        }

        if ($character === '*' || $character === '+' || $character === '?') {
            return null;
        }

        if ($character === '{') {
            if ($this->quantifierMinimum($pattern, $position) !== null) {
                return null;
            }

            $position++;

            return $opaque;
        }

        $position++;

        if (str_contains('.^$}]', $character)) {
            return $opaque;
        }

        if (ord($character) >= 0x80) {
            while ($position < $length && (ord($pattern[$position]) & 0xC0) === 0x80) {
                $character .= $pattern[$position];
                $position++;
            }
        }

        return ['literal' => $character, 'segments' => []];
    }

    /**
     * @return array{literal: null, segments: list<string>}|null
     */
    private function readGroup(string $pattern, int &$position): ?array
    {
        $rest = substr($pattern, $position + 1);
        $contributes = true;

        if (!str_starts_with($rest, '?')) {
            if (str_starts_with($rest, '*')) {
                return null;
            }

            $opening = 1;
        } elseif (preg_match('/^\?(?:P?<[A-Za-z_][A-Za-z0-9_]*>|\'[A-Za-z_][A-Za-z0-9_]*\'|[:>])/', $rest, $matches) === 1) {
            $opening = 1 + strlen($matches[0]);
        } elseif (preg_match('/^\?(?:<?[=!]|\|)/', $rest, $matches) === 1) {
            $opening = 1 + strlen($matches[0]);
            $contributes = false;
        } else {
            return null;
        }

        $position += $opening;
        $segments = $this->requiredSegments($pattern, $position, true);

        if ($segments === null || $position >= strlen($pattern) || $pattern[$position] !== ')') {
            return null;
        }

        $position++;

        return ['literal' => null, 'segments' => $contributes ? $segments : []];
    }

    private function readQuantifier(string $pattern, int &$position): int|false|null
    {
        if ($position >= strlen($pattern)) {
            return null;
        }

        $character = $pattern[$position];

        if ($character === '*' || $character === '?') {
            $minimum = 0;
            $position++;
        } elseif ($character === '+') {
            $minimum = 1;
            $position++;
        } elseif ($character === '{') {
            $quantifier = $this->quantifierMinimum($pattern, $position);

            if ($quantifier === null) {
                return null;
            }

            [$minimum, $quantifierLength] = $quantifier;
            $position += $quantifierLength;
        } else {
            return null;
        }

        if ($position < strlen($pattern) && ($pattern[$position] === '?' || $pattern[$position] === '+')) {
            $position++;
        }

        if ($position < strlen($pattern) && str_contains('*+?', $pattern[$position])) {
            return false;
        }

        return $minimum;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function quantifierMinimum(string $pattern, int $position): ?array
    {
        if (preg_match('/\G\{\s*(\d*)\s*(,\s*\d*\s*)?\}/', $pattern, $matches, 0, $position) !== 1) {
            return null;
        }

        $minimum = $matches[1];
        $hasComma = isset($matches[2]);

        if ($minimum === '' && !$hasComma) {
            return null;
        }

        return [$minimum === '' ? 0 : (int) $minimum, strlen($matches[0])];
    }

    private function characterClassEnd(string $pattern, int $start): ?int
    {
        $length = strlen($pattern);
        $index = $start + 1;

        if ($index < $length && $pattern[$index] === '^') {
            $index++;
        }

        if ($index < $length && $pattern[$index] === ']') {
            $index++;
        }

        while ($index < $length) {
            $character = $pattern[$index];

            if ($character === '\\') {
                $index += 2;
                continue;
            }

            if ($character === '[' && preg_match('/\G\[([:.=])[^\]]*?\1\]/', $pattern, $matches, 0, $index) === 1) {
                $index += strlen($matches[0]);
                continue;
            }

            if ($character === ']') {
                return $index;
            }

            $index++;
        }

        return null;
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
