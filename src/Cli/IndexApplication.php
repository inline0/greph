<?php

declare(strict_types=1);

namespace Greph\Cli;

use Greph\Ast\AstMatch;
use Greph\Ast\AstSearchOptions;
use Greph\Index\AstQueryCacheStore;
use Greph\Index\AstCacheStore;
use Greph\Index\AstIndexStore;
use Greph\Index\IndexFreshnessInspector;
use Greph\Index\IndexLifecycle;
use Greph\Index\IndexLifecycleProfile;
use Greph\Index\IndexMode;
use Greph\Index\IndexSet;
use Greph\Index\IndexSetEntry;
use Greph\Index\IndexSetLoader;
use Greph\Index\TextIndexStore;
use Greph\Index\TextQueryCacheStore;
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
                : $this->runTextStats($this->parseStatsArguments($arguments)),
            'search' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runSearch($this->parseSearchArguments($arguments)),
            'set' => $this->runSetCommand($arguments),
            'ast-index' => $this->runAstCommand('index', $arguments),
            'ast-cache' => $this->runAstCommand('cache', $arguments),
            default => throw new \InvalidArgumentException(sprintf('Unknown subcommand: %s', $command)),
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function runSetCommand(array $arguments): int
    {
        $command = array_shift($arguments);

        return match ($command) {
            null, 'help', '--help' => $this->runHelp(),
            'build' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runSetBuild($this->parseSetCommandArguments($arguments), false),
            'refresh' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runSetBuild($this->parseSetCommandArguments($arguments), true),
            'stats' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runSetStats($this->parseSetCommandArguments($arguments)),
            'search' => in_array('--help', $arguments, true)
                ? $this->runHelp()
                : $this->runSetSearch($this->parseSetSearchArguments($arguments)),
            default => throw new \InvalidArgumentException(sprintf('Unknown set subcommand: %s', $command)),
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
                : $this->runAstStats($mode, $this->parseStatsArguments($arguments)),
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
     *   manifest: ?string,
     *   mode: ?string,
     *   indexes: list<string>,
     *   dryRefresh: bool
     * } $arguments
     */
    private function runSetBuild(array $arguments, bool $refresh): int
    {
        $set = $this->loadIndexSet($arguments['manifest']);
        $entries = $this->selectedSetEntries($set, $arguments['mode'], $arguments['indexes']);

        if ($entries === []) {
            throw new \RuntimeException('No enabled index-set entries matched the requested filters.');
        }

        $lines = [];

        foreach ($entries as $entry) {
            $action = $refresh ? 'Refreshed' : 'Built';

            if ($entry->mode === IndexMode::Text) {
                $result = $refresh
                    ? Greph::refreshTextIndex($entry->rootPath, $entry->indexPath, $entry->lifecycle)
                    : Greph::buildTextIndex($entry->rootPath, $entry->indexPath, $entry->lifecycle);
                $lines[] = sprintf(
                    '%s set entry %s [%s] for %d files in %s (%d trigrams, %.2fms, +%d ~%d -%d =%d)',
                    $action,
                    $entry->name,
                    $entry->mode->label(),
                    $result->fileCount,
                    $this->displayPath($result->indexPath),
                    $result->trigramCount,
                    $result->buildDurationMs,
                    $result->addedFiles,
                    $result->updatedFiles,
                    $result->deletedFiles,
                    $result->unchangedFiles,
                );
                continue;
            }

            if ($entry->mode === IndexMode::AstIndex) {
                $result = $refresh
                    ? Greph::refreshAstIndex($entry->rootPath, $entry->indexPath, $entry->lifecycle)
                    : Greph::buildAstIndex($entry->rootPath, $entry->indexPath, $entry->lifecycle);
                $lines[] = sprintf(
                    '%s set entry %s [%s] for %d files in %s (%d fact rows, %.2fms, +%d ~%d -%d =%d)',
                    $action,
                    $entry->name,
                    $entry->mode->label(),
                    $result->fileCount,
                    $this->displayPath($result->indexPath),
                    $result->factCount,
                    $result->buildDurationMs,
                    $result->addedFiles,
                    $result->updatedFiles,
                    $result->deletedFiles,
                    $result->unchangedFiles,
                );
                continue;
            }

            $result = $refresh
                ? Greph::refreshAstCache($entry->rootPath, $entry->indexPath, $entry->lifecycle)
                : Greph::buildAstCache($entry->rootPath, $entry->indexPath, $entry->lifecycle);
            $lines[] = sprintf(
                '%s set entry %s [%s] for %d files in %s (%d cached trees, %.2fms, +%d ~%d -%d =%d)',
                $action,
                $entry->name,
                $entry->mode->label(),
                $result->fileCount,
                $this->displayPath($result->indexPath),
                $result->cachedTreeCount,
                $result->buildDurationMs,
                $result->addedFiles,
                $result->updatedFiles,
                $result->deletedFiles,
                $result->unchangedFiles,
            );
        }

        $this->writeOutput(implode(PHP_EOL, $lines) . PHP_EOL);

        return 0;
    }

    /**
     * @param array{
     *   manifest: ?string,
     *   mode: ?string,
     *   indexes: list<string>,
     *   dryRefresh: bool
     * } $arguments
     */
    private function runSetStats(array $arguments): int
    {
        $set = $this->loadIndexSet($arguments['manifest']);
        $entries = $this->selectedSetEntries($set, $arguments['mode'], $arguments['indexes']);

        if ($entries === []) {
            throw new \RuntimeException('No enabled index-set entries matched the requested filters.');
        }

        $blocks = [];
        $aggregateDisk = 0;
        $aggregateQueryCacheCount = 0;
        $aggregateQueryCacheSize = 0;
        $aggregateTreeCount = 0;
        $aggregateTreeSize = 0;
        $staleEntries = 0;
        $missingEntries = 0;

        foreach ($entries as $entry) {
            $stats = $this->setEntryStats($entry, $arguments['dryRefresh']);

            if ($stats['missing']) {
                $missingEntries++;
            }

            if ($stats['stale']) {
                $staleEntries++;
            }

            $aggregateDisk += $stats['diskSize'];
            $aggregateQueryCacheCount += $stats['queryCacheCount'];
            $aggregateQueryCacheSize += $stats['queryCacheSize'];
            $aggregateTreeCount += $stats['treeCount'];
            $aggregateTreeSize += $stats['treeSize'];
            $blocks[] = $stats['block'];
        }

        array_unshift($blocks, $this->formatStatsBlock('Index set stats', [
            'Set' => $set->name,
            'Manifest' => $this->displayPath($set->path),
            'Entries' => (string) count($entries),
            'Modes' => implode(', ', array_values(array_unique(array_map(
                static fn (IndexSetEntry $entry): string => $entry->mode->label(),
                $entries,
            )))),
            'Aggregate disk size' => $this->formatBytes($aggregateDisk),
            'Aggregate query caches' => sprintf('%d (%s)', $aggregateQueryCacheCount, $this->formatBytes($aggregateQueryCacheSize)),
            'Aggregate cached trees' => sprintf('%d (%s)', $aggregateTreeCount, $this->formatBytes($aggregateTreeSize)),
            'Stale entries' => (string) $staleEntries,
            'Missing entries' => (string) $missingEntries,
        ]));

        $this->writeOutput(implode(PHP_EOL, $blocks));

        return 0;
    }

    /**
     * @param array{
     *   manifest: ?string,
     *   mode: string,
     *   indexes: list<string>,
     *   showIndexOrigin: bool,
     *   search: array<string, mixed>
     * } $arguments
     */
    private function runSetSearch(array $arguments): int
    {
        $set = $this->loadIndexSet($arguments['manifest']);
        $entries = $this->selectedSetEntries($set, $arguments['mode'], $arguments['indexes']);

        if ($entries === []) {
            throw new \RuntimeException('No enabled index-set entries matched the requested filters.');
        }

        /** @var list<string> $indexPaths */
        $indexPaths = array_map(
            static fn (IndexSetEntry $entry): string => $entry->indexPath,
            $entries,
        );

        if ($arguments['mode'] === IndexMode::Text->value) {
            /** @var array{
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
             *   tracePlan: bool,
             *   pattern: ?string,
             *   paths: list<string>
             * } $search */
            $search = $arguments['search'];

            if ($search['pattern'] === null) {
                $this->writeError("Missing search pattern.\n");

                return 2;
            }

            $fileTypeFilter = $this->createFileTypeFilter($search['type'], $search['typeNot']);
            $beforeContext = $search['context'] ?? $search['beforeContext'];
            $afterContext = $search['context'] ?? $search['afterContext'];
            $options = new TextSearchOptions(
                fixedString: $search['fixedString'],
                caseInsensitive: $search['caseInsensitive'],
                wholeWord: $search['wholeWord'],
                invertMatch: $search['invertMatch'],
                maxCount: $search['maxCount'],
                beforeContext: $beforeContext,
                afterContext: $afterContext,
                countOnly: $search['countOnly'],
                filesWithMatches: $search['filesWithMatches'],
                filesWithoutMatches: $search['filesWithoutMatches'],
                jsonOutput: $search['json'],
                collectCaptures: $search['json'],
                tracePlan: $search['tracePlan'],
                respectIgnore: !$search['noIgnore'],
                includeHidden: $search['hidden'],
                fileTypeFilter: $fileTypeFilter,
                globPatterns: $search['glob'],
                showLineNumbers: $search['showLineNumbers'],
                showFileNames: $this->shouldDisplayFileNames($search),
            );

            $searcher = new \Greph\Index\IndexedTextSearcher();
            $results = $searcher->searchMany($search['pattern'], $search['paths'], $options, $indexPaths);
            if ($search['tracePlan']) {
                $this->writeError(json_encode(
                    $searcher->planMany($search['pattern'], $search['paths'], $options, $indexPaths),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ) . PHP_EOL);
            }
            $displayResults = $this->displayTextResults(
                $results,
                $arguments['showIndexOrigin'] ? $entries : null,
            );

            if ($search['json']) {
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
                if ($search['filesWithoutMatches']) {
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

        /** @var array{
         *   json: bool,
         *   noIgnore: bool,
         *   hidden: bool,
         *   tracePlan: bool,
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
         * } $search */
        $search = $arguments['search'];

        if ($search['pattern'] === null) {
            $this->writeError("Missing AST pattern.\n");

            return 2;
        }

        $fileTypeFilter = $this->createFileTypeFilter($search['type'], $search['typeNot']) ?? new FileTypeFilter(['php']);
        $options = new AstSearchOptions(
            language: $search['lang'],
            jobs: $search['jobs'],
            respectIgnore: !$search['noIgnore'],
            includeHidden: $search['hidden'],
            fileTypeFilter: $fileTypeFilter,
            globPatterns: $search['glob'],
            skipParseErrors: !$search['strictParse'],
            jsonOutput: $search['json'],
            tracePlan: $search['tracePlan'],
        );

        try {
            if ($arguments['mode'] === IndexMode::AstIndex->value) {
                $searcher = new \Greph\Index\IndexedAstSearcher();
                $matches = $searcher->searchMany($search['pattern'], $search['paths'], $options, $indexPaths);
                if ($search['tracePlan']) {
                    $this->writeError(json_encode(
                        $searcher->planMany($search['pattern'], $search['paths'], $options, $indexPaths),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    ) . PHP_EOL);
                }
            } else {
                $searcher = new \Greph\Index\CachedAstSearcher();
                $matches = $searcher->searchMany($search['pattern'], $search['paths'], $options, $indexPaths);
                if ($search['tracePlan']) {
                    $this->writeError(json_encode(
                        $searcher->planMany($search['pattern'], $search['paths'], $options, $indexPaths),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    ) . PHP_EOL);
                }
            }
        } catch (\RuntimeException $exception) {
            $this->writeError($exception->getMessage() . PHP_EOL);

            return 2;
        }

        if ($search['filesWithMatches']) {
            return $this->writeAstMatchFiles($matches, $arguments['showIndexOrigin'] ? $entries : null);
        }

        if ($search['json']) {
            $this->writeOutput($this->formatAstJsonMatches($matches, $arguments['showIndexOrigin'] ? $entries : null));
        } else {
            foreach ($matches as $match) {
                $this->writeOutput(sprintf(
                    '%s:%d:%s',
                    $this->displayAstPath($match->file, $arguments['showIndexOrigin'] ? $entries : null),
                    $match->startLine,
                    $this->displayAstLine($match),
                ) . PHP_EOL);
            }
        }

        return $matches === [] ? 1 : 0;
    }

    /**
     * @param array{
     *   root: string,
     *   indexDirs: list<string>,
     *   lifecycle: string,
     *   maxChangedFiles: int,
     *   maxChangedBytes: int,
     *   dryRefresh: bool
     * } $arguments
     */
    private function runTextStats(array $arguments): int
    {
        $store = new TextIndexStore();
        $inspector = new IndexFreshnessInspector();
        $queryCacheStore = new TextQueryCacheStore();
        $blocks = [];
        $aggregateDisk = 0;
        $aggregateQueryCacheCount = 0;
        $aggregateQueryCacheSize = 0;
        $staleCount = 0;

        foreach ($this->resolveTextIndexPaths($store, $arguments['root'], $arguments['indexDirs']) as $indexPath) {
            $index = $store->load($indexPath, includePostings: true);
            $freshness = $inspector->inspectText($index);
            $queryStats = $queryCacheStore->stats($index->indexPath);
            $diskSize = $this->directorySize($index->indexPath);
            $fields = [
                'Root' => $this->displayPath($index->rootPath),
                'Index' => $this->displayPath($index->indexPath),
                'Files' => (string) count($index->files),
                'Trigram postings' => (string) count($index->postings),
                'Word postings' => (string) count($index->wordPostings),
                'Disk size' => $this->formatBytes($diskSize),
                'Query caches' => sprintf('%d (%s)', $queryStats['count'], $this->formatBytes($queryStats['size'])),
                'Lifecycle' => $index->lifecycle->label(),
                'Stale' => $freshness->stale ? 'yes' : 'no',
                'Changes' => $freshness->summary(),
                'Last refresh' => $this->formatTimestamp($index->builtAt),
                'Last build time' => sprintf('%.2fms', $index->buildDurationMs),
            ];

            if ($arguments['dryRefresh']) {
                $fields['Search behavior'] = $this->refreshDecisionLabel($index->lifecycle, $freshness);
            }

            $aggregateDisk += $diskSize;
            $aggregateQueryCacheCount += $queryStats['count'];
            $aggregateQueryCacheSize += $queryStats['size'];
            $staleCount += $freshness->stale ? 1 : 0;
            $blocks[] = $this->formatStatsBlock('Text index stats', $fields);
        }

        if (count($blocks) > 1) {
            array_unshift($blocks, $this->formatStatsBlock('Text index aggregate', [
                'Indexes' => (string) count($blocks),
                'Aggregate disk size' => $this->formatBytes($aggregateDisk),
                'Aggregate query caches' => sprintf('%d (%s)', $aggregateQueryCacheCount, $this->formatBytes($aggregateQueryCacheSize)),
                'Stale indexes' => (string) $staleCount,
            ]));
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
     *   maxChangedBytes: int,
     *   dryRefresh: bool
     * } $arguments
     */
    private function runAstStats(string $mode, array $arguments): int
    {
        $inspector = new IndexFreshnessInspector();
        $queryCacheStore = new AstQueryCacheStore();

        if ($mode === 'index') {
            $store = new AstIndexStore();
            $blocks = [];
            $aggregateDisk = 0;
            $aggregateQueryCacheCount = 0;
            $aggregateQueryCacheSize = 0;
            $staleCount = 0;

            foreach ($this->resolveAstIndexPaths($store, $arguments['root'], $arguments['indexDirs']) as $indexPath) {
                $index = $store->load($indexPath);
                $freshness = $inspector->inspectAstIndex($index);
                $queryStats = $queryCacheStore->stats($index->indexPath);
                $diskSize = $this->directorySize($index->indexPath);
                $fields = [
                    'Root' => $this->displayPath($index->rootPath),
                    'Index' => $this->displayPath($index->indexPath),
                    'Files' => (string) count($index->files),
                    'Fact rows' => (string) count($index->facts),
                    'Disk size' => $this->formatBytes($diskSize),
                    'Query caches' => sprintf('%d (%s)', $queryStats['count'], $this->formatBytes($queryStats['size'])),
                    'Lifecycle' => $index->lifecycle->label(),
                    'Stale' => $freshness->stale ? 'yes' : 'no',
                    'Changes' => $freshness->summary(),
                    'Last refresh' => $this->formatTimestamp($index->builtAt),
                    'Last build time' => sprintf('%.2fms', $index->buildDurationMs),
                ];

                if ($arguments['dryRefresh']) {
                    $fields['Search behavior'] = $this->refreshDecisionLabel($index->lifecycle, $freshness);
                }

                $aggregateDisk += $diskSize;
                $aggregateQueryCacheCount += $queryStats['count'];
                $aggregateQueryCacheSize += $queryStats['size'];
                $staleCount += $freshness->stale ? 1 : 0;
                $blocks[] = $this->formatStatsBlock('AST index stats', $fields);
            }

            if (count($blocks) > 1) {
                array_unshift($blocks, $this->formatStatsBlock('AST index aggregate', [
                    'Indexes' => (string) count($blocks),
                    'Aggregate disk size' => $this->formatBytes($aggregateDisk),
                    'Aggregate query caches' => sprintf('%d (%s)', $aggregateQueryCacheCount, $this->formatBytes($aggregateQueryCacheSize)),
                    'Stale indexes' => (string) $staleCount,
                ]));
            }

            $this->writeOutput(implode(PHP_EOL, $blocks));

            return 0;
        }

        $store = new AstCacheStore();
        $blocks = [];
        $aggregateDisk = 0;
        $aggregateQueryCacheCount = 0;
        $aggregateQueryCacheSize = 0;
        $aggregateTreeCount = 0;
        $aggregateTreeSize = 0;
        $staleCount = 0;

        foreach ($this->resolveAstCachePaths($store, $arguments['root'], $arguments['indexDirs']) as $indexPath) {
            $cache = $store->load($indexPath);
            $treeStats = $store->treeStats($cache->indexPath);
            $queryStats = $queryCacheStore->stats($cache->indexPath);
            $cachedTreeCount = count(array_filter(
                $cache->facts,
                static fn (array $facts): bool => $facts['cached'],
            ));
            $freshness = $inspector->inspectAstCache($cache);
            $diskSize = $this->directorySize($cache->indexPath);
            $fields = [
                'Root' => $this->displayPath($cache->rootPath),
                'Index' => $this->displayPath($cache->indexPath),
                'Files' => (string) count($cache->files),
                'Fact rows' => (string) count($cache->facts),
                'Cached trees' => sprintf('%d (%s)', $cachedTreeCount, $this->formatBytes($treeStats['size'])),
                'Disk size' => $this->formatBytes($diskSize),
                'Query caches' => sprintf('%d (%s)', $queryStats['count'], $this->formatBytes($queryStats['size'])),
                'Lifecycle' => $cache->lifecycle->label(),
                'Stale' => $freshness->stale ? 'yes' : 'no',
                'Changes' => $freshness->summary(),
                'Last refresh' => $this->formatTimestamp($cache->builtAt),
                'Last build time' => sprintf('%.2fms', $cache->buildDurationMs),
            ];

            if ($arguments['dryRefresh']) {
                $fields['Search behavior'] = $this->refreshDecisionLabel($cache->lifecycle, $freshness);
            }

            $aggregateDisk += $diskSize;
            $aggregateQueryCacheCount += $queryStats['count'];
            $aggregateQueryCacheSize += $queryStats['size'];
            $aggregateTreeCount += $treeStats['count'];
            $aggregateTreeSize += $treeStats['size'];
            $staleCount += $freshness->stale ? 1 : 0;
            $blocks[] = $this->formatStatsBlock('AST cache stats', $fields);
        }

        if (count($blocks) > 1) {
            array_unshift($blocks, $this->formatStatsBlock('AST cache aggregate', [
                'Indexes' => (string) count($blocks),
                'Aggregate disk size' => $this->formatBytes($aggregateDisk),
                'Aggregate query caches' => sprintf('%d (%s)', $aggregateQueryCacheCount, $this->formatBytes($aggregateQueryCacheSize)),
                'Aggregate cached trees' => sprintf('%d (%s)', $aggregateTreeCount, $this->formatBytes($aggregateTreeSize)),
                'Stale indexes' => (string) $staleCount,
            ]));
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
      *   showIndexOrigin: bool,
     *   tracePlan: bool,
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
            tracePlan: $arguments['tracePlan'],
            respectIgnore: !$arguments['noIgnore'],
            includeHidden: $arguments['hidden'],
            fileTypeFilter: $fileTypeFilter,
            globPatterns: $arguments['glob'],
            showLineNumbers: $arguments['showLineNumbers'],
            showFileNames: $this->shouldDisplayFileNames($arguments),
        );

        $searcher = new \Greph\Index\IndexedTextSearcher();
        $results = count($arguments['indexDirs']) > 1
            ? $searcher->searchMany($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'])
            : $searcher->search(
                $arguments['pattern'],
                $arguments['paths'],
                $options,
                $arguments['indexDirs'][0] ?? null,
            );
        if ($arguments['tracePlan']) {
            $plan = count($arguments['indexDirs']) > 1
                ? $searcher->planMany($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'])
                : $searcher->plan($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'][0] ?? null);
            $this->writeError(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }
        $displayResults = $this->displayTextResults(
            $results,
            $arguments['showIndexOrigin']
                ? $this->textOriginEntries($arguments['paths'], $arguments['indexDirs'])
                : null,
        );

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
     *   showIndexOrigin: bool,
     *   tracePlan: bool,
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
            tracePlan: $arguments['tracePlan'],
        );

        try {
            try {
                if ($mode === 'index') {
                    $searcher = new \Greph\Index\IndexedAstSearcher();
                    $matches = count($arguments['indexDirs']) > 1
                        ? $searcher->searchMany($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'])
                        : $searcher->search($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'][0] ?? null);
                    if ($arguments['tracePlan']) {
                        $plan = count($arguments['indexDirs']) > 1
                            ? $searcher->planMany($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'])
                            : $searcher->plan($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'][0] ?? null);
                        $this->writeError(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                    }
                } else {
                    $searcher = new \Greph\Index\CachedAstSearcher();
                    $matches = count($arguments['indexDirs']) > 1
                        ? $searcher->searchMany($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'])
                        : $searcher->search($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'][0] ?? null);
                    if ($arguments['tracePlan']) {
                        $plan = count($arguments['indexDirs']) > 1
                            ? $searcher->planMany($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'])
                            : $searcher->plan($arguments['pattern'], $arguments['paths'], $options, $arguments['indexDirs'][0] ?? null);
                        $this->writeError(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                    }
                }
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
            return $this->writeAstMatchFiles(
                $matches,
                $arguments['showIndexOrigin']
                    ? $this->astOriginEntries($mode, $arguments['paths'], $arguments['indexDirs'])
                    : null,
            );
        }

        if ($arguments['json']) {
            $this->writeOutput($this->formatAstJsonMatches(
                $matches,
                $arguments['showIndexOrigin']
                    ? $this->astOriginEntries($mode, $arguments['paths'], $arguments['indexDirs'])
                    : null,
            ));
        } else {
            $originEntries = $arguments['showIndexOrigin']
                ? $this->astOriginEntries($mode, $arguments['paths'], $arguments['indexDirs'])
                : null;
            foreach ($matches as $match) {
                $this->writeOutput(sprintf(
                    '%s:%d:%s',
                    $this->displayAstPath($match->file, $originEntries),
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
     *   root: string,
     *   indexDirs: list<string>,
     *   lifecycle: string,
     *   maxChangedFiles: int,
     *   maxChangedBytes: int,
     *   dryRefresh: bool
     * }
     */
    private function parseStatsArguments(array $arguments): array
    {
        $parsed = $this->parseBuildArguments(array_values(array_filter(
            $arguments,
            static fn (string $argument): bool => $argument !== '--dry-refresh',
        )));
        $parsed['dryRefresh'] = in_array('--dry-refresh', $arguments, true);

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
      *   showIndexOrigin: bool,
     *   tracePlan: bool,
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
            'showIndexOrigin' => false,
            'tracePlan' => false,
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
                case '--show-index-origin':
                    $parsed['showIndexOrigin'] = true;
                    break;
                case '--no-index-origin':
                    $parsed['showIndexOrigin'] = false;
                    break;
                case '--trace-plan':
                    $parsed['tracePlan'] = true;
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
     *   showIndexOrigin: bool,
     *   tracePlan: bool,
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
            'showIndexOrigin' => false,
            'tracePlan' => false,
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
                case '--show-index-origin':
                    $parsed['showIndexOrigin'] = true;
                    break;
                case '--no-index-origin':
                    $parsed['showIndexOrigin'] = false;
                    break;
                case '--trace-plan':
                    $parsed['tracePlan'] = true;
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
     * @return array{
     *   manifest: ?string,
     *   mode: ?string,
     *   indexes: list<string>,
     *   dryRefresh: bool
     * }
     */
    private function parseSetCommandArguments(array $arguments): array
    {
        $parsed = [
            'manifest' => null,
            'mode' => null,
            'indexes' => [],
            'dryRefresh' => false,
        ];

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--manifest') {
                $parsed['manifest'] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--mode') {
                $parsed['mode'] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--index') {
                $parsed['indexes'][] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--dry-refresh') {
                $parsed['dryRefresh'] = true;
                continue;
            }

            if ($argument[0] === '-') {
                throw new \InvalidArgumentException(sprintf('Unknown argument: %s', $argument));
            }

            if ($parsed['manifest'] !== null) {
                throw new \InvalidArgumentException(sprintf('Unknown argument: %s', $argument));
            }

            $parsed['manifest'] = $argument;
        }

        if ($parsed['mode'] !== null && IndexMode::tryFrom($parsed['mode']) === null) {
            throw new \InvalidArgumentException(sprintf('Unknown index-set mode: %s', $parsed['mode']));
        }

        return $parsed;
    }

    /**
     * @param list<string> $arguments
     * @return array{
     *   manifest: ?string,
     *   mode: string,
     *   indexes: list<string>,
     *   showIndexOrigin: bool,
     *   search: array<string, mixed>
     * }
     */
    private function parseSetSearchArguments(array $arguments): array
    {
        $manifest = null;
        $mode = IndexMode::Text->value;
        $indexes = [];
        $showIndexOrigin = false;
        $remaining = [];

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--manifest') {
                $manifest = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--mode') {
                $mode = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--index') {
                $indexes[] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--show-index-origin') {
                $showIndexOrigin = true;
                continue;
            }

            if ($argument === '--no-index-origin') {
                $showIndexOrigin = false;
                continue;
            }

            $remaining[] = $argument;
        }

        if (IndexMode::tryFrom($mode) === null) {
            throw new \InvalidArgumentException(sprintf('Unknown index-set mode: %s', $mode));
        }

        $search = $mode === IndexMode::Text->value
            ? $this->parseSearchArguments($remaining)
            : $this->parseAstSearchArguments($remaining);

        return [
            'manifest' => $manifest,
            'mode' => $mode,
            'indexes' => $indexes,
            'showIndexOrigin' => $showIndexOrigin,
            'search' => $search,
        ];
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
     * @param list<IndexSetEntry>|null $originEntries
     * @return list<TextFileResult>
     */
    private function displayTextResults(array $results, ?array $originEntries = null): array
    {
        $displayResults = [];

        foreach ($results as $result) {
            $displayFile = $this->displayTextPath($result->file, $originEntries);
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
     * @param list<IndexSetEntry>|null $originEntries
     */
    private function writeAstMatchFiles(array $matches, ?array $originEntries = null): int
    {
        if ($matches === []) {
            return 1;
        }

        $files = [];

        foreach ($matches as $match) {
            $files[$this->displayAstPath($match->file, $originEntries)] = true;
        }

        $this->writeOutput(implode(PHP_EOL, array_keys($files)) . PHP_EOL);

        return 0;
    }

    /**
     * @param list<AstMatch> $matches
     * @param list<IndexSetEntry>|null $originEntries
     */
    private function formatAstJsonMatches(array $matches, ?array $originEntries = null): string
    {
        $payload = array_map(
            fn (AstMatch $match): array => [
                'file' => $this->displayAstPath($match->file, $originEntries),
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

    /**
     * @param list<IndexSetEntry>|null $originEntries
     */
    private function displayTextPath(string $path, ?array $originEntries = null): string
    {
        return $this->displayOriginPath($path, $originEntries);
    }

    /**
     * @param list<IndexSetEntry>|null $originEntries
     */
    private function displayAstPath(string $path, ?array $originEntries = null): string
    {
        return $this->displayOriginPath($path, $originEntries);
    }

    /**
     * @param list<IndexSetEntry>|null $originEntries
     */
    private function displayOriginPath(string $path, ?array $originEntries = null): string
    {
        $displayPath = $this->displayPath($path);

        if ($originEntries === null || $originEntries === []) {
            return $displayPath;
        }

        foreach ($originEntries as $entry) {
            if ($this->isWithinEntryRoot($path, $entry)) {
                return sprintf('[%s] %s', $entry->name, $displayPath);
            }
        }

        return $displayPath;
    }

    private function loadIndexSet(?string $manifestPath): IndexSet
    {
        return (new IndexSetLoader())->load($manifestPath);
    }

    /**
     * @param list<string> $names
     * @return list<IndexSetEntry>
     */
    private function selectedSetEntries(IndexSet $set, ?string $mode, array $names): array
    {
        $modeObject = $mode !== null ? IndexMode::from($mode) : null;

        return $set->entries($modeObject, $names);
    }

    /**
     * @param list<string> $paths
     * @param list<string> $indexDirs
     * @return list<IndexSetEntry>
     */
    private function textOriginEntries(array $paths, array $indexDirs): array
    {
        if ($indexDirs === []) {
            return [];
        }

        $root = $paths[0] ?? '.';
        $store = new TextIndexStore();
        $entries = [];

        foreach ($this->resolveTextIndexPaths($store, $root, $indexDirs) as $indexPath) {
            $index = $store->load($indexPath);
            $entries[] = new IndexSetEntry(
                name: $this->displayPath($index->rootPath),
                rootPath: $index->rootPath,
                indexPath: $index->indexPath,
                mode: IndexMode::Text,
                lifecycle: $index->lifecycle,
            );
        }

        return $entries;
    }

    /**
     * @param list<string> $paths
     * @param list<string> $indexDirs
     * @return list<IndexSetEntry>
     */
    private function astOriginEntries(string $mode, array $paths, array $indexDirs): array
    {
        if ($indexDirs === []) {
            return [];
        }

        $root = $paths[0] ?? '.';
        $entries = [];

        if ($mode === 'index') {
            $store = new AstIndexStore();

            foreach ($this->resolveAstIndexPaths($store, $root, $indexDirs) as $indexPath) {
                $index = $store->load($indexPath);
                $entries[] = new IndexSetEntry(
                    name: $this->displayPath($index->rootPath),
                    rootPath: $index->rootPath,
                    indexPath: $index->indexPath,
                    mode: IndexMode::AstIndex,
                    lifecycle: $index->lifecycle,
                );
            }

            return $entries;
        }

        $store = new AstCacheStore();

        foreach ($this->resolveAstCachePaths($store, $root, $indexDirs) as $indexPath) {
            $cache = $store->load($indexPath);
            $entries[] = new IndexSetEntry(
                name: $this->displayPath($cache->rootPath),
                rootPath: $cache->rootPath,
                indexPath: $cache->indexPath,
                mode: IndexMode::AstCache,
                lifecycle: $cache->lifecycle,
            );
        }

        return $entries;
    }

    /**
     * @return array{
     *   block: string,
     *   missing: bool,
     *   stale: bool,
     *   diskSize: int,
     *   queryCacheCount: int,
     *   queryCacheSize: int,
     *   treeCount: int,
     *   treeSize: int
     * }
     */
    private function setEntryStats(IndexSetEntry $entry, bool $dryRefresh): array
    {
        $root = $this->displayPath($entry->rootPath);
        $index = $this->displayPath($entry->indexPath);
        $common = [
            'Entry' => $entry->name,
            'Mode' => $entry->mode->label(),
            'Root' => $root,
            'Index' => $index,
            'Lifecycle' => $entry->lifecycle->label(),
        ];

        if ($entry->mode === IndexMode::Text) {
            $store = new TextIndexStore();

            if (!$store->exists($entry->indexPath)) {
                return [
                    'block' => $this->formatStatsBlock('Index set entry stats', [
                        ...$common,
                        'Status' => 'missing',
                    ]),
                    'missing' => true,
                    'stale' => false,
                    'diskSize' => 0,
                    'queryCacheCount' => 0,
                    'queryCacheSize' => 0,
                    'treeCount' => 0,
                    'treeSize' => 0,
                ];
            }

            $inspector = new IndexFreshnessInspector();
            $queryStats = (new TextQueryCacheStore())->stats($entry->indexPath);
            $textIndex = $store->load($entry->indexPath, includePostings: true);
            $freshness = $inspector->inspectText($textIndex);
            $diskSize = $this->directorySize($entry->indexPath);
            $fields = [
                ...$common,
                'Files' => (string) count($textIndex->files),
                'Trigram postings' => (string) count($textIndex->postings),
                'Word postings' => (string) count($textIndex->wordPostings),
                'Disk size' => $this->formatBytes($diskSize),
                'Query caches' => sprintf('%d (%s)', $queryStats['count'], $this->formatBytes($queryStats['size'])),
                'Stale' => $freshness->stale ? 'yes' : 'no',
                'Changes' => $freshness->summary(),
                'Last refresh' => $this->formatTimestamp($textIndex->builtAt),
                'Last build time' => sprintf('%.2fms', $textIndex->buildDurationMs),
            ];

            if ($dryRefresh) {
                $fields['Search behavior'] = $this->refreshDecisionLabel($entry->lifecycle, $freshness);
            }

            return [
                'block' => $this->formatStatsBlock('Index set entry stats', $fields),
                'missing' => false,
                'stale' => $freshness->stale,
                'diskSize' => $diskSize,
                'queryCacheCount' => $queryStats['count'],
                'queryCacheSize' => $queryStats['size'],
                'treeCount' => 0,
                'treeSize' => 0,
            ];
        }

        if ($entry->mode === IndexMode::AstIndex) {
            $store = new AstIndexStore();

            if (!$store->exists($entry->indexPath)) {
                return [
                    'block' => $this->formatStatsBlock('Index set entry stats', [
                        ...$common,
                        'Status' => 'missing',
                    ]),
                    'missing' => true,
                    'stale' => false,
                    'diskSize' => 0,
                    'queryCacheCount' => 0,
                    'queryCacheSize' => 0,
                    'treeCount' => 0,
                    'treeSize' => 0,
                ];
            }

            $inspector = new IndexFreshnessInspector();
            $queryStats = (new AstQueryCacheStore())->stats($entry->indexPath);
            $astIndex = $store->load($entry->indexPath);
            $freshness = $inspector->inspectAstIndex($astIndex);
            $diskSize = $this->directorySize($entry->indexPath);
            $fields = [
                ...$common,
                'Files' => (string) count($astIndex->files),
                'Fact rows' => (string) count($astIndex->facts),
                'Disk size' => $this->formatBytes($diskSize),
                'Query caches' => sprintf('%d (%s)', $queryStats['count'], $this->formatBytes($queryStats['size'])),
                'Stale' => $freshness->stale ? 'yes' : 'no',
                'Changes' => $freshness->summary(),
                'Last refresh' => $this->formatTimestamp($astIndex->builtAt),
                'Last build time' => sprintf('%.2fms', $astIndex->buildDurationMs),
            ];

            if ($dryRefresh) {
                $fields['Search behavior'] = $this->refreshDecisionLabel($entry->lifecycle, $freshness);
            }

            return [
                'block' => $this->formatStatsBlock('Index set entry stats', $fields),
                'missing' => false,
                'stale' => $freshness->stale,
                'diskSize' => $diskSize,
                'queryCacheCount' => $queryStats['count'],
                'queryCacheSize' => $queryStats['size'],
                'treeCount' => 0,
                'treeSize' => 0,
            ];
        }

        $store = new AstCacheStore();

        if (!$store->exists($entry->indexPath)) {
            return [
                'block' => $this->formatStatsBlock('Index set entry stats', [
                    ...$common,
                    'Status' => 'missing',
                ]),
                'missing' => true,
                'stale' => false,
                'diskSize' => 0,
                'queryCacheCount' => 0,
                'queryCacheSize' => 0,
                'treeCount' => 0,
                'treeSize' => 0,
            ];
        }

        $inspector = new IndexFreshnessInspector();
        $queryStats = (new AstQueryCacheStore())->stats($entry->indexPath);
        $treeStats = $store->treeStats($entry->indexPath);
        $cache = $store->load($entry->indexPath);
        $freshness = $inspector->inspectAstCache($cache);
        $cachedTreeCount = count(array_filter(
            $cache->facts,
            static fn (array $facts): bool => $facts['cached'],
        ));
        $diskSize = $this->directorySize($entry->indexPath);
        $fields = [
            ...$common,
            'Files' => (string) count($cache->files),
            'Fact rows' => (string) count($cache->facts),
            'Cached trees' => sprintf('%d (%s)', $cachedTreeCount, $this->formatBytes($treeStats['size'])),
            'Disk size' => $this->formatBytes($diskSize),
            'Query caches' => sprintf('%d (%s)', $queryStats['count'], $this->formatBytes($queryStats['size'])),
            'Stale' => $freshness->stale ? 'yes' : 'no',
            'Changes' => $freshness->summary(),
            'Last refresh' => $this->formatTimestamp($cache->builtAt),
            'Last build time' => sprintf('%.2fms', $cache->buildDurationMs),
        ];

        if ($dryRefresh) {
            $fields['Search behavior'] = $this->refreshDecisionLabel($entry->lifecycle, $freshness);
        }

        return [
            'block' => $this->formatStatsBlock('Index set entry stats', $fields),
            'missing' => false,
            'stale' => $freshness->stale,
            'diskSize' => $diskSize,
            'queryCacheCount' => $queryStats['count'],
            'queryCacheSize' => $queryStats['size'],
            'treeCount' => $treeStats['count'],
            'treeSize' => $treeStats['size'],
        ];
    }

    private function refreshDecisionLabel(IndexLifecycle $lifecycle, \Greph\Index\IndexFreshness $freshness): string
    {
        if (!$freshness->stale) {
            return 'use as-is (fresh)';
        }

        if ($lifecycle->shouldRejectStale()) {
            return 'reject stale search';
        }

        if ($lifecycle->shouldAutoRefresh()) {
            return $freshness->isCheapEnough($lifecycle)
                ? 'auto-refresh before search'
                : 'use stale index and skip auto-refresh';
        }

        if ($lifecycle->profile === IndexLifecycleProfile::Static) {
            return 'use as-is (static index)';
        }

        return 'use stale index until explicit refresh';
    }

    private function isWithinEntryRoot(string $path, IndexSetEntry $entry): bool
    {
        $path = Filesystem::normalizePath($path);
        $rootPath = Filesystem::normalizePath($entry->rootPath);

        return $path === $rootPath || str_starts_with($path, $rootPath . '/');
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
  greph-index stats [path] [--index-dir DIR...] [--dry-refresh]
  greph-index search [options] pattern [path...]
  greph-index set build [manifest] [--manifest FILE] [--mode MODE] [--index NAME...]
  greph-index set refresh [manifest] [--manifest FILE] [--mode MODE] [--index NAME...]
  greph-index set stats [manifest] [--manifest FILE] [--mode MODE] [--index NAME...] [--dry-refresh]
  greph-index set search [--manifest FILE] [--mode MODE] [--index NAME...] [--show-index-origin] [options] pattern [path...]
  greph-index ast-index build [path] [--index-dir DIR] [--lifecycle PROFILE] [--auto-refresh-max-files N] [--auto-refresh-max-bytes N]
  greph-index ast-index refresh [path] [--index-dir DIR] [--lifecycle PROFILE] [--auto-refresh-max-files N] [--auto-refresh-max-bytes N]
  greph-index ast-index stats [path] [--index-dir DIR...] [--dry-refresh]
  greph-index ast-index search [options] pattern [path...]
  greph-index ast-cache build [path] [--index-dir DIR] [--lifecycle PROFILE] [--auto-refresh-max-files N] [--auto-refresh-max-bytes N]
  greph-index ast-cache refresh [path] [--index-dir DIR] [--lifecycle PROFILE] [--auto-refresh-max-files N] [--auto-refresh-max-bytes N]
  greph-index ast-cache stats [path] [--index-dir DIR...] [--dry-refresh]
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
  --manifest FILE Load a named index-set manifest. Default: .greph-index-set.json
  --mode MODE     Index-set mode: text|ast-index|ast-cache.
  --index NAME    Restrict set operations to a named manifest entry. Repeatable.
  --show-index-origin
                  Prefix set-search output paths with the matching manifest entry.
  --trace-plan    Emit warmed planner diagnostics to stderr.
  --dry-refresh   Report what warmed search would do without mutating anything.
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
  --show-index-origin     Prefix warmed output paths with the matching index name.
  --trace-plan            Emit warmed planner diagnostics to stderr.
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
