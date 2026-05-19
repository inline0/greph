<?php

declare(strict_types=1);

namespace Greph\Tests\Oracle;

use Greph\Ast\AstMatch;
use Greph\Ast\RewriteResult;
use Greph\Support\Filesystem;
use Greph\Support\Json;
use Greph\Text\TextFileResult;
use Greph\Text\TextSearchOptions;

final class OutputNormalizer
{
    /**
     * @param list<TextFileResult> $results
     * @return array{text: string, json: list<array<string, mixed>>}
     */
    public function textOutputs(array $results, TextSearchOptions $options, string $workspaceRoot): array
    {
        $json = [];
        $lines = [];

        if ($options->countOnly) {
            foreach ($results as $result) {
                $lines[] = sprintf('%s:%d', $this->relativeFile($workspaceRoot, $result->file), $result->matchCount());
            }

            return ['text' => $this->linesToText($lines), 'json' => $json];
        }

        if ($options->filesWithMatches) {
            foreach ($results as $result) {
                if ($result->hasMatches()) {
                    $lines[] = $this->relativeFile($workspaceRoot, $result->file);
                }
            }

            return ['text' => $this->linesToText($lines), 'json' => $json];
        }

        if ($options->filesWithoutMatches) {
            foreach ($results as $result) {
                if (!$result->hasMatches()) {
                    $lines[] = $this->relativeFile($workspaceRoot, $result->file);
                }
            }

            return ['text' => $this->linesToText($lines), 'json' => $json];
        }

        foreach ($results as $result) {
            $relativeFile = $this->relativeFile($workspaceRoot, $result->file);

            foreach ($result->matches as $match) {
                foreach ($match->beforeContext as $context) {
                    $lines[] = sprintf('%s-%d-%s', $relativeFile, $context['line'], $context['content']);
                }

                $lines[] = sprintf('%s:%d:%s', $relativeFile, $match->line, $match->content);
                $json[] = [
                    'file' => $relativeFile,
                    'line' => $match->line,
                    'column' => $match->column,
                    'content' => $match->content,
                    'matched_text' => $match->matchedText,
                ];

                foreach ($match->afterContext as $context) {
                    $lines[] = sprintf('%s-%d-%s', $relativeFile, $context['line'], $context['content']);
                }
            }
        }

        return ['text' => $this->linesToText($lines), 'json' => $json];
    }

    /**
     * @param list<AstMatch> $matches
     * @return array{text: string, json: list<array<string, mixed>>}
     */
    public function astOutputs(array $matches, string $workspaceRoot): array
    {
        $lines = [];
        $json = [];

        foreach ($matches as $match) {
            $relativeFile = $this->relativeFile($workspaceRoot, $match->file);
            $collapsedCode = trim((string) preg_replace('/\s+/', ' ', $match->code));

            $lines[] = sprintf('%s:%d:%s', $relativeFile, $match->startLine, $collapsedCode);
            $json[] = [
                'file' => $relativeFile,
                'start_line' => $match->startLine,
                'end_line' => $match->endLine,
                'start_byte' => $match->startFilePos,
                'end_byte' => $match->endFilePos + 1,
                'code' => $match->code,
            ];
        }

        return ['text' => $this->linesToText($lines), 'json' => $json];
    }

    /**
     * @param list<RewriteResult> $results
     * @return array{text: string, json: list<array{file: string, content: string}>}
     */
    public function rewriteOutputs(array $results, string $workspaceRoot): array
    {
        $lines = [];
        $json = [];

        foreach ($results as $result) {
            if (!$result->changed()) {
                continue;
            }

            $relativeFile = $this->relativeFile($workspaceRoot, $result->file);
            $lines[] = $relativeFile;
            $json[] = ['file' => $relativeFile, 'content' => $result->rewrittenContents];
        }

        return ['text' => $this->linesToText($lines), 'json' => $json];
    }

