<?php

declare(strict_types=1);

namespace Greph\Cli;

use Greph\Ast\AstMatch;
use Greph\Ast\AstSearchOptions;
use Greph\Index\AstCacheStore;
use Greph\Index\AstIndexStore;
use Greph\Index\IndexFreshnessInspector;
use Greph\Index\IndexLifecycle;
use Greph\Index\IndexLifecycleProfile;
use Greph\Index\TextIndexStore;
use Greph\Output\GrepFormatter;
use Greph\Greph;
use Greph\Support\Filesystem;
use Greph\Text\TextFileResult;
use Greph\Text\TextMatch;
use Greph\Text\TextSearchOptions;
use Greph\Walker\FileTypeFilter;

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
            'stats' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runTextStats($this->parseBuildArguments($arguments)),
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
            'stats' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runAstStats($mode, $this->parseBuildArguments($arguments)),
            'search' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runAstSearch($mode, $this->parseAstSearchArguments($arguments)),
            default => throw new \InvalidArgumentException(sprintf('Unknown %s subcommand: %s', $mode === 'index' ? 'ast-index' : 'ast-cache', $command)),
        };
    }

    /**
     * @param array{
     *   root: string,
     *   indexDirs: list<string>,
     *   lifecycle: string,
     *   maxChangedFiles: int,
     *   maxChangedBytes: int
     * } $arguments
     */
    private function runBuild(array $arguments, bool $refresh): int
    {
        if (count($arguments['indexDirs']) > 1) {
            throw new \InvalidArgumentException('Build and refresh accept at most one --index-dir.');
        }

        $indexDir = $arguments['indexDirs'][0] ?? null;
        $lifecycle = new IndexLifecycle(
            profile: IndexLifecycleProfile::from($arguments['lifecycle']),
            maxChangedFiles: $arguments['maxChangedFiles'],
            maxChangedBytes: $arguments['maxChangedBytes'],
        );
        $result = $refresh
            ? Greph::refreshTextIndex($arguments['root'], $indexDir, $lifecycle)
            : Greph::buildTextIndex($arguments['root'], $indexDir, $lifecycle);

        $verb = $refresh ? 'Refreshed' : 'Built';
        $this->writeOutput(sprintf(
            '%s index for %d files in %s (%d trigrams, %.2fms, +%d ~%d -%d =%d)' . PHP_EOL,
            $verb,
            $result->fileCount,
            $this->displayPath($result->indexPath),
            $result->trigramCount,
            $result->buildDurationMs,
            $result->addedFiles,
            $result->updatedFiles,
            $result->deletedFiles,
            $result->unchangedFiles,
        ));

        return 0;
    }

    /**
     * @param array{
     *   root: string,
     *   indexDirs: list<string>,
     *   lifecycle: string,
     *   maxChangedFiles: int,
     *   maxChangedBytes: int
     * } $arguments
     */
    private function runAstBuild(string $mode, array $arguments, bool $refresh): int
    {
        if (count($arguments['indexDirs']) > 1) {
            throw new \InvalidArgumentException('Build and refresh accept at most one --index-dir.');
        }

        $indexDir = $arguments['indexDirs'][0] ?? null;
        $lifecycle = new IndexLifecycle(
            profile: IndexLifecycleProfile::from($arguments['lifecycle']),
            maxChangedFiles: $arguments['maxChangedFiles'],
            maxChangedBytes: $arguments['maxChangedBytes'],
        );

        if ($mode === 'index') {
            $result = $refresh
                ? Greph::refreshAstIndex($arguments['root'], $indexDir, $lifecycle)
                : Greph::buildAstIndex($arguments['root'], $indexDir, $lifecycle);

            $this->writeOutput(sprintf(
                '%s AST index for %d files in %s (%d fact rows, %.2fms, +%d ~%d -%d =%d)' . PHP_EOL,
                $refresh ? 'Refreshed' : 'Built',
                $result->fileCount,
                $this->displayPath($result->indexPath),
                $result->factCount,
                $result->buildDurationMs,
                $result->addedFiles,
                $result->updatedFiles,
                $result->deletedFiles,
                $result->unchangedFiles,
            ));

            return 0;
        }

        $result = $refresh
            ? Greph::refreshAstCache($arguments['root'], $indexDir, $lifecycle)
            : Greph::buildAstCache($arguments['root'], $indexDir, $lifecycle);

        $this->writeOutput(sprintf(
            '%s AST cache for %d files in %s (%d cached trees, %.2fms, +%d ~%d -%d =%d)' . PHP_EOL,
            $refresh ? 'Refreshed' : 'Built',
            $result->fileCount,
            $this->displayPath($result->indexPath),
            $result->cachedTreeCount,
            $result->buildDurationMs,
            $result->addedFiles,
            $result->updatedFiles,
            $result->deletedFiles,
            $result->unchangedFiles,
        ));

        return 0;
    }

    /**
     * @param array{
     *   root: string,
     *   indexDirs: list<string>,
     *   lifecycle: string,
     *   maxChangedFiles: int,
     *   maxChangedBytes: int
     * } $arguments
     */
    private function runTextStats(array $arguments): int
    {
        $store = new TextIndexStore();
        $inspector = new IndexFreshnessInspector();
        $blocks = [];

        foreach ($this->resolveTextIndexPaths($store, $arguments['root'], $arguments['indexDirs']) as $indexPath) {
            $index = $store->load($indexPath, includePostings: true);
            $freshness = $inspector->inspectText($index);
            $blocks[] = $this->formatStatsBlock('Text index stats', [
                'Root' => $this->displayPath($index->rootPath),
                'Index' => $this->displayPath($index->indexPath),
                'Files' => (string) count($index->files),
                'Trigram postings' => (string) count($index->postings),
                'Word postings' => (string) count($index->wordPostings),
                'Disk size' => $this->formatBytes($this->directorySize($index->indexPath)),
                'Lifecycle' => $index->lifecycle->label(),
                'Stale' => $freshness->stale ? 'yes' : 'no',
                'Changes' => $freshness->summary(),
                'Last refresh' => $this->formatTimestamp($index->builtAt),
                'Last build time' => sprintf('%.2fms', $index->buildDurationMs),
            ]);
        }

        $this->writeOutput(implode(PHP_EOL, $blocks));

        return 0;
    }

    /**
     * @param array{
     *   root: string,
     *   indexDirs: list<string>,
     *   lifecycle: string,
     *   maxChangedFiles: int,
     *   maxChangedBytes: int
     * } $arguments
     */
    private function runAstStats(string $mode, array $arguments): int
    {
        $inspector = new IndexFreshnessInspector();

        if ($mode === 'index') {
            $store = new AstIndexStore();
            $blocks = [];

            foreach ($this->resolveAstIndexPaths($store, $arguments['root'], $arguments['indexDirs']) as $indexPath) {
                $index = $store->load($indexPath);
                $freshness = $inspector->inspectAstIndex($index);
                $blocks[] = $this->formatStatsBlock('AST index stats', [
                    'Root' => $this->displayPath($index->rootPath),
                    'Index' => $this->displayPath($index->indexPath),
                    'Files' => (string) count($index->files),
                    'Fact rows' => (string) count($index->facts),
                    'Disk size' => $this->formatBytes($this->directorySize($index->indexPath)),
                    'Lifecycle' => $index->lifecycle->label(),
                    'Stale' => $freshness->stale ? 'yes' : 'no',
                    'Changes' => $freshness->summary(),
                    'Last refresh' => $this->formatTimestamp($index->builtAt),
                    'Last build time' => sprintf('%.2fms', $index->buildDurationMs),
                ]);
            }

            $this->writeOutput(implode(PHP_EOL, $blocks));

            return 0;
        }

        $store = new AstCacheStore();
        $blocks = [];

        foreach ($this->resolveAstCachePaths($store, $arguments['root'], $arguments['indexDirs']) as $indexPath) {
            $cache = $store->load($indexPath);
            $cachedTreeCount = count(array_filter(
                $cache->facts,
                static fn (array $facts): bool => $facts['cached'],
            ));
            $freshness = $inspector->inspectAstCache($cache);
            $blocks[] = $this->formatStatsBlock('AST cache stats', [
                'Root' => $this->displayPath($cache->rootPath),
                'Index' => $this->displayPath($cache->indexPath),
                'Files' => (string) count($cache->files),
                'Fact rows' => (string) count($cache->facts),
                'Cached trees' => (string) $cachedTreeCount,
                'Disk size' => $this->formatBytes($this->directorySize($cache->indexPath)),
                'Lifecycle' => $cache->lifecycle->label(),
                'Stale' => $freshness->stale ? 'yes' : 'no',
                'Changes' => $freshness->summary(),
                'Last refresh' => $this->formatTimestamp($cache->builtAt),
                'Last build time' => sprintf('%.2fms', $cache->buildDurationMs),
            ]);
        }

        $this->writeOutput(implode(PHP_EOL, $blocks));

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
     *   indexDirs: list<string>,
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
            collectCaptures: $arguments['json'],
            respectIgnore: !$arguments['noIgnore'],
            includeHidden: $arguments['hidden'],
            fileTypeFilter: $fileTypeFilter,
            globPatterns: $arguments['glob'],
            showLineNumbers: $arguments['showLineNumbers'],
            showFileNames: $this->shouldDisplayFileNames($arguments),
        );

        $results = count($arguments['indexDirs']) > 1
            ? Greph::searchTextIndexedMany($arguments['pattern'], $arguments['paths'], $arguments['indexDirs'], $options)
            : Greph::searchTextIndexed(
                $arguments['pattern'],
                $arguments['paths'],
                $options,
                $arguments['indexDirs'][0] ?? null,
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
     *   indexDirs: list<string>,
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
                    ? (
                        count($arguments['indexDirs']) > 1
                            ? Greph::searchAstIndexedMany($arguments['pattern'], $arguments['paths'], $arguments['indexDirs'], $options)
                            : Greph::searchAstIndexed($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'][0] ?? null)
                    )
                    : (
                        count($arguments['indexDirs']) > 1
                            ? Greph::searchAstCachedMany($arguments['pattern'], $arguments['paths'], $arguments['indexDirs'], $options)
                            : Greph::searchAstCached($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'][0] ?? null)
                    );
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
                    $matches = Greph::searchAst($arguments['pattern'], $arguments['paths'], $options);
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
     * @return array{
     *   root: string,
     *   indexDirs: list<string>,
     *   lifecycle: string,
     *   maxChangedFiles: int,
     *   maxChangedBytes: int
     * }
     */
    private function parseBuildArguments(array $arguments): array
    {
        $parsed = [
            'root' => '.',
            'indexDirs' => [],
            'lifecycle' => IndexLifecycleProfile::ManualRefresh->value,
            'maxChangedFiles' => IndexLifecycle::DEFAULT_MAX_CHANGED_FILES,
            'maxChangedBytes' => IndexLifecycle::DEFAULT_MAX_CHANGED_BYTES,
        ];

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--index-dir') {
                $parsed['indexDirs'][] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--lifecycle') {
                $parsed['lifecycle'] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--auto-refresh-max-files') {
                $parsed['maxChangedFiles'] = max(0, (int) $this->shiftValue($arguments, $argument));
                continue;
            }

            if ($argument === '--auto-refresh-max-bytes') {
                $parsed['maxChangedBytes'] = max(0, (int) $this->shiftValue($arguments, $argument));
                continue;
            }

            if ($argument[0] === '-') {
                throw new \InvalidArgumentException(sprintf('Unknown argument: %s', $argument));
            }

            $parsed['root'] = $argument;
        }

        if (IndexLifecycleProfile::tryFrom($parsed['lifecycle']) === null) {
            throw new \InvalidArgumentException(sprintf('Unknown lifecycle: %s', $parsed['lifecycle']));
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
     *   indexDirs: list<string>,
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
            'indexDirs' => [],
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
                    $parsed['indexDirs'][] = $this->shiftValue($arguments, $argument);
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
     *   indexDirs: list<string>,
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
            'indexDirs' => [],
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
                    $parsed['indexDirs'][] = $this->shiftValue($arguments, $argument);
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
  greph-index build [path] [--index-dir DIR] [--lifecycle PROFILE] [--auto-refresh-max-files N] [--auto-refresh-max-bytes N]
  greph-index refresh [path] [--index-dir DIR] [--lifecycle PROFILE] [--auto-refresh-max-files N] [--auto-refresh-max-bytes N]
  greph-index stats [path] [--index-dir DIR...]
  greph-index search [options] pattern [path...]
  greph-index ast-index build [path] [--index-dir DIR] [--lifecycle PROFILE] [--auto-refresh-max-files N] [--auto-refresh-max-bytes N]
  greph-index ast-index refresh [path] [--index-dir DIR] [--lifecycle PROFILE] [--auto-refresh-max-files N] [--auto-refresh-max-bytes N]
  greph-index ast-index stats [path] [--index-dir DIR...]
  greph-index ast-index search [options] pattern [path...]
  greph-index ast-cache build [path] [--index-dir DIR] [--lifecycle PROFILE] [--auto-refresh-max-files N] [--auto-refresh-max-bytes N]
  greph-index ast-cache refresh [path] [--index-dir DIR] [--lifecycle PROFILE] [--auto-refresh-max-files N] [--auto-refresh-max-bytes N]
  greph-index ast-cache stats [path] [--index-dir DIR...]
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
  --no-ignore     Ignore .gitignore and .grephignore rules.
  --hidden        Include hidden files.
  --index-dir DIR Use a non-default index directory. Repeat for multi-index search.
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
  --no-ignore             Ignore .gitignore and .grephignore rules.
  --hidden                Include hidden files.
  --strict-parse          Fail on parse errors instead of skipping them.
  --fallback MODE         Missing-index behavior: fail|scan.
  --index-dir DIR         Use a non-default AST index/cache directory. Repeat for multi-index search.
  --lifecycle PROFILE     Build policy: static|manual-refresh|opportunistic-refresh|strict-stale-check.
  --auto-refresh-max-files N
                          Opportunistic refresh file-change threshold.
  --auto-refresh-max-bytes N
                          Opportunistic refresh byte threshold.
  --help                  Show this help.

TEXT;
    }

    private function resolveRootPath(string $path): string
    {
        return Filesystem::normalizePath(realpath($path) ?: $path);
    }

    private function resolveTextIndexPath(TextIndexStore $store, string $root, ?string $indexDir): string
    {
        if ($indexDir !== null && $indexDir !== '') {
            if (str_starts_with($indexDir, '/')) {
                return Filesystem::normalizePath($indexDir);
            }

            return Filesystem::normalizePath($this->resolveRootPath($root) . '/' . $indexDir);
        }

        return $store->locateFrom($root) ?? $store->defaultPath($this->resolveRootPath($root));
    }

    /**
     * @param list<string> $indexDirs
     * @return list<string>
     */
    private function resolveTextIndexPaths(TextIndexStore $store, string $root, array $indexDirs): array
    {
        if ($indexDirs === []) {
            return [$this->resolveTextIndexPath($store, $root, null)];
        }

        return array_values(array_unique(array_map(
            fn (string $indexDir): string => $this->resolveTextIndexPath($store, $root, $indexDir),
            $indexDirs,
        )));
    }

    private function resolveAstIndexPath(AstIndexStore $store, string $root, ?string $indexDir): string
    {
        if ($indexDir !== null && $indexDir !== '') {
            if (str_starts_with($indexDir, '/')) {
                return Filesystem::normalizePath($indexDir);
            }

            return Filesystem::normalizePath($this->resolveRootPath($root) . '/' . $indexDir);
        }

        return $store->locateFrom($root) ?? $store->defaultPath($this->resolveRootPath($root));
    }

    /**
     * @param list<string> $indexDirs
     * @return list<string>
     */
    private function resolveAstIndexPaths(AstIndexStore $store, string $root, array $indexDirs): array
    {
        if ($indexDirs === []) {
            return [$this->resolveAstIndexPath($store, $root, null)];
        }

        return array_values(array_unique(array_map(
            fn (string $indexDir): string => $this->resolveAstIndexPath($store, $root, $indexDir),
            $indexDirs,
        )));
    }

    private function resolveAstCachePath(AstCacheStore $store, string $root, ?string $indexDir): string
    {
        if ($indexDir !== null && $indexDir !== '') {
            if (str_starts_with($indexDir, '/')) {
                return Filesystem::normalizePath($indexDir);
            }

            return Filesystem::normalizePath($this->resolveRootPath($root) . '/' . $indexDir);
        }

        return $store->locateFrom($root) ?? $store->defaultPath($this->resolveRootPath($root));
    }

    /**
     * @param list<string> $indexDirs
     * @return list<string>
     */
    private function resolveAstCachePaths(AstCacheStore $store, string $root, array $indexDirs): array
    {
        if ($indexDirs === []) {
            return [$this->resolveAstCachePath($store, $root, null)];
        }

        return array_values(array_unique(array_map(
            fn (string $indexDir): string => $this->resolveAstCachePath($store, $root, $indexDir),
            $indexDirs,
        )));
    }

    /**
     * @param array<string, string> $fields
     */
    private function formatStatsBlock(string $title, array $fields): string
    {
        $lines = [$title];

        foreach ($fields as $label => $value) {
            $lines[] = sprintf('%s: %s', $label, $value);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function directorySize(string $path): int
    {
        if (is_file($path)) {
            $size = filesize($path);

            return is_int($size) ? $size : 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            $entrySize = $entry->getSize();
            $size += is_int($entrySize) ? $entrySize : 0;
        }

        return $size;
    }

    private function formatBytes(int $bytes): string
    {
        return sprintf('%d bytes', $bytes);
    }

    private function formatTimestamp(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
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
