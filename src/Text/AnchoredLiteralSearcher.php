<?php

declare(strict_types=1);

namespace Phgrep\Text;

final class AnchoredLiteralSearcher implements TextMatcher
{
    public const MODE_PREFIX = 'prefix';
    public const MODE_SUFFIX = 'suffix';
    public const MODE_FULL_LINE = 'full-line';

    private int $literalLength;

    public function __construct(
        private readonly string $literal,
        private readonly string $mode,
        private readonly bool $caseInsensitive = false,
    ) {
        $this->literalLength = strlen($this->literal);
    }

    public function mayMatchContents(string $contents): bool
    {
        if ($this->literal === '') {
            return true;
        }

        return $this->caseInsensitive
            ? stripos($contents, $this->literal) !== false
            : strpos($contents, $this->literal) !== false;
    }

    public function match(string $line): ?LineMatch
    {
        return match ($this->mode) {
            self::MODE_PREFIX => $this->matchPrefix($line),
            self::MODE_SUFFIX => $this->matchSuffix($line),
            self::MODE_FULL_LINE => $this->matchFullLine($line),
            default => null,
        };
    }

    private function matchPrefix(string $line): ?LineMatch
    {
        if ($this->literalLength > strlen($line)) {
            return null;
        }

        if ($this->caseInsensitive) {
            if (stripos($line, $this->literal) !== 0) {
                return null;
            }

            return new LineMatch(1, substr($line, 0, $this->literalLength));
        }

        if (!str_starts_with($line, $this->literal)) {
            return null;
        }

        return new LineMatch(1, $this->literal);
    }

    private function matchSuffix(string $line): ?LineMatch
    {
        if ($this->literalLength > strlen($line)) {
            return null;
        }

        $offset = strlen($line) - $this->literalLength;

        if ($this->caseInsensitive) {
            if (stripos($line, $this->literal, $offset) !== $offset) {
                return null;
            }

            return new LineMatch($offset + 1, substr($line, $offset, $this->literalLength));
        }

        if (!str_ends_with($line, $this->literal)) {
            return null;
        }

        return new LineMatch($offset + 1, $this->literal);
    }

    private function matchFullLine(string $line): ?LineMatch
    {
        if ($this->caseInsensitive) {
            if (strlen($line) !== $this->literalLength || strcasecmp($line, $this->literal) !== 0) {
                return null;
            }

            return new LineMatch(1, $line);
        }

        if ($line !== $this->literal) {
            return null;
        }

        return new LineMatch(1, $this->literal);
    }
}
