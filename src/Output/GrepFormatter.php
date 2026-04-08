<?php

declare(strict_types=1);

namespace Phgrep\Output;

use Phgrep\Text\TextFileResult;
use Phgrep\Text\TextSearchOptions;

final class GrepFormatter
{
    /**
     * @param list<TextFileResult> $results
     */
    public function format(array $results, TextSearchOptions $options): string
    {
        if ($options->countOnly) {
            return $this->formatCounts($results);
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
                    $lines[] = sprintf('%s-%d-%s', $match->file, $context['line'], $context['content']);
                }

                $lines[] = sprintf('%s:%d:%s', $match->file, $match->line, $match->content);

                foreach ($match->afterContext as $context) {
                    $lines[] = sprintf('%s-%d-%s', $match->file, $context['line'], $context['content']);
                }
            }
        }

        return $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param list<TextFileResult> $results
     */
    private function formatCounts(array $results): string
    {
        $lines = [];

        foreach ($results as $result) {
            $lines[] = sprintf('%s:%d', $result->file, $result->matchCount());
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
}
