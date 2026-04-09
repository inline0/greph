<?php

declare(strict_types=1);

namespace Phgrep\Cli;

use Phgrep\Phgrep;
use Phgrep\Support\Filesystem;
use Phgrep\Walker\FileTypeFilter;
use Phgrep\Walker\WalkOptions;

final class RipgrepApplication
{
    private Application $application;

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
    public function __construct(?Application $application = null, $output = null, $errorOutput = null)
    {
        $this->output = $output ?? STDOUT;
        $this->errorOutput = $errorOutput ?? STDERR;
        $this->application = $application ?? new Application(output: $this->output, errorOutput: $this->errorOutput);
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $arguments = array_slice($argv, 1);

        if (in_array('--help', $arguments, true)) {
            $this->writeOutput($this->usage());

            return 0;
        }

        if (in_array('--files', $arguments, true)) {
            return $this->runFiles($arguments);
        }

        return $this->application->run($this->translateSearchArguments($argv));
    }

    /**
     * @param list<string> $arguments
     */
    private function runFiles(array $arguments): int
    {
        $parsed = $this->parseFilesArguments($arguments);
        $filter = $this->createFileTypeFilter($parsed['type'], $parsed['typeNot']);
        $files = Phgrep::walk(
            $parsed['paths'],
            new WalkOptions(
                respectIgnore: !$parsed['noIgnore'],
                includeHidden: $parsed['hidden'],
                skipBinaryFiles: false,
                includeGitDirectory: false,
                fileTypeFilter: $filter,
                maxFileSizeBytes: PHP_INT_MAX,
                globPatterns: $parsed['glob'],
            ),
        );

        $lines = [];

        foreach ($files as $file) {
            $lines[] = $this->displayPath($file);
        }

        sort($lines, SORT_STRING);

        if ($lines !== []) {
            $this->writeOutput(implode(PHP_EOL, $lines) . PHP_EOL);
        }

        return 0;
    }

    /**
     * @param list<string> $argv
     * @return list<string>
     */
    private function translateSearchArguments(array $argv): array
    {
        $arguments = array_slice($argv, 1);
        $translated = [$argv[0] ?? 'rg'];
        $pattern = null;
        $paths = [];

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--') {
                if ($pattern === null) {
                    $pattern = array_shift($arguments);
                }

                foreach ($arguments as $path) {
                    $paths[] = $path;
                }

                break;
            }

            if ($argument === '-e' || $argument === '--regexp') {
                $pattern ??= $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--fixed-strings') {
                $translated[] = '-F';
                continue;
            }

            if ($argument === '--ignore-case') {
                $translated[] = '-i';
                continue;
            }

            if ($argument === '--word-regexp') {
                $translated[] = '-w';
                continue;
            }

            if ($argument === '--invert-match') {
                $translated[] = '-v';
                continue;
            }

            if ($argument === '--count') {
                $translated[] = '-c';
                continue;
            }

            if ($argument === '--files-with-matches') {
                $translated[] = '-l';
                continue;
            }

            if ($argument === '--files-without-match' || $argument === '--files-without-matches') {
                $translated[] = '-L';
                continue;
            }

            if ($argument === '--line-number') {
                $translated[] = '-n';
                continue;
            }

            if ($argument === '--no-filename') {
                $translated[] = '-h';
                continue;
            }

            if ($argument === '--with-filename') {
                $translated[] = '-H';
                continue;
            }

            if ($argument === '--max-count') {
                $translated[] = '-m';
                $translated[] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--after-context') {
                $translated[] = '-A';
                $translated[] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--before-context') {
                $translated[] = '-B';
                $translated[] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--context') {
                $translated[] = '-C';
                $translated[] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--threads') {
                $translated[] = '-j';
                $translated[] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--glob' || $argument === '--type' || $argument === '--type-not' || $argument === '--json' || $argument === '--no-ignore' || $argument === '--hidden' || $argument === '-F' || $argument === '-i' || $argument === '-w' || $argument === '-v' || $argument === '-c' || $argument === '-l' || $argument === '-L' || $argument === '-h' || $argument === '-H' || $argument === '-n' || $argument === '-m' || $argument === '-A' || $argument === '-B' || $argument === '-C' || $argument === '-j') {
                $translated[] = $argument;

                if (in_array($argument, ['--glob', '--type', '--type-not', '-m', '-A', '-B', '-C', '-j'], true)) {
                    $translated[] = $this->shiftValue($arguments, $argument);
                }

                continue;
            }

            if ($argument !== '' && $argument[0] === '-') {
                $translated[] = $argument;
                continue;
            }

            if ($pattern === null) {
                $pattern = $argument;
                continue;
            }

            $paths[] = $argument;
        }

        if ($pattern !== null) {
            $translated[] = $pattern;
        }

        foreach ($paths as $path) {
            $translated[] = $path;
        }

        return $translated;
    }

    /**
     * @param list<string> $arguments
     * @return array{
     *   hidden: bool,
     *   noIgnore: bool,
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

    private function usage(): string
    {
        return <<<TEXT
Usage:
  rg [options] pattern [path...]
  rg --files [options] [path...]

Supported Options:
  -F, --fixed-strings       Fixed-string search.
  -i, --ignore-case         Case-insensitive search.
  -w, --word-regexp         Whole-word search.
  -v, --invert-match        Invert matches.
  -c, --count               Count matches per file.
  -l, --files-with-matches  List matching files.
  -L, --files-without-match List non-matching files.
  -h, --no-filename         Suppress filename prefixes.
  -H, --with-filename       Always print filename prefixes.
  -n, --line-number         Show line numbers.
  -A N, --after-context N   Show N lines after each match.
  -B N, --before-context N  Show N lines before each match.
  -C N, --context N         Show N lines of context before and after each match.
  -m N, --max-count N       Stop after N matches per file.
  -j N, --threads N         Use N workers.
  -e P, --regexp P          Search pattern.
  --glob GLOB               Include only files whose paths match GLOB.
  --type NAME               Include a file type.
  --type-not NAME           Exclude a file type.
  --json                    Emit JSON output.
  --no-ignore               Ignore .gitignore and .phgrepignore rules.
  --hidden                  Include hidden files.
  --files                   List candidate files instead of searching.
  --help                    Show this help.

TEXT;
    }

    private function writeOutput(string $contents): void
    {
        fwrite($this->output, $contents);
    }

    private function displayPath(string $path): string
    {
        return Filesystem::relativePath(getcwd() ?: '.', $path);
    }
}
