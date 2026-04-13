<?php

declare(strict_types=1);

namespace Greph\Text;

final class LiteralSearcher implements TextMatcher
{
    private ?string $wholeWordRegex = null;

    private int $needleLength;

    private bool $asciiWholeWordCandidate = false;

    public function __construct(
        private readonly string $needle,
        private readonly bool $caseInsensitive = false,
        private readonly bool $wholeWord = false,
    ) {
        $this->needleLength = strlen($this->needle);
        $this->asciiWholeWordCandidate = $this->wholeWord && $this->needle !== '' && preg_match('/^[\x00-\x7F]+$/', $this->needle) === 1;

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

        if ($this->supportsWholeWordOccurrenceScanForContents($contents)) {
            return $this->findWholeWordInContents($contents) !== false;
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
            if ($this->supportsWholeWordOccurrenceScanForContents($line)) {
                $position = $this->findWholeWordInAsciiContents($line);

                if ($position === false) {
                    return null;
                }

                return new LineMatch($position + 1, substr($line, $position, $this->needleLength));
            }

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

    public function supportsWholeWordOccurrenceScanForContents(string $contents): bool
    {
        return $this->asciiWholeWordCandidate && preg_match('/[\x80-\xFF]/', $contents) !== 1;
    }

    public function findInContents(string $contents, int $offset = 0): int|false
    {
        return $this->caseInsensitive
            ? stripos($contents, $this->needle, $offset)
            : strpos($contents, $this->needle, $offset);
    }

    public function findWholeWordInContents(string $contents, int $offset = 0): int|false
    {
        if (!$this->supportsWholeWordOccurrenceScanForContents($contents)) {
            return false;
        }

        return $this->findWholeWordInAsciiContents($contents, $offset);
    }

    public function findWholeWordInAsciiContents(string $contents, int $offset = 0): int|false
    {
        if (!$this->asciiWholeWordCandidate) {
            return false;
        }

        $position = $offset;

        while (($position = $this->findInContents($contents, $position)) !== false) {
            if ($this->isWholeWordBoundaryAt($contents, $position)) {
                return $position;
            }

            $position++;
        }

        return false;
    }

    public function matchedTextAt(string $contents, int $offset): string
    {
        return substr($contents, $offset, $this->needleLength);
    }

    private function isWholeWordBoundaryAt(string $contents, int $position): bool
    {
        $before = $position > 0 ? ord($contents[$position - 1]) : null;
        $afterOffset = $position + $this->needleLength;
        $after = $afterOffset < strlen($contents) ? ord($contents[$afterOffset]) : null;

        return !$this->isAsciiWordByte($before) && !$this->isAsciiWordByte($after);
    }

    private function isAsciiWordByte(?int $byte): bool
    {
        if ($byte === null) {
            return false;
        }

        return ($byte >= 48 && $byte <= 57)
            || ($byte >= 65 && $byte <= 90)
            || ($byte >= 97 && $byte <= 122)
            || $byte === 95;
    }
}
