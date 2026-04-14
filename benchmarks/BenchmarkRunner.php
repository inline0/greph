<?php

declare(strict_types=1);

namespace Greph\Benchmarks;

use Greph\Ast\AstSearchOptions;
use Greph\Index\AstCacheBuilder;
use Greph\Index\AstCacheStore;
use Greph\Index\AstIndexBuilder;
use Greph\Index\AstIndexStore;
use Greph\Index\TextIndexBuilder;
use Greph\Index\TextIndexStore;
use Greph\Index\TextQueryCacheStore;
use Greph\Index\TrigramExtractor;
use Greph\Greph;
use Greph\Support\CommandRunner;
use Greph\Support\Filesystem;
use Greph\Support\Json;
use Greph\Support\ToolResolver;
use Greph\Text\TextSearchOptions;

final class BenchmarkRunner
{
    private const REFRESH_DIRTY_FILE_COUNT = 4;

    private CommandRunner $commandRunner;

    private ToolResolver $toolResolver;

    public function __construct(private readonly string $rootPath, ?CommandRunner $commandRunner = null, ?ToolResolver $toolResolver = null)
    {
        $this->commandRunner = $commandRunner ?? new CommandRunner();
        $this->toolResolver = $toolResolver ?? new ToolResolver();
    }

    /**
     * @param list<string> $compareTools
     * @return list<BenchmarkResult>
     */
    public function run(?string $category = null, ?string $corpusFilter = null, array $compareTools = []): array
    {
        (new SyntheticCorpusGenerator($this->rootPath . '/benchmarks/corpora/synthetic'))->ensure();

        $results = [];

        foreach ($this->corpora() as $corpusName => $corpusPath) {
            if ($corpusFilter !== null && $corpusFilter !== $corpusName) {
                continue;
            }

            if (!is_dir($corpusPath)) {
                continue;
            }

            foreach ($this->suites() as $suite) {
                if (isset($suite['corpora']) && is_array($suite['corpora']) && !in_array($corpusName, $suite['corpora'], true)) {
                    continue;
                }

                if ($category !== null && $suite['category'] !== $category) {
                    continue;
                }

                $results[] = $this->runGrephBenchmark($suite, $corpusName, $corpusPath);

                foreach ($compareTools as $tool) {
                    $external = $this->runExternalBenchmark($suite, $tool, $corpusName, $corpusPath);

                    if ($external !== null) {
                        $results[] = $external;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $suite
     */
    private function runGrephBenchmark(array $suite, string $corpusName, string $corpusPath): BenchmarkResult
    {
        $fileCount = iterator_count(Greph::walk($corpusPath));
        $indexPath = $this->textIndexPath($suite, $corpusName);
        $astIndexPath = $this->astIndexPath($suite, $corpusName);
        $astCachePath = $this->astCachePath($suite, $corpusName);
        $runtimeFileCount = $fileCount;

        if (in_array($suite['category'], ['indexed-text', 'indexed-text-cold', 'indexed-load', 'indexed-summary'], true)) {
            if (!(new TextIndexStore())->exists($indexPath)) {
                (new TextIndexBuilder())->build($corpusPath, $indexPath);
            }
        }

        if (in_array($suite['category'], ['ast-indexed', 'ast-indexed-build'], true)) {
            if (!(new AstIndexStore())->exists($astIndexPath) && $suite['category'] !== 'ast-indexed-build') {
                (new AstIndexBuilder())->build($corpusPath, $astIndexPath);
            }
        }

        if (in_array($suite['category'], ['ast-cached', 'ast-cached-build'], true)) {
            if (!(new AstCacheStore())->exists($astCachePath) && $suite['category'] !== 'ast-cached-build') {
                (new AstCacheBuilder())->build($corpusPath, $astCachePath);
            }
        }

        $memoryBefore = memory_get_usage(true);
        $start = hrtime(true);
        $matchCount = 0;

        switch ($suite['category']) {
            case 'text':
                $results = Greph::searchText((string) $suite['pattern'], $corpusPath, new TextSearchOptions(
                    fixedString: (bool) ($suite['fixed'] ?? false),
                    caseInsensitive: (bool) ($suite['case_insensitive'] ?? false),
                    wholeWord: (bool) ($suite['whole_word'] ?? false),
                    quiet: (bool) ($suite['quiet'] ?? false),
                    collectCaptures: false,
                    jobs: (int) ($suite['jobs'] ?? 1),
                ));

                foreach ($results as $result) {
                    $matchCount += $result->matchCount();
                }
                break;

            case 'ast':
                $results = Greph::searchAst((string) $suite['pattern'], $corpusPath, new AstSearchOptions(
                    jobs: (int) ($suite['jobs'] ?? 1),
                    language: (string) ($suite['lang'] ?? 'php'),
                ));
                $matchCount = count($results);
                break;

            case 'ast-internal':
                $matchCount = (new \Greph\Ast\AstSearcher())->countFiles(
                    Greph::walk($corpusPath),
                    (string) $suite['pattern'],
                    new AstSearchOptions(
                        jobs: (int) ($suite['jobs'] ?? 1),
                        language: (string) ($suite['lang'] ?? 'php'),
                    ),
                );
                break;

            case 'ast-parse':
                $matchCount = (new \Greph\Ast\AstSearcher())->countParsedFiles(
                    Greph::walk($corpusPath),
                    (string) $suite['pattern'],
                    new AstSearchOptions(
                        jobs: (int) ($suite['jobs'] ?? 1),
                        language: (string) ($suite['lang'] ?? 'php'),
                    ),
                );
                break;

            case 'ast-indexed':
                $results = Greph::searchAstIndexed(
                    (string) $suite['pattern'],
                    $corpusPath,
                    new AstSearchOptions(
                        jobs: (int) ($suite['jobs'] ?? 1),
                        language: (string) ($suite['lang'] ?? 'php'),
                    ),
                    $astIndexPath,
                );
                $matchCount = count($results);
                break;

            case 'ast-indexed-build':
                Filesystem::remove($astIndexPath);
                $result = (new AstIndexBuilder())->build($corpusPath, $astIndexPath);
                $matchCount = $result->fileCount;
                break;

            case 'ast-indexed-refresh':
                [$refreshCorpusPath, $refreshIndexPath] = $this->prepareAstIndexRefreshWorkspace($corpusPath, $corpusName);

                try {
                    $this->applyRefreshMutations($refreshCorpusPath);
                    $runtimeFileCount = iterator_count(Greph::walk($refreshCorpusPath));
                    $memoryBefore = memory_get_usage(true);
                    $start = hrtime(true);
                    $result = (new AstIndexBuilder())->refresh($refreshCorpusPath, $refreshIndexPath);
                    $matchCount = $result->addedFiles + $result->updatedFiles + $result->deletedFiles;
                } finally {
                    Filesystem::remove($refreshCorpusPath);
                }
                break;

            case 'ast-cached':
                $results = Greph::searchAstCached(
                    (string) $suite['pattern'],
                    $corpusPath,
                    new AstSearchOptions(
                        jobs: (int) ($suite['jobs'] ?? 1),
                        language: (string) ($suite['lang'] ?? 'php'),
                    ),
                    $astCachePath,
                );
                $matchCount = count($results);
                break;

            case 'indexed-text-many':
                [$workspacePath, $textIndexPaths] = $this->preparePartitionedTextWorkspace($corpusPath, $corpusName);
                $runtimeFileCount = iterator_count(Greph::walk($workspacePath));
                $memoryBefore = memory_get_usage(true);
                $start = hrtime(true);
                $results = Greph::searchTextIndexedMany(
                    (string) $suite['pattern'],
                    $workspacePath,
                    $textIndexPaths,
                    new TextSearchOptions(
                        fixedString: (bool) ($suite['fixed'] ?? false),
                        caseInsensitive: (bool) ($suite['case_insensitive'] ?? false),
                        wholeWord: (bool) ($suite['whole_word'] ?? false),
                        countOnly: (bool) ($suite['count_only'] ?? false),
                        filesWithMatches: (bool) ($suite['files_with_matches'] ?? false),
                        filesWithoutMatches: (bool) ($suite['files_without_matches'] ?? false),
                        quiet: (bool) ($suite['quiet'] ?? false),
                        collectCaptures: false,
                    ),
                );

                foreach ($results as $result) {
                    $matchCount += $result->matchCount();
                }
                break;

            case 'indexed-set-text':
                ['workspace' => $workspacePath, 'manifest' => $manifestPath] = $this->preparePartitionedIndexSet($corpusPath, $corpusName, includeText: true);
                $runtimeFileCount = iterator_count(Greph::walk($workspacePath));
                $memoryBefore = memory_get_usage(true);
                $start = hrtime(true);
                $results = Greph::searchTextIndexedSet(
                    (string) $suite['pattern'],
                    $workspacePath,
                    new TextSearchOptions(
                        fixedString: (bool) ($suite['fixed'] ?? false),
                        caseInsensitive: (bool) ($suite['case_insensitive'] ?? false),
                        wholeWord: (bool) ($suite['whole_word'] ?? false),
                        countOnly: (bool) ($suite['count_only'] ?? false),
                        filesWithMatches: (bool) ($suite['files_with_matches'] ?? false),
                        filesWithoutMatches: (bool) ($suite['files_without_matches'] ?? false),
                        quiet: (bool) ($suite['quiet'] ?? false),
                        collectCaptures: false,
                    ),
                    $manifestPath,
                );

                foreach ($results as $result) {
                    $matchCount += $result->matchCount();
                }
                break;

            case 'ast-indexed-set':
                ['workspace' => $workspacePath, 'manifest' => $manifestPath] = $this->preparePartitionedIndexSet($corpusPath, $corpusName, includeAstIndex: true);
                $runtimeFileCount = iterator_count(Greph::walk($workspacePath));
                $memoryBefore = memory_get_usage(true);
                $start = hrtime(true);
                $results = Greph::searchAstIndexedSet(
                    (string) $suite['pattern'],
                    $workspacePath,
                    new AstSearchOptions(
                        jobs: (int) ($suite['jobs'] ?? 1),
                        language: (string) ($suite['lang'] ?? 'php'),
                    ),
                    $manifestPath,
                );
                $matchCount = count($results);
                break;

            case 'ast-cached-set':
                ['workspace' => $workspacePath, 'manifest' => $manifestPath] = $this->preparePartitionedIndexSet($corpusPath, $corpusName, includeAstCache: true);
                $runtimeFileCount = iterator_count(Greph::walk($workspacePath));
                $memoryBefore = memory_get_usage(true);
                $start = hrtime(true);
                $results = Greph::searchAstCachedSet(
                    (string) $suite['pattern'],
                    $workspacePath,
                    new AstSearchOptions(
                        jobs: (int) ($suite['jobs'] ?? 1),
                        language: (string) ($suite['lang'] ?? 'php'),
                    ),
                    $manifestPath,
                );
                $matchCount = count($results);
                break;

            case 'ast-cached-build':
                Filesystem::remove($astCachePath);
                $result = (new AstCacheBuilder())->build($corpusPath, $astCachePath);
                $matchCount = $result->cachedTreeCount;
                break;

            case 'ast-cached-refresh':
                [$refreshCorpusPath, $refreshIndexPath] = $this->prepareAstCacheRefreshWorkspace($corpusPath, $corpusName);

                try {
                    $this->applyRefreshMutations($refreshCorpusPath);
                    $runtimeFileCount = iterator_count(Greph::walk($refreshCorpusPath));
                    $memoryBefore = memory_get_usage(true);
                    $start = hrtime(true);
                    $result = (new AstCacheBuilder())->refresh($refreshCorpusPath, $refreshIndexPath);
                    $matchCount = $result->addedFiles + $result->updatedFiles + $result->deletedFiles;
                } finally {
                    Filesystem::remove($refreshCorpusPath);
                }
                break;

            case 'walker':
                $matchCount = count(Greph::walk($corpusPath));
                break;

            case 'parallel':
                $results = Greph::searchText((string) $suite['pattern'], $corpusPath, new TextSearchOptions(
                    fixedString: (bool) ($suite['fixed'] ?? false),
                    wholeWord: (bool) ($suite['whole_word'] ?? false),
                    quiet: (bool) ($suite['quiet'] ?? false),
                    collectCaptures: false,
                    jobs: (int) ($suite['jobs'] ?? 1),
                ));

                foreach ($results as $result) {
                    $matchCount += $result->matchCount();
                }
                break;

            case 'indexed-build':
                Filesystem::remove($indexPath);
                $result = (new TextIndexBuilder())->build($corpusPath, $indexPath);
                $matchCount = $result->fileCount;
                break;

            case 'indexed-refresh':
                [$refreshCorpusPath, $refreshIndexPath] = $this->prepareTextRefreshWorkspace($corpusPath, $corpusName);

                try {
                    $this->applyRefreshMutations($refreshCorpusPath);
                    $runtimeFileCount = iterator_count(Greph::walk($refreshCorpusPath));
                    $memoryBefore = memory_get_usage(true);
                    $start = hrtime(true);
                    $result = (new TextIndexBuilder())->refresh($refreshCorpusPath, $refreshIndexPath);
                    $matchCount = $result->addedFiles + $result->updatedFiles + $result->deletedFiles;
                } finally {
                    Filesystem::remove($refreshCorpusPath);
                }
                break;

            case 'indexed-text':
            case 'indexed-text-cold':
            case 'indexed-summary':
                if ($suite['category'] === 'indexed-text-cold') {
                    (new TextQueryCacheStore())->clear($indexPath);
                }

                $results = Greph::searchTextIndexed(
                    (string) $suite['pattern'],
                    $corpusPath,
                    new TextSearchOptions(
                        fixedString: (bool) ($suite['fixed'] ?? false),
                        caseInsensitive: (bool) ($suite['case_insensitive'] ?? false),
                        wholeWord: (bool) ($suite['whole_word'] ?? false),
                        countOnly: (bool) ($suite['count_only'] ?? false),
                        filesWithMatches: (bool) ($suite['files_with_matches'] ?? false),
                        filesWithoutMatches: (bool) ($suite['files_without_matches'] ?? false),
                        quiet: (bool) ($suite['quiet'] ?? false),
                        collectCaptures: false,
                    ),
                    $indexPath,
                );

                foreach ($results as $result) {
                    $matchCount += $result->matchCount();
                }
                break;

            case 'indexed-load':
                $store = new TextIndexStore();

                if (($suite['mode'] ?? 'metadata') === 'postings') {
                    $trigrams = (new TrigramExtractor())->extract((string) ($suite['pattern'] ?? ''));
                    $postings = $store->loadSelectedPostings($indexPath, $trigrams);
                    $matchCount = count($postings);
                    break;
                }

                $loaded = $store->load($indexPath);
                $matchCount = count($loaded->files);
                break;

            default:
                throw new \RuntimeException(sprintf('Unknown benchmark category: %s', $suite['category']));
        }

        return new BenchmarkResult(
            category: (string) $suite['category'],
            suite: (string) $suite['suite'],
            operation: (string) $suite['name'],
            corpus: $corpusName,
            tool: 'greph',
            durationMs: (hrtime(true) - $start) / 1_000_000,
            memoryBytes: max(0, memory_get_usage(true) - $memoryBefore),
            fileCount: $runtimeFileCount,
            matchCount: $matchCount,
        );
    }

    /**
     * @param array<string, mixed> $suite
     */
    private function runExternalBenchmark(array $suite, string $tool, string $corpusName, string $corpusPath): ?BenchmarkResult
    {
        $command = match ($tool) {
            'grep' => $this->externalGrepCommand($suite, $corpusPath),
            'rg' => $this->externalRipgrepCommand($suite, $corpusPath),
            'sg' => $this->externalAstGrepCommand($suite, $corpusPath),
            default => null,
        };

        if ($command === null) {
            return null;
        }

        try {
            $result = $this->commandRunner->run($command, $this->rootPath);
        } catch (\Throwable $throwable) {
            return new BenchmarkResult(
                category: (string) $suite['category'],
                suite: (string) $suite['suite'],
                operation: (string) $suite['name'],
                corpus: $corpusName,
                tool: $tool,
                durationMs: 0.0,
                memoryBytes: 0,
                fileCount: 0,
                matchCount: 0,
                skipped: true,
                skipReason: $throwable->getMessage(),
            );
        }

        return new BenchmarkResult(
            category: (string) $suite['category'],
            suite: (string) $suite['suite'],
            operation: (string) $suite['name'],
            corpus: $corpusName,
            tool: $tool,
            durationMs: $result->durationMs,
            memoryBytes: 0,
            fileCount: 0,
            matchCount: 0,
            skipped: !$result->successful(),
            skipReason: $result->successful() ? null : trim($result->output()),
        );
    }

    /**
     * @return array<string, string>
     */
    private function corpora(): array
    {
        return [
            'synthetic-1k' => $this->rootPath . '/benchmarks/corpora/synthetic/1k-files',
            'synthetic-10k' => $this->rootPath . '/benchmarks/corpora/synthetic/10k-files',
            'synthetic-100k-single' => $this->rootPath . '/benchmarks/corpora/synthetic/100k-lines-single',
            'wordpress' => $this->rootPath . '/benchmarks/corpora/wordpress',
            'laravel' => $this->rootPath . '/benchmarks/corpora/laravel',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suites(): array
    {
        $suites = [];

        foreach (glob($this->rootPath . '/benchmarks/suites/*.php') ?: [] as $suitePath) {
            /** @var list<array<string, mixed>> $suiteDefinitions */
            $suiteDefinitions = require $suitePath;

            foreach ($suiteDefinitions as $suiteDefinition) {
                $suites[] = $suiteDefinition;
            }
        }

        usort(
            $suites,
            fn (array $left, array $right): int => $this->compareSuites($left, $right),
        );

        return $suites;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compareSuites(array $left, array $right): int
    {
        $priority = [
            'text' => 10,
            'walker' => 20,
            'parallel' => 30,
            'ast' => 40,
            'ast-internal' => 50,
            'ast-parse' => 60,
            'indexed-load' => 70,
            'indexed-summary' => 80,
            'indexed-text' => 90,
            'indexed-text-cold' => 100,
            'indexed-text-many' => 110,
            'indexed-set-text' => 120,
            'ast-indexed' => 130,
            'ast-cached' => 140,
            'ast-indexed-set' => 150,
            'ast-cached-set' => 160,
            'indexed-build' => 170,
            'indexed-refresh' => 180,
            'ast-indexed-build' => 190,
            'ast-indexed-refresh' => 200,
            'ast-cached-build' => 210,
            'ast-cached-refresh' => 220,
        ];

        $leftPriority = $priority[(string) ($left['category'] ?? '')] ?? 999;
        $rightPriority = $priority[(string) ($right['category'] ?? '')] ?? 999;

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return [(string) ($left['name'] ?? ''), (string) ($left['pattern'] ?? '')]
            <=> [(string) ($right['name'] ?? ''), (string) ($right['pattern'] ?? '')];
    }

    /**
     * @param array<string, mixed> $suite
     */
    private function textIndexPath(array $suite, string $corpusName): string
    {
        $mode = match ((string) ($suite['category'] ?? '')) {
            'indexed-build' => 'build',
            'indexed-text-cold' => 'cold',
            'indexed-text-many' => 'many',
            'indexed-set-text' => 'set',
            'indexed-refresh' => 'refresh',
            default => 'runtime',
        };

        return $this->rootPath . '/build/benchmarks/indexes/' . $mode . '/' . $corpusName;
    }

    /**
     * @param array<string, mixed> $suite
     */
    private function astIndexPath(array $suite, string $corpusName): string
    {
        $mode = match ((string) ($suite['category'] ?? '')) {
            'ast-indexed-build' => 'build',
            'ast-indexed-refresh' => 'refresh',
            default => 'runtime',
        };

        return $this->rootPath . '/build/benchmarks/ast-indexes/' . $mode . '/' . $corpusName;
    }

    /**
     * @param array<string, mixed> $suite
     */
    private function astCachePath(array $suite, string $corpusName): string
    {
        $mode = match ((string) ($suite['category'] ?? '')) {
            'ast-cached-build' => 'build',
            'ast-cached-refresh' => 'refresh',
            default => 'runtime',
        };

        return $this->rootPath . '/build/benchmarks/ast-caches/' . $mode . '/' . $corpusName;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function preparePartitionedTextWorkspace(string $corpusPath, string $corpusName): array
    {
        $workspacePath = $this->preparePartitionedWorkspace($corpusPath, $corpusName);
        $store = new TextIndexStore();
        $indexPaths = [
            $store->defaultPath($workspacePath . '/part-a'),
            $store->defaultPath($workspacePath . '/part-b'),
        ];

        if (!$store->exists($indexPaths[0])) {
            (new TextIndexBuilder())->build($workspacePath . '/part-a', $indexPaths[0]);
        }

        if (!$store->exists($indexPaths[1])) {
            (new TextIndexBuilder())->build($workspacePath . '/part-b', $indexPaths[1]);
        }

        return [$workspacePath, $indexPaths];
    }

    /**
     * @return array{workspace: string, manifest: string}
     */
    private function preparePartitionedIndexSet(
        string $corpusPath,
        string $corpusName,
        bool $includeText = false,
        bool $includeAstIndex = false,
        bool $includeAstCache = false,
    ): array
    {
        $workspacePath = $this->preparePartitionedWorkspace($corpusPath, $corpusName);
        $textStore = new TextIndexStore();
        $astIndexStore = new AstIndexStore();
        $astCacheStore = new AstCacheStore();

        foreach ([$workspacePath . '/part-a', $workspacePath . '/part-b'] as $partRoot) {
            $textIndexPath = $textStore->defaultPath($partRoot);
            $astIndexPath = $astIndexStore->defaultPath($partRoot);
            $astCachePath = $astCacheStore->defaultPath($partRoot);

            if ($includeText && !$textStore->exists($textIndexPath)) {
                (new TextIndexBuilder())->build($partRoot, $textIndexPath);
            }

            if ($includeAstIndex && !$astIndexStore->exists($astIndexPath)) {
                (new AstIndexBuilder())->build($partRoot, $astIndexPath);
            }

            if ($includeAstCache && !$astCacheStore->exists($astCachePath)) {
                (new AstCacheBuilder())->build($partRoot, $astCachePath);
            }
        }

        return [
            'workspace' => $workspacePath,
            'manifest' => $this->preparePartitionedIndexSetManifest($workspacePath),
        ];
    }

    private function preparePartitionedWorkspace(string $corpusPath, string $corpusName): string
    {
        $workspacePath = $this->rootPath . '/build/benchmarks/index-sets/' . $corpusName . '/workspace';
        $readyMarker = dirname($workspacePath) . '/.ready';

        if (is_file($readyMarker)) {
            return $workspacePath;
        }

        Filesystem::remove(dirname($workspacePath));
        Filesystem::ensureDirectory($workspacePath . '/part-a');
        Filesystem::ensureDirectory($workspacePath . '/part-b');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($corpusPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }

            $absolutePath = Filesystem::normalizePath($entry->getPathname());
            $normalizedCorpusPath = Filesystem::normalizePath($corpusPath);
            $relativePath = ltrim(substr($absolutePath, strlen($normalizedCorpusPath)), '/');
            $bucket = (crc32($relativePath) % 2) === 0 ? 'part-a' : 'part-b';
            $targetPath = $workspacePath . '/' . $bucket . '/' . $relativePath;
            Filesystem::ensureDirectory(dirname($targetPath));

            if (@copy($absolutePath, $targetPath) === false) {
                throw new \RuntimeException(sprintf('Failed to copy benchmark file: %s', $absolutePath));
            }
        }

        if (@file_put_contents($readyMarker, "ready\n") === false) {
            throw new \RuntimeException(sprintf('Failed to write benchmark workspace marker: %s', $readyMarker));
        }

        return $workspacePath;
    }

    private function preparePartitionedIndexSetManifest(string $workspacePath): string
    {
        $manifestPath = $workspacePath . '/.greph-index-set.json';

        if (is_file($manifestPath)) {
            return $manifestPath;
        }

        $payload = [
            'name' => 'benchmark-set',
            'indexes' => [
                ['name' => 'part-a-text', 'root' => 'part-a', 'mode' => 'text', 'lifecycle' => 'static', 'priority' => 20],
                ['name' => 'part-b-text', 'root' => 'part-b', 'mode' => 'text', 'lifecycle' => 'opportunistic-refresh', 'priority' => 10],
                ['name' => 'part-a-ast', 'root' => 'part-a', 'mode' => 'ast-index', 'lifecycle' => 'static', 'priority' => 20],
                ['name' => 'part-b-ast', 'root' => 'part-b', 'mode' => 'ast-index', 'lifecycle' => 'opportunistic-refresh', 'priority' => 10],
                ['name' => 'part-a-cache', 'root' => 'part-a', 'mode' => 'ast-cache', 'lifecycle' => 'static', 'priority' => 20],
                ['name' => 'part-b-cache', 'root' => 'part-b', 'mode' => 'ast-cache', 'lifecycle' => 'opportunistic-refresh', 'priority' => 10],
            ],
        ];

        if (@file_put_contents($manifestPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException(sprintf('Failed to write benchmark index-set manifest: %s', $manifestPath));
        }

        return $manifestPath;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function prepareTextRefreshWorkspace(string $corpusPath, string $corpusName): array
    {
        $workspace = $this->prepareRefreshWorkspace($corpusPath, 'indexed-refresh', $corpusName);
        $indexPath = $this->textIndexPath(['category' => 'indexed-refresh'], $corpusName);
        Filesystem::remove($indexPath);
        (new TextIndexBuilder())->build($workspace, $indexPath);

        return [$workspace, $indexPath];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function prepareAstIndexRefreshWorkspace(string $corpusPath, string $corpusName): array
    {
        $workspace = $this->prepareRefreshWorkspace($corpusPath, 'ast-indexed-refresh', $corpusName);
        $indexPath = $this->astIndexPath(['category' => 'ast-indexed-refresh'], $corpusName);
        Filesystem::remove($indexPath);
        (new AstIndexBuilder())->build($workspace, $indexPath);

        return [$workspace, $indexPath];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function prepareAstCacheRefreshWorkspace(string $corpusPath, string $corpusName): array
    {
        $workspace = $this->prepareRefreshWorkspace($corpusPath, 'ast-cached-refresh', $corpusName);
        $indexPath = $this->astCachePath(['category' => 'ast-cached-refresh'], $corpusName);
        Filesystem::remove($indexPath);
        (new AstCacheBuilder())->build($workspace, $indexPath);

        return [$workspace, $indexPath];
    }

    private function prepareRefreshWorkspace(string $corpusPath, string $category, string $corpusName): string
    {
        $workspace = $this->rootPath
            . '/build/benchmarks/refresh-workspaces/'
            . $category
            . '/'
            . $corpusName
            . '/'
            . str_replace('.', '-', uniqid('', true));

        Filesystem::copyDirectory($corpusPath, $workspace);

        return $workspace;
    }

    private function applyRefreshMutations(string $corpusPath): void
    {
        $phpFiles = $this->refreshCandidatePhpFiles($corpusPath);

        if (count($phpFiles) < self::REFRESH_DIRTY_FILE_COUNT) {
            throw new \RuntimeException(sprintf(
                'Refresh benchmark corpus needs at least %d PHP files: %s',
                self::REFRESH_DIRTY_FILE_COUNT,
                $corpusPath,
            ));
        }

        $this->mutateRefreshFile($phpFiles[0]);
        if (!@unlink($phpFiles[1])) {
            throw new \RuntimeException(sprintf('Failed to delete refresh benchmark file: %s', $phpFiles[1]));
        }
        $renamedPath = dirname($phpFiles[2]) . '/' . pathinfo($phpFiles[2], PATHINFO_FILENAME) . '-refresh-renamed.php';
        if (!@rename($phpFiles[2], $renamedPath)) {
            throw new \RuntimeException(sprintf('Failed to rename refresh benchmark file: %s', $phpFiles[2]));
        }

        if (@file_put_contents(
            $corpusPath . '/greph_refresh_benchmark.php',
            "<?php\n\nfunction greph_refresh_benchmark_fixture(): string\n{\n    return 'refresh';\n}\n",
        ) === false) {
            throw new \RuntimeException(sprintf('Failed to add refresh benchmark file under: %s', $corpusPath));
        }
    }

    /**
     * @return list<string>
     */
    private function refreshCandidatePhpFiles(string $corpusPath): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($corpusPath, \FilesystemIterator::SKIP_DOTS),
        );
        $paths = [];

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }

            $path = Filesystem::normalizePath($entry->getPathname());

            if (!str_ends_with($path, '.php') || str_contains($path, '/.greph-')) {
                continue;
            }

            $paths[] = $path;
        }

        sort($paths);

        return $paths;
    }

    private function mutateRefreshFile(string $path): void
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Failed to read refresh benchmark file: %s', $path));
        }

        if (str_contains($contents, '<?php')) {
            $updated = preg_replace('/<\\?php/', "<?php\n/* greph refresh benchmark */", $contents, 1);
            if (@file_put_contents($path, $updated === null ? $contents : $updated) === false) {
                throw new \RuntimeException(sprintf('Failed to update refresh benchmark file: %s', $path));
            }

            return;
        }

        if (@file_put_contents($path, $contents . "\n<?php\n/* greph refresh benchmark */\n") === false) {
            throw new \RuntimeException(sprintf('Failed to update refresh benchmark file: %s', $path));
        }
    }

    /**
     * @param array<string, mixed> $suite
     * @return list<string>|null
     */
    private function externalGrepCommand(array $suite, string $corpusPath): ?array
    {
        if (!in_array($suite['category'], ['text', 'walker', 'parallel', 'indexed-text', 'indexed-text-cold', 'indexed-summary'], true)) {
            return null;
        }

        if ($suite['category'] === 'walker') {
            return array_merge($this->toolResolver->grep(), ['-r', '-l', '.', $corpusPath]);
        }

        $command = array_merge($this->toolResolver->grep(), ['-r', '-n']);

        if (($suite['fixed'] ?? false) === true) {
            $command[] = '-F';
        } else {
            $command[] = '-E';
        }

        if (($suite['case_insensitive'] ?? false) === true) {
            $command[] = '-i';
        }

        if (($suite['whole_word'] ?? false) === true) {
            $command[] = '-w';
        }

        if (($suite['count_only'] ?? false) === true) {
            $command[] = '-c';
        }

        if (($suite['files_with_matches'] ?? false) === true) {
            $command[] = '-l';
        }

        if (($suite['files_without_matches'] ?? false) === true) {
            $command[] = '-L';
        }

        if (($suite['quiet'] ?? false) === true) {
            $command[] = '-q';
        }

        return array_merge($command, [(string) $suite['pattern'], $corpusPath]);
    }

    /**
     * @param array<string, mixed> $suite
     * @return list<string>|null
     */
    private function externalRipgrepCommand(array $suite, string $corpusPath): ?array
    {
        if (!in_array($suite['category'], ['text', 'walker', 'parallel', 'indexed-text', 'indexed-text-cold', 'indexed-summary'], true)) {
            return null;
        }

        if ($suite['category'] === 'walker') {
            return array_merge($this->toolResolver->ripgrep(), ['--files', $corpusPath]);
        }

        $command = array_merge($this->toolResolver->ripgrep(), ['-n', '--color', 'never']);

        if (($suite['fixed'] ?? false) === true) {
            $command[] = '-F';
        }

        if (($suite['case_insensitive'] ?? false) === true) {
            $command[] = '-i';
        }

        if (($suite['whole_word'] ?? false) === true) {
            $command[] = '-w';
        }

        if (($suite['count_only'] ?? false) === true) {
            $command[] = '-c';
        }

        if (($suite['files_with_matches'] ?? false) === true) {
            $command[] = '-l';
        }

        if (($suite['files_without_matches'] ?? false) === true) {
            $command[] = '-L';
        }

        if (($suite['quiet'] ?? false) === true) {
            $command[] = '-q';
        }

        return array_merge($command, [(string) $suite['pattern'], $corpusPath]);
    }

    /**
     * @param array<string, mixed> $suite
     * @return list<string>|null
     */
    private function externalAstGrepCommand(array $suite, string $corpusPath): ?array
    {
        if (!in_array($suite['category'], ['ast', 'ast-indexed', 'ast-cached'], true)) {
            return null;
        }

        return array_merge(
            $this->toolResolver->astGrep(),
            ['run', '--lang', (string) ($suite['lang'] ?? 'php'), '-p', (string) $suite['pattern'], $corpusPath],
        );
    }
}
