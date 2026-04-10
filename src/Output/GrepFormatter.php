<?php

declare(strict_types=1);

namespace Greph\Output;

use Greph\Text\TextFileResult;
use Greph\Text\TextSearchOptions;

final class GrepFormatter
{
    /**
     * @param list<TextFileResult> $results
     */
    public function format(array $results, TextSearchOptions $options): string
    {
        if ($options->countOnly) {
            return $this->formatCounts($results, $options);
        }

        if ($options->filesWithMatches) {
            return $this->formatFileList(
                array_values(array_filter($results, static fn (TextFileResult $result): bool => $result->hasMatches()))
            );
        }

        if ($options->filesWithoutMatches) {
            return $this->formatFileList(
                array_values(array_filter($results, static fn (TextFileResult $result): bool => !$result->hasMatches()))
            );
        }

        $lines = [];

        foreach ($results as $result) {
            foreach ($result->matches as $match) {
                foreach ($match->beforeContext as $context) {
                    $lines[] = $this->formatLine($match->file, $context['line'], $context['content'], $options, false);
                }

                $lines[] = $this->formatLine($match->file, $match->line, $match->content, $options, true);

                foreach ($match->afterContext as $context) {
                    $lines[] = $this->formatLine($match->file, $context['line'], $context['content'], $options, false);
                }
            }
        }

        return $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param list<TextFileResult> $results
     */
    private function formatCounts(array $results, TextSearchOptions $options): string
    {
        $lines = [];

        foreach ($results as $result) {
            $lines[] = $options->showFileNames
                ? sprintf('%s:%d', $result->file, $result->matchCount())
                : (string) $result->matchCount();
        }

        return $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param list<TextFileResult> $results
     */
    private function formatFileList(array $results): string
    {
        $lines = array_map(static fn (TextFileResult $result): string => $result->file, $results);

        return $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function formatLine(
        string $file,
        int $line,
        string $content,
        TextSearchOptions $options,
        bool $isMatch,
    ): string {
        $separator = $isMatch ? ':' : '-';
        $prefix = [];

        if ($options->showFileNames) {
            $prefix[] = $file;
        }

        if ($options->showLineNumbers) {
            $prefix[] = (string) $line;
        }

        if ($prefix === []) {
            return $content;
        }

        return implode($separator, $prefix) . $separator . $content;
    }
}
