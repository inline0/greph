<?php

declare(strict_types=1);

namespace Phgrep\Text;

use Phgrep\Walker\FileList;

final class TextSearcher
{
    private BufferedReader $reader;

    private LiteralExtractor $literalExtractor;

    public function __construct(?BufferedReader $reader = null, ?LiteralExtractor $literalExtractor = null)
    {
        $this->reader = $reader ?? new BufferedReader();
        $this->literalExtractor = $literalExtractor ?? new LiteralExtractor();
    }

    /**
     * @return list<TextFileResult>
     */
    public function searchFiles(FileList $files, string $pattern, TextSearchOptions $options): array
    {
        $matcher = $this->createMatcher($pattern, $options);
        $results = [];

        foreach ($files as $file) {
            $results[] = $this->searchFile($file, $matcher, $options);
        }

        return $results;
    }

    /**
     * @param list<TextFileResult> $results
     * @param list<string>|null $fileOrder
     * @return list<TextFileResult>
     */
    public function sortResults(array $results, ?array $fileOrder = null): array
    {
        if ($fileOrder === null) {
            return $results;
        }

        $order = array_flip($fileOrder);

        usort(
            $results,
            static fn (TextFileResult $left, TextFileResult $right): int => [$order[$left->file] ?? PHP_INT_MAX, $left->file] <=> [$order[$right->file] ?? PHP_INT_MAX, $right->file]
        );

        return $results;
    }

    private function createMatcher(string $pattern, TextSearchOptions $options): TextMatcher
    {
        if ($options->fixedString) {
            return new LiteralSearcher($pattern, $options->caseInsensitive, $options->wholeWord);
        }

        $segments = $this->literalExtractor->extractSegments($pattern);
        $literalPrefilter = $this->selectSeedLiteral($segments);

        return new RegexSearcher(
            $pattern,
            $options->caseInsensitive,
            $options->wholeWord,
            $literalPrefilter,
            $this->selectLineLiteral($segments, $literalPrefilter),
        );
    }

