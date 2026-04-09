<?php

declare(strict_types=1);

namespace Phgrep\Text;

final class LiteralExtractor
{
    public function extract(string $pattern): ?string
    {
        $literals = $this->extractAll($pattern);

        return $literals[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function extractAll(string $pattern): array
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

            if ($character === '|') {
                return [];
            }

            if ($character === '?' || $character === '*') {
                $segment = substr($segment, 0, max(0, strlen($segment) - 1));
                $this->flushSegment($segments, $segment);
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
            static fn (string $left, string $right): int => [strlen($right), $left] <=> [strlen($left), $right]
        );

        return array_slice($segments, 0, 3);
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
}
