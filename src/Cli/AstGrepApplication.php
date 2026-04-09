<?php

declare(strict_types=1);

namespace Phgrep\Cli;

use Phgrep\Ast\AstMatch;
use Phgrep\Ast\AstSearchOptions;
use Phgrep\Ast\RewriteResult;
use Phgrep\Phgrep;
use Phgrep\Support\Filesystem;
use Phgrep\Walker\FileTypeFilter;

final class AstGrepApplication
{
    /**
     * @var resource
     */
    private $input;

    /**
     * @var resource
     */
    private $output;

    /**
     * @var resource
     */
    private $errorOutput;

    /**
     * @var array<string, list<string>>
     */
    private array $lineCache = [];

    /**
     * @param resource|null $input
     * @param resource|null $output
     * @param resource|null $errorOutput
     */
    public function __construct($input = null, $output = null, $errorOutput = null)
    {
        $this->input = $input ?? STDIN;
        $this->output = $output ?? STDOUT;
        $this->errorOutput = $errorOutput ?? STDERR;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $arguments = array_slice($argv, 1);

        if ($arguments === []) {
            $this->writeError("Missing AST pattern.\n");

            return 2;
        }

        if (in_array('--help', $arguments, true) || $arguments === ['-h']) {
            $this->writeOutput($this->usage());

            return 0;
        }

        return $this->runCommand($this->parseArguments($arguments));
    }

    /**
     * @param array{
     *   command: string,
     *   pattern: ?string,
     *   rewrite: ?string,
     *   language: string,
     *   json: bool,
     *   jsonStyle: string,
     *   noIgnore: bool,
     *   hidden: bool,
     *   glob: list<string>,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   jobs: int,
     *   interactive: bool,
     *   updateAll: bool,
     *   filesWithMatches: bool,
     *   paths: list<string>
     * } $arguments
     */
    private function runCommand(array $arguments): int
    {
        if ($arguments['pattern'] === null) {
            $this->writeError("Missing AST pattern.\n");

            return 2;
        }

        $options = new AstSearchOptions(
            language: $arguments['language'],
            jobs: $arguments['jobs'],
            respectIgnore: !$arguments['noIgnore'],
            includeHidden: $arguments['hidden'],
            fileTypeFilter: $this->createFileTypeFilter($arguments['type'], $arguments['typeNot']) ?? new FileTypeFilter(['php']),
            globPatterns: $arguments['glob'],
            interactive: $arguments['interactive'],
            jsonOutput: $arguments['json'],
        );

        if ($arguments['rewrite'] !== null) {
            return $this->runRewrite($arguments, $options);
        }

        $matches = Phgrep::searchAst($arguments['pattern'], $arguments['paths'], $options);

        if ($arguments['filesWithMatches']) {
            return $this->writeMatchFiles($matches);
        }

        if ($arguments['json']) {
            $this->writeOutput($this->formatJsonMatches($matches, $arguments['jsonStyle']));
        } else {
            foreach ($matches as $match) {
                $this->writeOutput(sprintf(
                    '%s:%d:%s',
                    $this->displayPath($match->file),
                    $match->startLine,
                    $this->displayLine($match),
                ) . PHP_EOL);
            }
        }

        return $matches === [] ? 1 : 0;
    }

    /**
     * @param array{
     *   command: string,
     *   pattern: ?string,
     *   rewrite: ?string,
     *   language: string,
     *   json: bool,
     *   jsonStyle: string,
     *   noIgnore: bool,
     *   hidden: bool,
     *   glob: list<string>,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   jobs: int,
     *   interactive: bool,
     *   updateAll: bool,
     *   filesWithMatches: bool,
     *   paths: list<string>
     * } $arguments
     */
    private function runRewrite(array $arguments, AstSearchOptions $options): int
    {
        $results = Phgrep::rewriteAst(
            (string) $arguments['pattern'],
            (string) $arguments['rewrite'],
            $arguments['paths'],
            $options,
        );
        $changed = array_values(array_filter($results, static fn (RewriteResult $result): bool => $result->changed()));

        if ($changed === []) {
            return 1;
        }

        if ($arguments['interactive']) {
            foreach ($changed as $result) {
                $this->writeOutput(sprintf('Rewrite %s? [y/N] ', $result->file));
                $answer = trim((string) fgets($this->input));

                if (!in_array(strtolower($answer), ['y', 'yes'], true)) {
                    continue;
                }

                file_put_contents($result->file, $result->rewrittenContents);
                $this->writeOutput($this->displayPath($result->file) . PHP_EOL);
            }

            return 0;
        }

        if ($arguments['updateAll']) {
            foreach ($changed as $result) {
                file_put_contents($result->file, $result->rewrittenContents);
                $this->writeOutput($this->displayPath($result->file) . PHP_EOL);
            }

            return 0;
        }

        foreach ($changed as $result) {
            $this->writeOutput($this->renderDiffPreview($result));
        }

        return 0;
    }

