<?php

declare(strict_types=1);

namespace Phgrep\Text;

use Phgrep\Exceptions\PatternException;

final class RegexSearcher implements TextMatcher
{
    private string $regex;

    private ?string $fallbackRegex = null;

    public function __construct(
        string $pattern,
        private readonly bool $caseInsensitive = false,
        bool $wholeWord = false,
        private readonly ?string $literalPrefilter = null,
    ) {
        if ($wholeWord) {
            $pattern = '(?<![\pL\pN_])(?:' . $pattern . ')(?![\pL\pN_])';
        }

        $modifiers = $caseInsensitive ? 'iu' : 'u';
        $fallbackModifiers = $caseInsensitive ? 'i' : '';
        $this->regex = $this->wrapPattern($pattern, $modifiers);
        $this->fallbackRegex = $this->wrapPattern($pattern, $fallbackModifiers);

        if (@preg_match($this->regex, '') === false && @preg_match($this->fallbackRegex, '') === false) {
            throw new PatternException(sprintf('Invalid regex pattern: %s', $pattern));
        }
    }

    public function mayMatchContents(string $contents): bool
    {
        if ($this->literalPrefilter === null) {
            return true;
        }

        return ($this->caseInsensitive ? stripos($contents, $this->literalPrefilter) : strpos($contents, $this->literalPrefilter)) !== false;
    }

    public function match(string $line): ?LineMatch
    {
        if (
            $this->literalPrefilter !== null
            && (($this->caseInsensitive
                ? stripos($line, $this->literalPrefilter)
                : strpos($line, $this->literalPrefilter)) === false)
        ) {
            return null;
        }

        $matches = [];
        $matched = @preg_match($this->regex, $line, $matches, PREG_OFFSET_CAPTURE);

        if ($matched === false && $this->fallbackRegex !== null) {
            $matched = @preg_match($this->fallbackRegex, $line, $matches, PREG_OFFSET_CAPTURE);
        }

        if ($matched !== 1) {
            return null;
        }

        /** @var array{0: string, 1: int<0, max>} $firstMatch */
        $firstMatch = $matches[0];
        $captures = [];

        foreach ($matches as $key => $value) {
            /** @var array{0: string, 1: int<0, max>} $value */
            $captures[$key] = $value[0];
        }

        return new LineMatch($firstMatch[1] + 1, $firstMatch[0], $captures);
    }

    private function wrapPattern(string $pattern, string $modifiers): string
    {
        return '#' . str_replace('#', '\#', $pattern) . '#' . $modifiers;
    }
}
