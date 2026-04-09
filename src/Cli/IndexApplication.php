<?php

declare(strict_types=1);

namespace Phgrep\Cli;

use Phgrep\Output\GrepFormatter;
use Phgrep\Phgrep;
use Phgrep\Support\Filesystem;
use Phgrep\Text\TextFileResult;
use Phgrep\Text\TextMatch;
use Phgrep\Text\TextSearchOptions;
use Phgrep\Walker\FileTypeFilter;

final class IndexApplication
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
        $command = array_shift($arguments);

        return match ($command) {
            null, 'help', '--help' => $this->runHelp(),
            'build' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runBuild($this->parseBuildArguments($arguments), false),
            'refresh' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runBuild($this->parseBuildArguments($arguments), true),
            'search' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runSearch($this->parseSearchArguments($arguments)),
            default => throw new \InvalidArgumentException(sprintf('Unknown subcommand: %s', $command)),
        };
    }

    /**
     * @param array{root: string, indexDir: ?string} $arguments
     */
    private function runBuild(array $arguments, bool $refresh): int
    {
        $result = $refresh
            ? Phgrep::refreshTextIndex($arguments['root'], $arguments['indexDir'])
            : Phgrep::buildTextIndex($arguments['root'], $arguments['indexDir']);

        $verb = $refresh ? 'Refreshed' : 'Built';
        $this->writeOutput(sprintf(
            '%s index for %d files in %s (%d trigrams, +%d ~%d -%d =%d)' . PHP_EOL,
            $verb,
            $result->fileCount,
            $this->displayPath($result->indexPath),
            $result->trigramCount,
            $result->addedFiles,
            $result->updatedFiles,
            $result->deletedFiles,
            $result->unchangedFiles,
        ));

        return 0;
    }

    /**
     * @param array{
     *   fixedString: bool,
     *   caseInsensitive: bool,
     *   wholeWord: bool,
     *   invertMatch: bool,
     *   countOnly: bool,
     *   filesWithMatches: bool,
     *   filesWithoutMatches: bool,
     *   json: bool,
     *   noIgnore: bool,
     *   hidden: bool,
     *   glob: list<string>,
     *   showFileNames: ?bool,
     *   showLineNumbers: bool,
     *   maxCount: ?int,
     *   beforeContext: int,
     *   afterContext: int,
     *   context: ?int,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   indexDir: ?string,
     *   pattern: ?string,
     *   paths: list<string>
     * } $arguments
     */
    private function runSearch(array $arguments): int
    {
        if ($arguments['pattern'] === null) {
            $this->writeError("Missing search pattern.\n");

            return 2;
        }

        $fileTypeFilter = $this->createFileTypeFilter($arguments['type'], $arguments['typeNot']);
        $beforeContext = $arguments['context'] ?? $arguments['beforeContext'];
        $afterContext = $arguments['context'] ?? $arguments['afterContext'];
        $options = new TextSearchOptions(
            fixedString: $arguments['fixedString'],
            caseInsensitive: $arguments['caseInsensitive'],
            wholeWord: $arguments['wholeWord'],
            invertMatch: $arguments['invertMatch'],
            maxCount: $arguments['maxCount'],
            beforeContext: $beforeContext,
            afterContext: $afterContext,
            countOnly: $arguments['countOnly'],
            filesWithMatches: $arguments['filesWithMatches'],
            filesWithoutMatches: $arguments['filesWithoutMatches'],
            jsonOutput: $arguments['json'],
            respectIgnore: !$arguments['noIgnore'],
            includeHidden: $arguments['hidden'],
            fileTypeFilter: $fileTypeFilter,
            globPatterns: $arguments['glob'],
            showLineNumbers: $arguments['showLineNumbers'],
            showFileNames: $this->shouldDisplayFileNames($arguments),
        );

        $results = Phgrep::searchTextIndexed(
            $arguments['pattern'],
            $arguments['paths'],
            $options,
            $arguments['indexDir'],
        );
        $displayResults = $this->displayTextResults($results);

        if ($arguments['json']) {
            $payload = array_map(
                static fn ($result): array => [
                    'file' => $result->file,
                    'matches' => array_map(
                        static fn ($match): array => [
                            'line' => $match->line,
                            'column' => $match->column,
                            'content' => $match->content,
                            'matched_text' => $match->matchedText,
                            'captures' => $match->captures,
                        ],
                        $result->matches,
                    ),
                ],
                $displayResults,
            );

            $this->writeOutput(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } else {
            $this->writeOutput($this->grepFormatter->format($displayResults, $options));
        }

        foreach ($results as $result) {
            if ($arguments['filesWithoutMatches']) {
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

    private function runHelp(): int
    {
        $this->writeOutput($this->usage());

        return 0;
    }

    /**
     * @param list<string> $arguments
     * @return array{root: string, indexDir: ?string}
     */
    private function parseBuildArguments(array $arguments): array
    {
        $parsed = [
            'root' => '.',
            'indexDir' => null,
        ];

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--index-dir') {
                $parsed['indexDir'] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument[0] === '-') {
                throw new \InvalidArgumentException(sprintf('Unknown argument: %s', $argument));
            }

            $parsed['root'] = $argument;
        }

        return $parsed;
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
     *   json: bool,
     *   noIgnore: bool,
     *   hidden: bool,
     *   glob: list<string>,
     *   showFileNames: ?bool,
     *   showLineNumbers: bool,
     *   maxCount: ?int,
     *   beforeContext: int,
     *   afterContext: int,
     *   context: ?int,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   indexDir: ?string,
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
            'json' => false,
            'noIgnore' => false,
            'hidden' => false,
            'glob' => [],
            'showFileNames' => null,
            'showLineNumbers' => true,
            'maxCount' => null,
            'beforeContext' => 0,
            'afterContext' => 0,
            'context' => null,
            'type' => [],
            'typeNot' => [],
            'indexDir' => null,
            'pattern' => null,
            'paths' => [],
        ];

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--') {
                break;
            }

            if ($argument[0] !== '-') {
                if ($parsed['pattern'] === null) {
                    $parsed['pattern'] = $argument;
                } else {
                    $parsed['paths'][] = $argument;
                }

                continue;
            }

            switch ($argument) {
                case '-F':
                    $parsed['fixedString'] = true;
                    break;
                case '-i':
                    $parsed['caseInsensitive'] = true;
                    break;
                case '-w':
                    $parsed['wholeWord'] = true;
                    break;
                case '-v':
                    $parsed['invertMatch'] = true;
                    break;
                case '-c':
                    $parsed['countOnly'] = true;
                    break;
                case '-l':
                    $parsed['filesWithMatches'] = true;
                    break;
                case '-L':
                    $parsed['filesWithoutMatches'] = true;
                    break;
                case '--json':
                    $parsed['json'] = true;
                    break;
                case '--no-ignore':
                    $parsed['noIgnore'] = true;
                    break;
                case '--hidden':
                    $parsed['hidden'] = true;
                    break;
                case '--glob':
                    $parsed['glob'][] = $this->shiftValue($arguments, $argument);
                    break;
                case '--index-dir':
                    $parsed['indexDir'] = $this->shiftValue($arguments, $argument);
                    break;
                case '-h':
                    $parsed['showFileNames'] = false;
                    break;
                case '-H':
                    $parsed['showFileNames'] = true;
                    break;
                case '-n':
                    $parsed['showLineNumbers'] = true;
                    break;
                case '-m':
                    $parsed['maxCount'] = max(1, (int) $this->shiftValue($arguments, $argument));
                    break;
                case '-A':
                    $parsed['afterContext'] = max(0, (int) $this->shiftValue($arguments, $argument));
                    break;
                case '-B':
                    $parsed['beforeContext'] = max(0, (int) $this->shiftValue($arguments, $argument));
                    break;
                case '-C':
                    $parsed['context'] = max(0, (int) $this->shiftValue($arguments, $argument));
                    break;
                case '--type':
                    $parsed['type'][] = $this->shiftValue($arguments, $argument);
                    break;
                case '--type-not':
                    $parsed['typeNot'][] = $this->shiftValue($arguments, $argument);
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Unknown argument: %s', $argument));
            }
        }

        foreach ($arguments as $argument) {
            if ($parsed['pattern'] === null) {
                $parsed['pattern'] = $argument;
            } else {
                $parsed['paths'][] = $argument;
            }
        }

        if ($parsed['paths'] === []) {
            $parsed['paths'] = ['.'];
        }

        return $parsed;
    }

    /**
     * @param list<string> $arguments
     */
    private function shiftValue(array &$arguments, string $argument): string
    {
        $next = array_shift($arguments);

        if (!is_string($next)) {
            throw new \InvalidArgumentException(sprintf('Missing value for %s.', $argument));
        }

        return $next;
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
     * @param array{
     *   paths: list<string>,
     *   showFileNames: ?bool
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
     * @param list<TextFileResult> $results
     * @return list<TextFileResult>
     */
    private function displayTextResults(array $results): array
    {
        $displayResults = [];

        foreach ($results as $result) {
            $displayFile = $this->displayPath($result->file);
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

    private function displayPath(string $path): string
    {
        return Filesystem::relativePath(getcwd() ?: '.', $path);
    }

    private function usage(): string
    {
        return <<<TEXT
Usage:
  phgrep-index build [path] [--index-dir DIR]
  phgrep-index refresh [path] [--index-dir DIR]
  phgrep-index search [options] pattern [path...]

Search Options:
  -F              Fixed-string search.
  -i              Case-insensitive search.
  -w              Whole-word search.
  -v              Invert matches.
  -c              Count matches per file.
  -l              List matching files.
  -L              List non-matching files.
  -h              Suppress filename prefixes.
  -H              Always print filename prefixes.
  -n              Show line numbers. Default: on.
  -A N            Show N lines after each match.
  -B N            Show N lines before each match.
  -C N            Show N lines of context before and after each match.
  -m N            Stop after N matches per file.
  --glob GLOB     Include only files whose paths match GLOB.
  --type NAME     Include a file type.
  --type-not NAME Exclude a file type.
  --json          Emit JSON output.
  --no-ignore     Ignore .gitignore and .phgrepignore rules.
  --hidden        Include hidden files.
  --index-dir DIR Use a non-default index directory.
  --help          Show this help.

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
