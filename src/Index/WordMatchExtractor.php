<?php

declare(strict_types=1);

namespace Phgrep\Index;

final class WordMatchExtractor
{
    /**
     * @return array{
     *   words: array<string, list<int>>,
     *   lineOffsets: list<int>
     * }
     */
    public function extract(string $contents): array
    {
        $words = [];
        $lineOffsets = [0];
        $offset = 0;
        $lineNumber = 1;
        $length = strlen($contents);

        while (true) {
            $newlinePosition = strpos($contents, "\n", $offset);

            if ($newlinePosition === false) {
                $rawLine = substr($contents, $offset);
            } else {
                $rawLine = substr($contents, $offset, $newlinePosition - $offset);
            }

            $line = rtrim($rawLine, "\r");
            $lineWords = $this->extractLineWords($line);

            foreach ($lineWords as $word) {
                $words[$word] ??= [];
                $words[$word][] = $lineNumber;
            }

            if ($newlinePosition === false) {
                break;
            }

            $offset = $newlinePosition + 1;
            $lineOffsets[] = $offset;
            $lineNumber++;
        }

        if ($lineOffsets[array_key_last($lineOffsets)] !== $length) {
            $lineOffsets[] = $length;
        }

        return [
            'words' => $words,
            'lineOffsets' => $lineOffsets,
        ];
    }

    /**
     * @return list<string>
     */
    private function extractLineWords(string $line): array
    {
        $matches = [];
        $matched = @preg_match_all('/[\p{L}\p{N}_]+/u', $line, $matches);

        if ($matched === false) {
            $matched = preg_match_all('/[A-Za-z0-9_]+/', $line, $matches);
        }

        if ($matched === false) {
            return [];
        }

        $words = [];
        $seen = [];

        foreach ($matches[0] as $token) {
            $word = strtolower($token);

            if (isset($seen[$word])) {
                continue;
            }

            $seen[$word] = true;
            $words[] = $word;
        }

        return $words;
    }
}