    /**
     * @param list<string> $arguments
     * @return array{
     *   command: string,
     *   pattern: ?string,
     *   rewrite: ?string,
     *   language: string,
     *   json: bool,
     *   jsonStyle: string,
     *   noIgnore: bool,
     *   hidden: bool,
     *   glob: list<string>,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   jobs: int,
     *   interactive: bool,
     *   updateAll: bool,
     *   filesWithMatches: bool,
     *   paths: list<string>
     * }
     */
    private function parseArguments(array $arguments): array
    {
        $parsed = [
            'command' => 'run',
            'pattern' => null,
            'rewrite' => null,
            'language' => 'php',
            'json' => false,
            'jsonStyle' => 'pretty',
            'noIgnore' => false,
            'hidden' => false,
            'glob' => [],
            'type' => [],
            'typeNot' => [],
            'jobs' => 1,
            'interactive' => false,
            'updateAll' => false,
            'filesWithMatches' => false,
            'paths' => [],
        ];

        if ($arguments !== [] && in_array($arguments[0], ['run', 'scan', 'rewrite'], true)) {
            $parsed['command'] = (string) array_shift($arguments);
        }

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--') {
                foreach ($arguments as $value) {
                    $parsed['paths'][] = $value;
                }

                break;
            }

            if ($argument === '-p' || $argument === '--pattern') {
                $parsed['pattern'] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '-r' || $argument === '--rewrite') {
                $parsed['rewrite'] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '-l' || $argument === '--lang') {
                $parsed['language'] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--json') {
                $parsed['json'] = true;
                $parsed['jsonStyle'] = 'pretty';
                continue;
            }

            if (str_starts_with($argument, '--json=')) {
                $parsed['json'] = true;
                $parsed['jsonStyle'] = substr($argument, strlen('--json='));
                continue;
            }

            if ($argument === '-j' || $argument === '--threads') {
                $parsed['jobs'] = $this->parsePositiveInt($this->shiftValue($arguments, $argument), $argument);
                continue;
            }

            if ($argument === '-i' || $argument === '--interactive') {
                $parsed['interactive'] = true;
                continue;
            }

            if ($argument === '-U' || $argument === '--update-all') {
                $parsed['updateAll'] = true;
                continue;
            }

            if ($argument === '--dry-run') {
                continue;
            }

            if ($argument === '--files-with-matches') {
                $parsed['filesWithMatches'] = true;
                continue;
            }

            if ($argument === '--hidden') {
                $parsed['hidden'] = true;
                continue;
            }

            if ($argument === '--no-ignore') {
                $parsed['noIgnore'] = true;

                if ($arguments !== [] && is_string($arguments[0]) && $arguments[0] !== '' && $arguments[0][0] !== '-') {
                    $mode = (string) array_shift($arguments);

                    if ($mode === 'hidden') {
                        $parsed['hidden'] = true;
                    }
                }

                continue;
            }

            if ($argument === '--glob' || $argument === '--globs') {
                $parsed['glob'][] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--type') {
                $parsed['type'][] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--type-not') {
                $parsed['typeNot'][] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument !== '' && $argument[0] === '-') {
                throw new \InvalidArgumentException(sprintf('Unsupported sg argument: %s', $argument));
            }

            if ($parsed['pattern'] === null) {
                $parsed['pattern'] = $argument;
                continue;
            }