    /**
     * @param list<string> $segments
     */
    private function selectSeedLiteral(array $segments): ?string
    {
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
     * @param list<string> $segments
     */
    private function selectLineLiteral(array $segments, ?string $seedLiteral): ?string
    {
        if ($seedLiteral === null) {
            return null;
        }

        for ($index = count($segments) - 1; $index >= 0; $index--) {
            if ($segments[$index] !== $seedLiteral) {
                return $segments[$index];
            }
        }

        return null;
    }

    private function searchFile(
        string $file,
        TextMatcher $matcher,
        TextSearchOptions $options,
    ): TextFileResult {
        if ($options->beforeContext === 0 && $options->afterContext === 0) {
            return $this->searchFileWithoutContext($file, $matcher, $options);
        }

        /** @var list<array{line: int, content: string}> $previousLines */
        $previousLines = [];
        /** @var list<array{line: int, content: string, column: int, matchedText: string, captures: array<int|string, string>, beforeContext: list<array{line: int, content: string}>, afterContext: list<array{line: int, content: string}>}> $matches */
        $matches = [];
        /** @var array<int, int> $pendingAfterContext */
        $pendingAfterContext = [];

        $foundCount = 0;
        $collectMatches = true;

        foreach ($this->reader->readLines($file) as $line) {
            foreach ($pendingAfterContext as $index => $remainingLines) {
                $matches[$index]['afterContext'][] = ['line' => $line->number, 'content' => $line->content];

                if ($remainingLines === 1) {
                    unset($pendingAfterContext[$index]);
                } else {
                    $pendingAfterContext[$index] = $remainingLines - 1;
                }
            }

            $lineMatch = $collectMatches ? $matcher->match($line->content) : null;
            $isSelected = $collectMatches && ($options->invertMatch ? $lineMatch === null : $lineMatch !== null);

            if ($isSelected) {
                $column = $lineMatch !== null ? $lineMatch->column : 1;
                $matchedText = $lineMatch !== null ? $lineMatch->matchedText : '';
                $captures = $lineMatch !== null ? $lineMatch->captures : [];

                $matches[] = [
                    'line' => $line->number,
                    'content' => $line->content,
                    'column' => $column,
                    'matchedText' => $matchedText,
                    'captures' => $captures,
                    'beforeContext' => $previousLines,
                    'afterContext' => [],
                ];

                /** @var int $matchIndex */
                $matchIndex = array_key_last($matches);

                if ($options->afterContext > 0) {
                    $pendingAfterContext[$matchIndex] = $options->afterContext;
                }

                $foundCount++;

                if ($options->maxCount !== null && $foundCount >= $options->maxCount) {
                    $collectMatches = false;

                    if ($pendingAfterContext === []) {
                        break;
                    }
                }
            }

            if ($options->beforeContext > 0) {
                $previousLines[] = ['line' => $line->number, 'content' => $line->content];

                if (count($previousLines) > $options->beforeContext) {
                    array_shift($previousLines);
                }
            }

            if (!$collectMatches && $pendingAfterContext === []) {
                break;
            }
        }

        $materializedMatches = [];

        foreach ($matches as $row) {
            /** @var array{
             *   line: int,
             *   content: string,
             *   column: int,
             *   matchedText: string,
             *   captures: array<int|string, string>,
             *   beforeContext: list<array{line: int, content: string}>,
             *   afterContext: list<array{line: int, content: string}>
             * } $row
             */
            $materializedMatches[] = new TextMatch(
                file: $file,
                line: $row['line'],
                column: $row['column'],
                content: $row['content'],
                matchedText: $row['matchedText'],
                captures: $row['captures'],
                beforeContext: $row['beforeContext'],
                afterContext: $row['afterContext'],
            );
        }

        return new TextFileResult($file, $materializedMatches, $foundCount);
    }

    private function searchFileWithoutContext(
        string $file,
        TextMatcher $matcher,
        TextSearchOptions $options,
    ): TextFileResult {
        if (!$this->shouldUseContentsFastPath($matcher, $options)) {
            return $this->searchFileWithStreamWithoutContext($file, $matcher, $options);
        }

        $contents = @file_get_contents($file);

        if ($contents === false) {
            return new TextFileResult($file, [], 0);
        }

        if (!$options->invertMatch && !$matcher->mayMatchContents($contents)) {
            return new TextFileResult($file, [], 0);
        }

        if ($matcher instanceof LiteralSearcher && !$options->invertMatch && $matcher->supportsOccurrenceScan()) {
            return $this->searchContentsByLiteral($file, $contents, $matcher, $options);
        }

        if ($matcher instanceof RegexSearcher && !$options->invertMatch && $matcher->supportsOccurrenceScan()) {
            return $this->searchContentsByRegexPrefilter($file, $contents, $matcher, $options);
        }

        return $this->searchContentsWithoutContext($file, $contents, $matcher, $options);
    }

    private function searchFileWithStreamWithoutContext(
        string $file,
        TextMatcher $matcher,
        TextSearchOptions $options,
    ): TextFileResult {
        $matches = [];
        $foundCount = 0;
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return new TextFileResult($file, $matches, $foundCount);
        }
        $lineNumber = 0;

        while (($rawLine = fgets($handle)) !== false) {
            $lineNumber++;
            $lineContent = rtrim($rawLine, "\r\n");
            $lineMatch = $matcher->match($lineContent);
            $isSelected = $options->invertMatch ? $lineMatch === null : $lineMatch !== null;

            if (!$isSelected) {
                continue;
            }

            $foundCount++;

            if ($options->countOnly || $options->filesWithMatches || $options->filesWithoutMatches) {
                if ($options->filesWithMatches || $options->filesWithoutMatches) {
                    break;
                }

                if ($options->maxCount !== null && $foundCount >= $options->maxCount) {
                    break;
                }

                continue;
            }

            $matches[] = new TextMatch(
                file: $file,
                line: $lineNumber,
                column: $lineMatch !== null ? $lineMatch->column : 1,
                content: $lineContent,
                matchedText: $lineMatch !== null ? $lineMatch->matchedText : '',
                captures: $lineMatch !== null ? $lineMatch->captures : [],
            );

            if ($options->maxCount !== null && $foundCount >= $options->maxCount) {
                break;
            }
        }

        fclose($handle);

        return new TextFileResult($file, $matches, $foundCount);
    }

    private function searchContentsWithoutContext(
        string $file,
        string $contents,
        TextMatcher $matcher,
        TextSearchOptions $options,
    ): TextFileResult {
        $matches = [];
        $foundCount = 0;
        $lineNumber = 0;
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $newlinePosition = strpos($contents, "\n", $offset);

            if ($newlinePosition === false) {
                $rawLine = substr($contents, $offset);
                $offset = $length;
            } else {
                $rawLine = substr($contents, $offset, $newlinePosition - $offset);
                $offset = $newlinePosition + 1;
            }

            $lineNumber++;
            $lineContent = rtrim($rawLine, "\r");
            $lineMatch = $matcher->match($lineContent);
            $isSelected = $options->invertMatch ? $lineMatch === null : $lineMatch !== null;

            if (!$isSelected) {
                continue;
            }

            $foundCount++;

            if ($options->countOnly || $options->filesWithMatches || $options->filesWithoutMatches) {
                if ($options->filesWithMatches || $options->filesWithoutMatches) {
                    break;
                }

                if ($options->maxCount !== null && $foundCount >= $options->maxCount) {
                    break;
                }

                continue;
            }

            $matches[] = new TextMatch(
                file: $file,
                line: $lineNumber,
                column: $lineMatch !== null ? $lineMatch->column : 1,
                content: $lineContent,
                matchedText: $lineMatch !== null ? $lineMatch->matchedText : '',
                captures: $lineMatch !== null ? $lineMatch->captures : [],
            );

            if ($options->maxCount !== null && $foundCount >= $options->maxCount) {
                break;
            }
        }

