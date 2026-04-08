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

    private function createMatcher(string $pattern, TextSearchOptions $options): LiteralSearcher|RegexSearcher
    {
        if ($options->fixedString) {
            return new LiteralSearcher($pattern, $options->caseInsensitive, $options->wholeWord);
        }

        return new RegexSearcher(
            $pattern,
            $options->caseInsensitive,
            $options->wholeWord,
            $this->literalExtractor->extract($pattern),
        );
    }

    private function searchFile(
        string $file,
        LiteralSearcher|RegexSearcher $matcher,
        TextSearchOptions $options,
    ): TextFileResult {
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

        return new TextFileResult($file, $materializedMatches);
    }
}