            $parsed['paths'][] = $argument;
        }

        if ($parsed['rewrite'] === '') {
            $parsed['rewrite'] = null;
        }

        if ($parsed['paths'] === []) {
            $parsed['paths'] = ['.'];
        }

        return $parsed;
    }

    /**
     * @param list<AstMatch> $matches
     */
    private function writeMatchFiles(array $matches): int
    {
        if ($matches === []) {
            return 1;
        }

        $files = [];

        foreach ($matches as $match) {
            $files[$this->displayPath($match->file)] = true;
        }

        $this->writeOutput(implode(PHP_EOL, array_keys($files)) . PHP_EOL);

        return 0;
    }

    /**
     * @param list<AstMatch> $matches
     */
    private function formatJsonMatches(array $matches, string $style): string
    {
        $payload = array_map(
            fn (AstMatch $match): array => [
                'file' => $this->displayPath($match->file),
                'range' => [
                    'start' => [
                        'line' => $match->startLine,
                        'column' => 1,
                    ],
                    'end' => [
                        'line' => $match->endLine,
                        'column' => 1,
                    ],
                ],
                'code' => $match->code,
            ],
            $matches,
        );

        return match ($style) {
            'compact' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            'stream' => implode(
                PHP_EOL,
                array_map(static fn (array $entry): string => (string) json_encode($entry, JSON_UNESCAPED_SLASHES), $payload),
            ) . ($payload === [] ? '' : PHP_EOL),
            default => (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        };
    }

    private function displayLine(AstMatch $match): string
    {
        $lines = $this->fileLines($match->file);
        $line = $lines[$match->startLine - 1] ?? null;

        if ($line !== null) {
            return rtrim($line, "\r\n");
        }

        return trim(preg_replace('/\s+/', ' ', $match->code) ?? $match->code);
    }

    private function renderDiffPreview(RewriteResult $result): string
    {
        $oldLines = $this->splitLines($result->originalContents);
        $newLines = $this->splitLines($result->rewrittenContents);
        $oldCount = count($oldLines);
        $newCount = count($newLines);
        $start = 0;

        while ($start < $oldCount && $start < $newCount && $oldLines[$start] === $newLines[$start]) {
            $start++;
        }

        $oldEnd = $oldCount - 1;
        $newEnd = $newCount - 1;

        while ($oldEnd >= $start && $newEnd >= $start && $oldLines[$oldEnd] === $newLines[$newEnd]) {
            $oldEnd--;
            $newEnd--;
        }

        $oldSlice = array_slice($oldLines, $start, max(0, $oldEnd - $start + 1));
        $newSlice = array_slice($newLines, $start, max(0, $newEnd - $start + 1));
        $lines = [
            $this->displayPath($result->file),
            sprintf(
                '@@ -%d,%d +%d,%d @@',
                $start + 1,
                count($oldSlice),
                $start + 1,
                count($newSlice),
            ),
        ];

        foreach ($oldSlice as $line) {
            $lines[] = '-' . $line;
        }

        foreach ($newSlice as $line) {
            $lines[] = '+' . $line;
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $contents): array
    {
        $trimmed = preg_replace("/\r\n?/", "\n", $contents) ?? $contents;
        $trimmed = rtrim($trimmed, "\n");

        if ($trimmed === '') {
            return [];
        }

        return explode("\n", $trimmed);
    }

    /**
     * @return list<string>
     */
    private function fileLines(string $path): array
    {
        if (!isset($this->lineCache[$path])) {
            $contents = file_get_contents($path);
            $this->lineCache[$path] = is_string($contents) ? preg_split("/\r\n|\n|\r/", $contents) ?: [] : [];
        }

        return $this->lineCache[$path];
    }

    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    private function createFileTypeFilter(array $include, array $exclude): ?FileTypeFilter
    {
        if ($include === [] && $exclude === []) {
            return null;
        }

        return new FileTypeFilter($include, $exclude);
    }

    /**
     * @param list<string> $arguments
     */
    private function shiftValue(array &$arguments, string $flag): string
    {
        $value = array_shift($arguments);

        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Missing value for %s.', $flag));
        }

        return $value;
    }

    private function parsePositiveInt(string $value, string $flag): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException(sprintf('Expected a positive integer for %s.', $flag));
        }

        return (int) $value;
    }

    private function displayPath(string $path): string
    {
        return Filesystem::relativePath(getcwd() ?: '.', $path);
    }

    private function usage(): string
    {
        return <<<TEXT
Usage:
  sg run --pattern PATTERN [options] [path...]
  sg scan -p PATTERN [options] [path...]
  sg rewrite -p PATTERN -r TEMPLATE [options] [path...]

Supported Options:
  -p, --pattern PATTERN      AST pattern.
  -r, --rewrite TEMPLATE     Rewrite template.
  -l, --lang NAME            AST language. Default: php.
  -j, --threads N            Use N workers.
  -i, --interactive          Confirm each rewrite.
  -U, --update-all           Apply rewrites without confirmation.
  --files-with-matches       Print only file paths with matches.
  --json[=STYLE]             Emit JSON output.
  --no-ignore [MODE]         Ignore repository ignore rules.
  --hidden                   Include hidden files.
  --glob GLOB                Include only files whose paths match GLOB.
  --globs GLOB               Alias for --glob.
  --type NAME                Include a file type.
  --type-not NAME            Exclude a file type.
  --dry-run                  Preview rewrites without writing files.
  --help                     Show this help.

TEXT;
    }

    private function writeOutput(string $contents): void
    {
        fwrite($this->output, $contents);
    }

    private function writeError(string $contents): void
    {
        fwrite($this->errorOutput, $contents);
    }
}