        return new TextFileResult($file, $matches, $foundCount);
    }

    private function shouldUseContentsFastPath(TextMatcher $matcher, TextSearchOptions $options): bool
    {
        if ($matcher instanceof RegexSearcher) {
            return true;
        }

        return $matcher instanceof LiteralSearcher
            || $options->caseInsensitive
            || $options->wholeWord
            || $options->filesWithoutMatches;
    }

    private function searchContentsByLiteral(
        string $file,
        string $contents,
        LiteralSearcher $matcher,
        TextSearchOptions $options,
    ): TextFileResult {
        $matches = [];
        $foundCount = 0;
        $contentsLength = strlen($contents);
        $lineStart = 0;
        $lineNumber = 1;
        $lineEnd = strpos($contents, "\n");
        $offset = 0;

        while (($position = $matcher->findInContents($contents, $offset)) !== false) {
            while ($lineEnd !== false && $position > $lineEnd) {
                $lineStart = $lineEnd + 1;
                $lineNumber++;
                $lineEnd = strpos($contents, "\n", $lineStart);
            }

            $lineStop = $lineEnd === false ? $contentsLength : $lineEnd;
            $lineContent = rtrim(substr($contents, $lineStart, $lineStop - $lineStart), "\r");
            $foundCount++;

            if (!$options->countOnly && !$options->filesWithMatches && !$options->filesWithoutMatches) {
                $matches[] = new TextMatch(
                    file: $file,
                    line: $lineNumber,
                    column: ($position - $lineStart) + 1,
                    content: $lineContent,
                    matchedText: $matcher->matchedTextAt($contents, $position),
                );
            }

            if (
                $options->filesWithMatches
                || $options->filesWithoutMatches
                || ($options->maxCount !== null && $foundCount >= $options->maxCount)
                || $lineEnd === false
            ) {
                break;
            }

            $lineStart = $lineEnd + 1;
            $lineNumber++;
            $offset = $lineStart;
            $lineEnd = strpos($contents, "\n", $lineStart);
        }

        return new TextFileResult($file, $matches, $foundCount);
    }

    private function searchContentsByRegexPrefilter(
        string $file,
        string $contents,
        RegexSearcher $matcher,
        TextSearchOptions $options,
    ): TextFileResult {
        $matches = [];
        $foundCount = 0;
        $contentsLength = strlen($contents);
        $lineStart = 0;
        $lineNumber = 1;
        $lineEnd = strpos($contents, "\n");
        $offset = 0;

        while (($position = $matcher->findPrefilterInContents($contents, $offset)) !== false) {
            while ($lineEnd !== false && $position > $lineEnd) {
                $lineStart = $lineEnd + 1;
                $lineNumber++;
                $lineEnd = strpos($contents, "\n", $lineStart);
            }

            $lineStop = $lineEnd === false ? $contentsLength : $lineEnd;
            $rawLine = substr($contents, $lineStart, $lineStop - $lineStart);
            $lineContent = str_ends_with($rawLine, "\r") ? substr($rawLine, 0, -1) : $rawLine;
            $lineMatch = $matcher->matchPrefilteredLine($lineContent);

            if ($lineMatch !== null) {
                $foundCount++;

                if (!$options->countOnly && !$options->filesWithMatches && !$options->filesWithoutMatches) {
                    $matches[] = new TextMatch(
                        file: $file,
                        line: $lineNumber,
                        column: $lineMatch->column,
                        content: $lineContent,
                        matchedText: $lineMatch->matchedText,
                        captures: $lineMatch->captures,
                    );
                }
            }

            if (
                $options->filesWithMatches
                || $options->filesWithoutMatches
                || ($options->maxCount !== null && $foundCount >= $options->maxCount)
                || $lineEnd === false
            ) {
                break;
            }

            $lineStart = $lineEnd + 1;
            $lineNumber++;
            $offset = $lineStart;
            $lineEnd = strpos($contents, "\n", $lineStart);
        }

        return new TextFileResult($file, $matches, $foundCount);
    }
}
