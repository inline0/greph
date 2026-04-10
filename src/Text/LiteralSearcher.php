<?php

declare(strict_types=1);

namespace Greph\Text;

final class LiteralSearcher implements TextMatcher
{
    private ?string $wholeWordRegex = null;

    private int $needleLength;

    public function __construct(
        private readonly string $needle,
        private readonly bool $caseInsensitive = false,
        private readonly bool $wholeWord = false,
    ) {
        $this->needleLength = strlen($this->needle);

        if ($this->wholeWord) {
            $pattern = preg_quote($this->needle, '#');
            $pattern = '(?<![\pL\pN_])' . $pattern . '(?![\pL\pN_])';
            $modifiers = $this->caseInsensitive ? 'iu' : 'u';
            $this->wholeWordRegex = '#' . $pattern . '#' . $modifiers;
        }
    }

    public function mayMatchContents(string $contents): bool
    {
        if ($this->needle === '') {
            return true;
        }

        return $this->findInContents($contents) !== false;
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

            return new LineMatch($position + 1, substr($line, $position, $this->needleLength));
        }

        if ($this->wholeWordRegex !== null) {
            $matches = [];
            $matched = @preg_match($this->wholeWordRegex, $line, $matches, PREG_OFFSET_CAPTURE);

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

    public function supportsOccurrenceScan(): bool
    {
        return !$this->wholeWord && $this->needle !== '';
    }

    public function findInContents(string $contents, int $offset = 0): int|false
    {
        return $this->caseInsensitive
            ? stripos($contents, $this->needle, $offset)
            : strpos($contents, $this->needle, $offset);
    }

    public function matchedTextAt(string $contents, int $offset): string
    {
        return substr($contents, $offset, $this->needleLength);
    }
}
