<?php

declare(strict_types=1);

namespace Phgrep\Text;

final class LiteralSearcher
{
    public function __construct(
        private readonly string $needle,
        private readonly bool $caseInsensitive = false,
        private readonly bool $wholeWord = false,
    ) {
    }

    public function match(string $line): ?LineMatch
    {
        if ($this->needle === '') {
            return new LineMatch(1, '');
        }

        if ($this->caseInsensitive && !$this->wholeWord) {
            $position = stripos($line, $this->needle);

            if ($position === false) {
                return null;
            }

            return new LineMatch($position + 1, substr($line, $position, strlen($this->needle)));
        }

        if ($this->wholeWord) {
            $pattern = preg_quote($this->needle, '#');
            $pattern = '(?<![\pL\pN_])' . $pattern . '(?![\pL\pN_])';
            $modifiers = $this->caseInsensitive ? 'iu' : 'u';
            $regex = '#' . $pattern . '#' . $modifiers;
            $matches = [];
            $matched = @preg_match($regex, $line, $matches, PREG_OFFSET_CAPTURE);

            if ($matched === false || $matched === 0) {
                return null;
            }

            /** @var array{0: string, 1: int<0, max>} $match */
            $match = $matches[0];

            return new LineMatch($match[1] + 1, $match[0]);
        }

        $position = strpos($line, $this->needle);

        if ($position === false) {
            return null;
        }

        return new LineMatch($position + 1, $this->needle);
    }
}
