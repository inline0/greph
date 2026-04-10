<?php

declare(strict_types=1);

namespace Phgrep\Cli;

use Phgrep\Ast\AstMatch;
use Phgrep\Ast\AstSearchOptions;
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
     * @var array<string, list<string>>
     */
    private array $lineCache = [];

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
            'ast-index' => $this->runAstCommand('index', $arguments),
            'ast-cache' => $this->runAstCommand('cache', $arguments),
            default => throw new \InvalidArgumentException(sprintf('Unknown subcommand: %s', $command)),
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function runAstCommand(string $mode, array $arguments): int
    {
        $command = array_shift($arguments);

        return match ($command) {
            null, 'help', '--help' => $this->runHelp(),
            'build' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runAstBuild($mode, $this->parseBuildArguments($arguments), false),
            'refresh' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runAstBuild($mode, $this->parseBuildArguments($arguments), true),
            'search' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runAstSearch($mode, $this->parseAstSearchArguments($arguments)),
            default => throw new \InvalidArgumentException(sprintf('Unknown %s subcommand: %s', $mode === 'index' ? 'ast-index' : 'ast-cache', $command)),
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
     * @param array{root: string, indexDir: ?string} $arguments
     */
    private function runAstBuild(string $mode, array $arguments, bool $refresh): int
    {
        if ($mode === 'index') {
            $result = $refresh
                ? Phgrep::refreshAstIndex($arguments['root'], $arguments['indexDir'])
                : Phgrep::buildAstIndex($arguments['root'], $arguments['indexDir']);

            $this->writeOutput(sprintf(
                '%s AST index for %d files in %s (%d fact rows, +%d ~%d -%d =%d)' . PHP_EOL,
                $refresh ? 'Refreshed' : 'Built',
                $result->fileCount,
                $this->displayPath($result->indexPath),
                $result->factCount,
                $result->addedFiles,
                $result->updatedFiles,
                $result->deletedFiles,
                $result->unchangedFiles,
            ));

            return 0;
        }

        $result = $refresh
            ? Phgrep::refreshAstCache($arguments['root'], $arguments['indexDir'])
            : Phgrep::buildAstCache($arguments['root'], $arguments['indexDir']);

        $this->writeOutput(sprintf(
            '%s AST cache for %d files in %s (%d cached trees, +%d ~%d -%d =%d)' . PHP_EOL,
            $refresh ? 'Refreshed' : 'Built',
            $result->fileCount,
            $this->displayPath($result->indexPath),
            $result->cachedTreeCount,
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

    /**
     * @param array{
     *   json: bool,
     *   noIgnore: bool,
     *   hidden: bool,
     *   strictParse: bool,
     *   filesWithMatches: bool,
     *   glob: list<string>,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   indexDir: ?string,
     *   lang: string,
     *   jobs: int,
     *   fallback: string,
     *   pattern: ?string,
     *   paths: list<string>
     * } $arguments
     */
    private function runAstSearch(string $mode, array $arguments): int
    {
        if ($arguments['pattern'] === null) {
            $this->writeError("Missing AST pattern.\n");

            return 2;
        }

        $fileTypeFilter = $this->createFileTypeFilter($arguments['type'], $arguments['typeNot']) ?? new FileTypeFilter(['php']);
        $options = new AstSearchOptions(
            language: $arguments['lang'],
            jobs: $arguments['jobs'],
            respectIgnore: !$arguments['noIgnore'],
            includeHidden: $arguments['hidden'],
            fileTypeFilter: $fileTypeFilter,
            globPatterns: $arguments['glob'],
            skipParseErrors: !$arguments['strictParse'],
            jsonOutput: $arguments['json'],
        );

        try {
            try {
                $matches = $mode === 'index'
                    ? Phgrep::searchAstIndexed($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDir'])
                    : Phgrep::searchAstCached($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDir']);
            } catch (\RuntimeException $exception) {
                if (
                    $arguments['fallback'] === 'scan'
                    && (
                        $exception->getMessage() === 'No AST index found for the requested paths. Build one first.'
                        || $exception->getMessage() === 'No AST cache found for the requested paths. Build one first.'
                        || str_starts_with($exception->getMessage(), 'AST index does not exist: ')
                        || str_starts_with($exception->getMessage(), 'AST cache does not exist: ')
                    )
                ) {
                    $matches = Phgrep::searchAst($arguments['pattern'], $arguments['paths'], $options);
                } else {
                    throw $exception;
                }
            }
        } catch (\RuntimeException $exception) {
            $this->writeError($exception->getMessage() . PHP_EOL);

            return 2;
        }

        if ($arguments['filesWithMatches']) {
            return $this->writeAstMatchFiles($matches);
        }

        if ($arguments['json']) {
            $this->writeOutput($this->formatAstJsonMatches($matches));
        } else {
            foreach ($matches as $match) {
                $this->writeOutput(sprintf(
                    '%s:%d:%s',
                    $this->displayPath($match->file),
                    $match->startLine,
                    $this->displayAstLine($match),
                ) . PHP_EOL);
            }
        }

        return $matches === [] ? 1 : 0;
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
     * @return array{
     *   json: bool,
     *   noIgnore: bool,
     *   hidden: bool,
     *   strictParse: bool,
     *   filesWithMatches: bool,
     *   glob: list<string>,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   indexDir: ?string,
     *   lang: string,
     *   jobs: int,
     *   fallback: string,
     *   pattern: ?string,
     *   paths: list<string>
     * }
     */
    private function parseAstSearchArguments(array $arguments): array
    {
        $parsed = [
            'json' => false,
            'noIgnore' => false,
            'hidden' => false,
            'strictParse' => false,
            'filesWithMatches' => false,
            'glob' => [],
            'type' => [],
            'typeNot' => [],
            'indexDir' => null,
            'lang' => 'php',
            'jobs' => 1,
            'fallback' => 'fail',
            'pattern' => null,
            'paths' => [],
        ];

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--') {
                break;
            }

            if ($argument === '' || $argument[0] !== '-') {
                if ($parsed['pattern'] === null) {
                    $parsed['pattern'] = $argument;
                } else {
                    $parsed['paths'][] = $argument;
                }

                continue;
            }

            switch ($argument) {
                case '--json':
                    $parsed['json'] = true;
                    break;
                case '--no-ignore':
                    $parsed['noIgnore'] = true;
                    break;
                case '--hidden':
                    $parsed['hidden'] = true;
                    break;
                case '--strict-parse':
                    $parsed['strictParse'] = true;
                    break;
                case '-l':
                case '--files-with-matches':
                    $parsed['filesWithMatches'] = true;
                    break;
                case '--glob':
                    $parsed['glob'][] = $this->shiftValue($arguments, $argument);
                    break;
                case '--type':
                    $parsed['type'][] = $this->shiftValue($arguments, $argument);
                    break;
                case '--type-not':
                    $parsed['typeNot'][] = $this->shiftValue($arguments, $argument);
                    break;
                case '--index-dir':
                    $parsed['indexDir'] = $this->shiftValue($arguments, $argument);
                    break;
                case '--lang':
                    $parsed['lang'] = $this->shiftValue($arguments, $argument);
                    break;
                case '-j':
                case '--jobs':
                    $parsed['jobs'] = max(1, (int) $this->shiftValue($arguments, $argument));
                    break;
                case '--fallback':
                    $parsed['fallback'] = $this->shiftValue($arguments, $argument);
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

        if (!in_array($parsed['fallback'], ['fail', 'scan'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown fallback mode: %s', $parsed['fallback']));
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

    /**
     * @param list<AstMatch> $matches
     */
    private function writeAstMatchFiles(array $matches): int
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
    private function formatAstJsonMatches(array $matches): string
    {
        $payload = array_map(
            fn (AstMatch $match): array => [
                'file' => $this->displayPath($match->file),
                'start_line' => $match->startLine,
                'end_line' => $match->endLine,
                'start_file_pos' => $match->startFilePos,
                'end_file_pos' => $match->endFilePos,
                'code' => $match->code,
            ],
            $matches,
        );

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    private function displayAstLine(AstMatch $match): string
    {
        $lines = $this->fileLines($match->file);
        $line = $lines[$match->startLine - 1] ?? null;

        if ($line !== null) {
            return rtrim($line, "\r\n");
        }

        return trim(preg_replace('/\s+/', ' ', $match->code) ?? $match->code);
    }

    /**
     * @return list<string>
     */
    private function fileLines(string $path): array
    {
        if (isset($this->lineCache[$path])) {
            return $this->lineCache[$path];
        }

        $contents = @file($path);

        if (!is_array($contents)) {
            $this->lineCache[$path] = [];

            return [];
        }

        $this->lineCache[$path] = $contents;

        return $this->lineCache[$path];
    }

    private function usage(): string
    {
        return <<<TEXT
Usage:
  greph-index build [path] [--index-dir DIR]
  greph-index refresh [path] [--index-dir DIR]
  greph-index search [options] pattern [path...]
  greph-index ast-index build [path] [--index-dir DIR]
  greph-index ast-index refresh [path] [--index-dir DIR]
  greph-index ast-index search [options] pattern [path...]
  greph-index ast-cache build [path] [--index-dir DIR]
  greph-index ast-cache refresh [path] [--index-dir DIR]
  greph-index ast-cache search [options] pattern [path...]

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

AST Search Options:
  --lang LANG             AST language. Default: php.
  -j N, --jobs N          Number of workers for AST scans.
  -l, --files-with-matches
                          List matching files.
  --glob GLOB             Include only files whose paths match GLOB.
  --type NAME             Include a file type.
  --type-not NAME         Exclude a file type.
  --json                  Emit JSON output.
  --no-ignore             Ignore .gitignore and .phgrepignore rules.
  --hidden                Include hidden files.
  --strict-parse          Fail on parse errors instead of skipping them.
  --fallback MODE         Missing-index behavior: fail|scan.
  --index-dir DIR         Use a non-default AST index/cache directory.
  --help                  Show this help.

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
