<?php

declare(strict_types=1);

namespace Greph\Cli;

use Greph\Output\GrepFormatter;
use Greph\Greph;
use Greph\Support\Filesystem;
use Greph\Text\TextFileResult;
use Greph\Text\TextMatch;
use Greph\Text\TextSearchOptions;
use Greph\Walker\FileTypeFilter;
use Greph\Walker\WalkOptions;

final class RipgrepApplication
{
    private GrepFormatter $grepFormatter;

    /**
     * @var resource
     */
    private $output;

    /**
     * @var resource
     */
    private $errorOutput;

    /**
     * @param resource|null $output
     * @param resource|null $errorOutput
     */
    public function __construct(?GrepFormatter $grepFormatter = null, $output = null, $errorOutput = null)
    {
        $this->grepFormatter = $grepFormatter ?? new GrepFormatter();
        $this->output = $output ?? STDOUT;
        $this->errorOutput = $errorOutput ?? STDERR;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $arguments = array_slice($argv, 1);

        if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true) && $arguments === ['-h']) {
            $this->writeOutput($this->usage());

            return 0;
        }

        if (in_array('--files', $arguments, true)) {
            return $this->runFiles($arguments);
        }

        return $this->runSearch($arguments);
    }

    /**
     * @param list<string> $arguments
     */
    private function runSearch(array $arguments): int
    {
        $parsed = $this->parseSearchArguments($arguments);

        if ($parsed['pattern'] === null) {
            $this->writeError("Missing search pattern.\n");

            return 2;
        }

        $options = new TextSearchOptions(
            fixedString: $parsed['fixedString'],
            caseInsensitive: $parsed['caseInsensitive'],
            wholeWord: $parsed['wholeWord'],
            invertMatch: $parsed['invertMatch'],
            maxCount: $parsed['maxCount'],
            beforeContext: $parsed['context'] ?? $parsed['beforeContext'],
            afterContext: $parsed['context'] ?? $parsed['afterContext'],
            countOnly: $parsed['countOnly'],
            filesWithMatches: $parsed['filesWithMatches'],
            filesWithoutMatches: $parsed['filesWithoutMatches'],
            quiet: $parsed['quiet'],
            jsonOutput: $parsed['json'],
            collectCaptures: $parsed['json'],
            jobs: $parsed['jobs'],
            respectIgnore: !$parsed['noIgnore'],
            includeHidden: $parsed['hidden'],
            followSymlinks: $parsed['followSymlinks'],
            fileTypeFilter: $this->createFileTypeFilter($parsed['type'], $parsed['typeNot']),
            globPatterns: $parsed['glob'],
            showLineNumbers: $parsed['showLineNumbers'],
            showFileNames: $this->shouldDisplayFileNames($parsed),
        );

        $results = Greph::searchText($parsed['pattern'], $parsed['paths'], $options);
        $displayResults = $this->displayTextResults($results, $this->shouldPrefixCurrentDirectory($parsed['paths']));

        if ($parsed['quiet']) {
            // Quiet mode is exit-code-only by definition.
        } elseif ($parsed['json']) {
            $this->writeOutput($this->formatJsonEvents($results, $this->shouldPrefixCurrentDirectory($parsed['paths'])) . PHP_EOL);
        } elseif ($parsed['countOnly']) {
            $this->writeOutput($this->formatCounts($displayResults, $options));
        } elseif ($parsed['filesWithMatches']) {
            $this->writeOutput($this->formatFileList($displayResults, true));
        } elseif ($parsed['filesWithoutMatches']) {
            $this->writeOutput($this->formatFileList($displayResults, false));
        } else {
            $this->writeOutput($this->grepFormatter->format($displayResults, $options));
        }

        foreach ($results as $result) {
            if ($parsed['filesWithoutMatches']) {
                if (!$result->hasMatches()) {
                    return 0;
                }

                continue;
            }

            if ($result->hasMatches()) {
                return 0;
            }
        }

        return 1;
    }

    /**
     * @param list<string> $arguments
     */
    private function runFiles(array $arguments): int
    {
        $parsed = $this->parseFilesArguments($arguments);
        $filter = $this->createFileTypeFilter($parsed['type'], $parsed['typeNot']);
        $files = Greph::walk(
            $parsed['paths'],
            new WalkOptions(
                respectIgnore: !$parsed['noIgnore'],
                includeHidden: $parsed['hidden'],
                followSymlinks: $parsed['followSymlinks'],
                skipBinaryFiles: false,
                includeGitDirectory: false,
                fileTypeFilter: $filter,
                maxFileSizeBytes: PHP_INT_MAX,
                globPatterns: $parsed['glob'],
            ),
        );

        $lines = [];
        $prefixCurrentDirectory = $this->shouldPrefixCurrentDirectory($parsed['paths']);

        foreach ($files as $file) {
            $lines[] = $this->displayPath($file, $prefixCurrentDirectory);
        }

        sort($lines, SORT_STRING);

        if ($lines !== []) {
            $this->writeOutput(implode(PHP_EOL, $lines) . PHP_EOL);
        }

        return 0;
    }

    /**
     * @param list<string> $arguments
     * @return array{
     *   fixedString: bool,
     *   caseInsensitive: bool,
     *   wholeWord: bool,
     *   invertMatch: bool,
     *   countOnly: bool,
     *   filesWithMatches: bool,
     *   filesWithoutMatches: bool,
     *   quiet: bool,
     *   json: bool,
     *   noIgnore: bool,
     *   hidden: bool,
     *   followSymlinks: bool,
     *   glob: list<string>,
     *   showFileNames: ?bool,
     *   showLineNumbers: bool,
     *   jobs: int,
     *   maxCount: ?int,
     *   beforeContext: int,
     *   afterContext: int,
     *   context: ?int,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   pattern: ?string,
     *   paths: list<string>
     * }
     */
    private function parseSearchArguments(array $arguments): array
    {
        $parsed = [
            'fixedString' => false,
            'caseInsensitive' => false,
            'wholeWord' => false,
            'invertMatch' => false,
            'countOnly' => false,
            'filesWithMatches' => false,
            'filesWithoutMatches' => false,
            'quiet' => false,
            'json' => false,
            'noIgnore' => false,
            'hidden' => false,
            'followSymlinks' => false,
            'glob' => [],
            'showFileNames' => null,
            'showLineNumbers' => false,
            'jobs' => 1,
            'maxCount' => null,
            'beforeContext' => 0,
            'afterContext' => 0,
            'context' => null,
            'type' => [],
            'typeNot' => [],
            'pattern' => null,
            'paths' => [],
        ];

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--') {
                if ($parsed['pattern'] === null) {
                    $parsed['pattern'] = $this->shiftValue($arguments, $argument);
                }

                foreach ($arguments as $path) {
                    $parsed['paths'][] = $path;
                }

                break;
            }

            if ($argument === '-F' || $argument === '--fixed-strings') {
                $parsed['fixedString'] = true;
                continue;
            }

            if ($argument === '-i' || $argument === '--ignore-case') {
                $parsed['caseInsensitive'] = true;
                continue;
            }

            if ($argument === '-w' || $argument === '--word-regexp') {
                $parsed['wholeWord'] = true;
                continue;
            }

            if ($argument === '-v' || $argument === '--invert-match') {
                $parsed['invertMatch'] = true;
                continue;
            }

            if ($argument === '-c' || $argument === '--count') {
                $parsed['countOnly'] = true;
                continue;
            }

            if ($argument === '-l' || $argument === '--files-with-matches') {
                $parsed['filesWithMatches'] = true;
                continue;
            }

            if ($argument === '--files-without-match' || $argument === '--files-without-matches') {
                $parsed['filesWithoutMatches'] = true;
                continue;
            }

            if ($argument === '-q' || $argument === '--quiet') {
                $parsed['quiet'] = true;
                continue;
            }

            if ($argument === '-n' || $argument === '--line-number') {
                $parsed['showLineNumbers'] = true;
                continue;
            }

            if ($argument === '-I' || $argument === '--no-filename') {
                $parsed['showFileNames'] = false;
                continue;
            }

            if ($argument === '-H' || $argument === '--with-filename') {
                $parsed['showFileNames'] = true;
                continue;
            }

            if ($argument === '--json' || str_starts_with($argument, '--json=')) {
                $parsed['json'] = true;
                continue;
            }

            if ($argument === '-L' || $argument === '--follow') {
                $parsed['followSymlinks'] = true;
                continue;
            }

            if ($argument === '--no-follow') {
                $parsed['followSymlinks'] = false;
                continue;
            }

            if ($argument === '--no-ignore') {
                $parsed['noIgnore'] = true;
                continue;
            }

            if ($argument === '--hidden') {
                $parsed['hidden'] = true;
                continue;
            }

            if ($argument === '-e' || $argument === '--regexp') {
                $parsed['pattern'] ??= $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--glob') {
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

            if ($argument === '-j' || $argument === '--threads') {
                $parsed['jobs'] = $this->parsePositiveInt($this->shiftValue($arguments, $argument), $argument);
                continue;
            }

            if ($argument === '-m' || $argument === '--max-count') {
                $parsed['maxCount'] = $this->parsePositiveInt($this->shiftValue($arguments, $argument), $argument);
                continue;
            }

            if ($argument === '-A' || $argument === '--after-context') {
                $parsed['afterContext'] = $this->parseNonNegativeInt($this->shiftValue($arguments, $argument), $argument);
                continue;
            }

            if ($argument === '-B' || $argument === '--before-context') {
                $parsed['beforeContext'] = $this->parseNonNegativeInt($this->shiftValue($arguments, $argument), $argument);
                continue;
            }

            if ($argument === '-C' || $argument === '--context') {
                $parsed['context'] = $this->parseNonNegativeInt($this->shiftValue($arguments, $argument), $argument);
                continue;
            }

            if ($argument !== '' && $argument[0] === '-') {
                throw new \InvalidArgumentException(sprintf('Unsupported rg argument: %s', $argument));
            }

            if ($parsed['pattern'] === null) {
                $parsed['pattern'] = $argument;
                continue;
            }

            $parsed['paths'][] = $argument;
        }

        if ($parsed['paths'] === []) {
            $parsed['paths'] = ['.'];
        }

        return $parsed;
    }

    /**
     * @param list<string> $arguments
     * @return array{
     *   hidden: bool,
     *   noIgnore: bool,
     *   followSymlinks: bool,
     *   glob: list<string>,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   paths: list<string>
     * }
     */
    private function parseFilesArguments(array $arguments): array
    {
        $parsed = [
            'hidden' => false,
            'noIgnore' => false,
            'followSymlinks' => false,
            'glob' => [],
            'type' => [],
            'typeNot' => [],
            'paths' => [],
        ];

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--files') {
                continue;
            }

            if ($argument === '--hidden') {
                $parsed['hidden'] = true;
                continue;
            }

            if ($argument === '--no-ignore') {
                $parsed['noIgnore'] = true;
                continue;
            }

            if ($argument === '-L' || $argument === '--follow') {
                $parsed['followSymlinks'] = true;
                continue;
            }

            if ($argument === '--glob') {
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
                throw new \InvalidArgumentException(sprintf('Unsupported rg --files argument: %s', $argument));
            }

            $parsed['paths'][] = $argument;
        }

        if ($parsed['paths'] === []) {
            $parsed['paths'] = ['.'];
        }

        return $parsed;
    }

    /**
     * @param list<TextFileResult> $results
     */
    private function formatCounts(array $results, TextSearchOptions $options): string
    {
        $lines = [];

        foreach ($results as $result) {
            if (!$result->hasMatches()) {
                continue;
            }

            $lines[] = $options->showFileNames
                ? sprintf('%s:%d', $result->file, $result->matchCount())
                : (string) $result->matchCount();
        }

        return $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param list<TextFileResult> $results
     */
    private function formatFileList(array $results, bool $matching): string
    {
        $lines = [];

        foreach ($results as $result) {
            if ($matching && !$result->hasMatches()) {
                continue;
            }

            if (!$matching && $result->hasMatches()) {
                continue;
            }

            $lines[] = $result->file;
        }

        return $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param list<TextFileResult> $results
     */
    private function formatJsonEvents(array $results, bool $prefixCurrentDirectory): string
    {
        $events = [];
        $summary = [
            'bytes_printed' => 0,
            'bytes_searched' => 0,
            'matched_lines' => 0,
            'matches' => 0,
            'searches' => count($results),
            'searches_with_match' => 0,
        ];

        foreach ($results as $result) {
            $displayFile = $this->displayPath($result->file, $prefixCurrentDirectory);
            $fileBytes = @filesize($result->file);
            $stats = [
                'elapsed' => [
                    'secs' => 0,
                    'nanos' => 0,
                    'human' => '0.000000s',
                ],
                'searches' => 1,
                'searches_with_match' => $result->hasMatches() ? 1 : 0,
                'bytes_searched' => $fileBytes === false ? 0 : (int) $fileBytes,
                'bytes_printed' => 0,
                'matched_lines' => count($result->matches),
                'matches' => count($result->matches),
            ];

            $events[] = [
                'type' => 'begin',
                'data' => [
                    'path' => ['text' => $displayFile],
                ],
            ];

            foreach ($result->matches as $match) {
                $offset = $this->absoluteOffset($result->file, $match);
                $matchedText = $match->matchedText !== '' ? $match->matchedText : $match->content;
                $start = max(0, $match->column - 1);
                $end = $start + strlen($matchedText);
                $event = [
                    'type' => 'match',
                    'data' => [
                        'path' => ['text' => $displayFile],
                        'lines' => ['text' => $match->content . "\n"],
                        'line_number' => $match->line,
                        'absolute_offset' => $offset,
                        'submatches' => [[
                            'match' => ['text' => $matchedText],
                            'start' => $start,
                            'end' => $end,
                        ]],
                    ],
                ];
                $events[] = $event;
                $encoded = json_encode($event, JSON_UNESCAPED_SLASHES);
                $stats['bytes_printed'] += is_string($encoded) ? strlen($encoded) + 1 : 0;
            }

            $events[] = [
                'type' => 'end',
                'data' => [
                    'path' => ['text' => $displayFile],
                    'binary_offset' => null,
                    'stats' => $stats,
                ],
            ];

            $summary['bytes_printed'] += $stats['bytes_printed'];
            $summary['bytes_searched'] += $stats['bytes_searched'];
            $summary['matched_lines'] += $stats['matched_lines'];
            $summary['matches'] += $stats['matches'];
            $summary['searches_with_match'] += $stats['searches_with_match'];
        }

        $events[] = [
            'type' => 'summary',
            'data' => [
                'elapsed_total' => [
                    'human' => '0.000000s',
                    'nanos' => 0,
                    'secs' => 0,
                ],
                'stats' => array_merge(
                    $summary,
                    [
                        'elapsed' => [
                            'human' => '0.000000s',
                            'nanos' => 0,
                            'secs' => 0,
                        ],
                    ],
                ),
            ],
        ];

        return implode(
            PHP_EOL,
            array_map(
                static fn (array $event): string => (string) json_encode($event, JSON_UNESCAPED_SLASHES),
                $events,
            ),
        );
    }

    private function absoluteOffset(string $file, TextMatch $match): int
    {
        $contents = file_get_contents($file);

        if (!is_string($contents) || $contents === '') {
            return 0;
        }

        $offset = 0;
        $line = 1;
        $length = strlen($contents);

        while ($line < $match->line && $offset < $length) {
            $next = strpos($contents, "\n", $offset);

            if ($next === false) {
                break;
            }

            $offset = $next + 1;
            $line++;
        }

        return $offset + max(0, $match->column - 1);
    }

    /**
     * @param list<TextFileResult> $results
     * @return list<TextFileResult>
     */
    private function displayTextResults(array $results, bool $prefixCurrentDirectory): array
    {
        $displayResults = [];

        foreach ($results as $result) {
            $displayFile = $this->displayPath($result->file, $prefixCurrentDirectory);
            $displayMatches = [];

            foreach ($result->matches as $match) {
                $displayMatches[] = new TextMatch(
                    file: $displayFile,
                    line: $match->line,
                    column: $match->column,
                    content: $match->content,
                    matchedText: $match->matchedText,
                    captures: $match->captures,
                    beforeContext: $match->beforeContext,
                    afterContext: $match->afterContext,
                );
            }

            $displayResults[] = new TextFileResult($displayFile, $displayMatches, $result->matchCount());
        }

        return $displayResults;
    }

    /**
     * @param array{
     *   showFileNames: ?bool,
     *   paths: list<string>
     * } $arguments
     */
    private function shouldDisplayFileNames(array $arguments): bool
    {
        if ($arguments['showFileNames'] !== null) {
            return $arguments['showFileNames'];
        }

        if (count($arguments['paths']) > 1) {
            return true;
        }

        foreach ($arguments['paths'] as $path) {
            if (is_dir($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $paths
     */
    private function shouldPrefixCurrentDirectory(array $paths): bool
    {
        foreach ($paths as $path) {
            if ($path === '.' || $path === './') {
                return true;
            }
        }

        return false;
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

    private function parseNonNegativeInt(string $value, string $flag): int
    {
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('Expected a non-negative integer for %s.', $flag));
        }

        return (int) $value;
    }

    private function usage(): string
    {
        return <<<TEXT
Usage:
  rg [options] pattern [path...]
  rg --files [options] [path...]

Supported Options:
  -F, --fixed-strings          Fixed-string search.
  -i, --ignore-case            Case-insensitive search.
  -w, --word-regexp            Whole-word search.
  -v, --invert-match           Invert matches.
  -c, --count                  Count matching lines.
  -l, --files-with-matches     List matching files.
  --files-without-match        List non-matching files.
  -q, --quiet                  Exit immediately after the first selected match.
  -I, --no-filename            Suppress filename prefixes.
  -H, --with-filename          Always print filename prefixes.
  -n, --line-number            Show line numbers.
  -A N, --after-context N      Show N lines after each match.
  -B N, --before-context N     Show N lines before each match.
  -C N, --context N            Show N lines before and after each match.
  -m N, --max-count N          Stop after N matches per file.
  -j N, --threads N            Use N workers.
  -e P, --regexp P             Search pattern.
  -L, --follow                 Follow symlinks.
  --glob GLOB                  Include only files whose paths match GLOB.
  --type NAME                  Include a file type.
  --type-not NAME              Exclude a file type.
  --json                       Emit ripgrep-style JSON events.
  --no-ignore                  Ignore .gitignore and .grephignore rules.
  --hidden                     Include hidden files.
  --files                      List candidate files instead of searching.
  --help                       Show this help.

TEXT;
    }

    private function displayPath(string $path, bool $prefixCurrentDirectory = false): string
    {
        $relative = Filesystem::relativePath(getcwd() ?: '.', $path);

        if (
            $prefixCurrentDirectory
            && $relative !== '.'
            && !str_starts_with($relative, './')
            && !str_starts_with($relative, '../')
            && !str_starts_with($relative, '/')
        ) {
            return './' . $relative;
        }

        return $relative;
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
