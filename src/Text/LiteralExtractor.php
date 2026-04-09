<?php

declare(strict_types=1);

namespace Phgrep\Text;

final class LiteralExtractor
{
    public function extract(string $pattern): ?string
    {
        $segments = $this->extractSegments($pattern);

        if ($segments === []) {
            return null;
        }

        usort(
            $segments,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left)
        );

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
}
