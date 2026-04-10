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

            /** @var array<string, mixed> $data */
            $data = (array) ($payload['data'] ?? []);
            $file = (string) (($data['path']['text'] ?? '') ?: ($data['path']['bytes'] ?? ''));
            $lineNumber = (int) ($data['line_number'] ?? 0);
            $content = rtrim((string) ($data['lines']['text'] ?? ''), "\r\n");
            /** @var list<array<string, mixed>> $submatches */
            $submatches = array_values(array_filter((array) ($data['submatches'] ?? []), 'is_array'));

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
                $matches[] = [
                    'file' => $file,
                    'line' => $lineNumber,
                    'column' => ((int) ($submatch['start'] ?? 0)) + 1,
                    'content' => $content,
                    'matched_text' => (string) (($submatch['match']['text'] ?? '') ?: ''),
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

            $matches[] = [
                'file' => (string) ($match['file'] ?? ''),
                'start_line' => ((int) (($match['range']['start']['line'] ?? 0))) + 1,
                'end_line' => ((int) (($match['range']['end']['line'] ?? 0))) + 1,
                'start_byte' => (int) ($match['range']['byteOffset']['start'] ?? 0),
                'end_byte' => (int) ($match['range']['byteOffset']['end'] ?? 0),
                'code' => (string) ($match['text'] ?? ''),
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
}