    /**
     * @return list<array{file: string, line: int, column: int, content: string, matched_text: string}>
     */
    public function parseRipgrepJson(string $raw): array
    {
        $matches = [];

        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $payload = Json::decode($line);

            if (($payload['type'] ?? null) !== 'match') {
                continue;
            }

            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $pathField = is_array($data['path'] ?? null) ? $data['path'] : [];
            $linesField = is_array($data['lines'] ?? null) ? $data['lines'] : [];
            $file = self::asString($pathField['text'] ?? null) ?: self::asString($pathField['bytes'] ?? null);
            $lineNumber = self::asInt($data['line_number'] ?? null);
            $content = rtrim(self::asString($linesField['text'] ?? null), "\r\n");
            $submatches = array_values(array_filter(is_array($data['submatches'] ?? null) ? $data['submatches'] : [], 'is_array'));

            if ($submatches === []) {
                $matches[] = [
                    'file' => $file,
                    'line' => $lineNumber,
                    'column' => 1,
                    'content' => $content,
                    'matched_text' => '',
                ];

                continue;
            }

            foreach ($submatches as $submatch) {
                $matchField = is_array($submatch['match'] ?? null) ? $submatch['match'] : [];
                $matches[] = [
                    'file' => $file,
                    'line' => $lineNumber,
                    'column' => self::asInt($submatch['start'] ?? null) + 1,
                    'content' => $content,
                    'matched_text' => self::asString($matchField['text'] ?? null),
                ];
            }
        }

        usort($matches, static fn (array $left, array $right): int => [$left['file'], $left['line'], $left['column']] <=> [$right['file'], $right['line'], $right['column']]);

        return $matches;
    }

    /**
     * @return list<array{file: string, start_line: int, end_line: int, start_byte: int, end_byte: int, code: string}>
     */
    public function parseAstGrepJson(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = Json::decode($raw);
        $matches = [];

        foreach ($decoded as $match) {
            if (!is_array($match)) {
                continue;
            }

            $range = is_array($match['range'] ?? null) ? $match['range'] : [];
            $rangeStart = is_array($range['start'] ?? null) ? $range['start'] : [];
            $rangeEnd = is_array($range['end'] ?? null) ? $range['end'] : [];
            $byteOffset = is_array($range['byteOffset'] ?? null) ? $range['byteOffset'] : [];

            $matches[] = [
                'file' => self::asString($match['file'] ?? null),
                'start_line' => self::asInt($rangeStart['line'] ?? null) + 1,
                'end_line' => self::asInt($rangeEnd['line'] ?? null) + 1,
                'start_byte' => self::asInt($byteOffset['start'] ?? null),
                'end_byte' => self::asInt($byteOffset['end'] ?? null),
                'code' => self::asString($match['text'] ?? null),
            ];
        }

        usort($matches, static fn (array $left, array $right): int => [$left['file'], $left['start_byte']] <=> [$right['file'], $right['start_byte']]);

        return $matches;
    }

    /**
     * @return list<array{file: string, content: string}>
     */
    public function snapshotToRewriteJson(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $contents = file_get_contents($fileInfo->getPathname());

            if ($contents === false) {
                continue;
            }

            $files[] = [
                'file' => Filesystem::relativePath(dirname($directory), $fileInfo->getPathname()),
                'content' => $contents,
            ];
        }

        usort($files, static fn (array $left, array $right): int => strcmp($left['file'], $right['file']));

        return $files;
    }

    /**
     * @param list<array{file: string, content: string}> $before
     * @param list<array{file: string, content: string}> $after
     * @return list<array{file: string, content: string}>
     */
    public function diffRewriteSnapshots(array $before, array $after): array
    {
        $beforeMap = [];

        foreach ($before as $file) {
            $beforeMap[$file['file']] = $file['content'];
        }

        $changed = [];

        foreach ($after as $file) {
            if (($beforeMap[$file['file']] ?? null) !== $file['content']) {
                $changed[] = $file;
            }
        }

        usort($changed, static fn (array $left, array $right): int => strcmp($left['file'], $right['file']));

        return $changed;
    }

    public function normalizeTextOutput(string $output): string
    {
        $output = str_replace("\r\n", "\n", $output);

        return $output === '' || str_ends_with($output, "\n") ? $output : $output . "\n";
    }

    private function relativeFile(string $workspaceRoot, string $file): string
    {
        return Filesystem::relativePath($workspaceRoot, $file);
    }

    /**
     * @param list<string> $lines
     */
    private function linesToText(array $lines): string
    {
        return $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return (string) $value;
        }

        return '';
    }

    private static function asInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || is_string($value) || is_bool($value)) {
            return (int) $value;
        }

        return 0;
    }
}
