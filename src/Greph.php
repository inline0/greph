<?php

declare(strict_types=1);

namespace Greph;

use Greph\Ast\AstMatch;
use Greph\Ast\AstRewriter;
use Greph\Ast\AstSearchOptions;
use Greph\Ast\AstSearcher;
use Greph\Index\AstCacheBuildResult;
use Greph\Index\AstCacheBuilder;
use Greph\Index\AstIndexBuildResult;
use Greph\Index\AstIndexBuilder;
use Greph\Index\CachedAstSearcher;
use Greph\Index\IndexLifecycle;
use Greph\Index\IndexLifecycleProfile;
use Greph\Index\IndexBuildResult;
use Greph\Index\IndexedAstSearcher;
use Greph\Index\IndexedTextSearcher;
use Greph\Index\TextIndexBuilder;
use Greph\Ast\RewriteResult;
use Greph\Parallel\WorkSplitter;
use Greph\Parallel\WorkerPool;
use Greph\Text\TextFileResult;
use Greph\Text\TextResultCodec;
use Greph\Text\TextSearcher;
use Greph\Text\TextSearchOptions;
use Greph\Walker\FileList;
use Greph\Walker\FileWalker;
use Greph\Walker\WalkOptions;

final class Greph
{
    /**
     * @param string|list<string> $paths
     */
    public static function walk(string|array $paths, ?WalkOptions $options = null): FileList
    {
        return (new FileWalker())->walk($paths, $options);
    }

    /**
     * @param string|list<string> $paths
     * @return list<TextFileResult>
     */
    public static function searchText(string $pattern, string|array $paths, ?TextSearchOptions $options = null): array
    {
        $options ??= new TextSearchOptions();
        $files = self::walk($paths, $options->walkOptions());
        $searcher = new TextSearcher();
        $codec = new TextResultCodec();

        if (!self::shouldUseTextWorkers($pattern, $options, count($files))) {
            return $searcher->searchFiles($files, $pattern, $options);
        }

        $chunks = (new WorkSplitter())->split($files, $options->jobs);
        $results = (new WorkerPool())->map(
            $chunks,
            static fn (FileList $chunk): array => $searcher->searchFiles($chunk, $pattern, $options),
            $options->jobs,
            static fn (mixed $chunkResults): array => self::encodeTextWorkerResults($chunkResults, $codec),
            static fn (mixed $payload): array => $codec->decode($payload),
        );

        $flattened = [];

        foreach ($results as $chunkResults) {
            foreach ($chunkResults as $result) {
                $flattened[] = $result;
            }
        }

        return $searcher->sortResults($flattened, $files->paths());
    }

    public static function buildTextIndex(
        string $rootPath = '.',
        ?string $indexPath = null,
        IndexLifecycle|IndexLifecycleProfile|string|null $lifecycle = null,
    ): IndexBuildResult {
        return (new TextIndexBuilder())->build($rootPath, $indexPath, $lifecycle);
    }

    public static function refreshTextIndex(
        string $rootPath = '.',
        ?string $indexPath = null,
        IndexLifecycle|IndexLifecycleProfile|string|null $lifecycle = null,
    ): IndexBuildResult {
        return (new TextIndexBuilder())->refresh($rootPath, $indexPath, $lifecycle);
    }

    public static function buildAstIndex(
        string $rootPath = '.',
        ?string $indexPath = null,
        IndexLifecycle|IndexLifecycleProfile|string|null $lifecycle = null,
    ): AstIndexBuildResult {
        return (new AstIndexBuilder())->build($rootPath, $indexPath, $lifecycle);
    }

    public static function refreshAstIndex(
        string $rootPath = '.',
        ?string $indexPath = null,
        IndexLifecycle|IndexLifecycleProfile|string|null $lifecycle = null,
    ): AstIndexBuildResult {
        return (new AstIndexBuilder())->refresh($rootPath, $indexPath, $lifecycle);
    }

    public static function buildAstCache(
        string $rootPath = '.',
        ?string $indexPath = null,
        IndexLifecycle|IndexLifecycleProfile|string|null $lifecycle = null,
    ): AstCacheBuildResult {
        return (new AstCacheBuilder())->build($rootPath, $indexPath, $lifecycle);
    }

    public static function refreshAstCache(
        string $rootPath = '.',
        ?string $indexPath = null,
        IndexLifecycle|IndexLifecycleProfile|string|null $lifecycle = null,
    ): AstCacheBuildResult {
        return (new AstCacheBuilder())->refresh($rootPath, $indexPath, $lifecycle);
    }

    /**
     * @param string|list<string> $paths
     * @return list<TextFileResult>
     */
    public static function searchTextIndexed(
        string $pattern,
        string|array $paths,
        ?TextSearchOptions $options = null,
        ?string $indexPath = null,
    ): array {
        $options ??= new TextSearchOptions();

        return (new IndexedTextSearcher())->search($pattern, $paths, $options, $indexPath);
    }

    /**
     * @param string|list<string> $paths
     * @param list<string> $indexPaths
     * @return list<TextFileResult>
     */
    public static function searchTextIndexedMany(
        string $pattern,
        string|array $paths,
        array $indexPaths,
        ?TextSearchOptions $options = null,
    ): array {
        $options ??= new TextSearchOptions();

        return (new IndexedTextSearcher())->searchMany($pattern, $paths, $options, $indexPaths);
    }

    /**
     * @param string|list<string> $paths
     * @return list<AstMatch>
     */
    public static function searchAst(string $pattern, string|array $paths, ?AstSearchOptions $options = null): array
    {
        $options ??= new AstSearchOptions();
        $files = self::walk($paths, $options->walkOptions());
        $searcher = new AstSearcher();

        if (!self::shouldUseAstWorkers($options->jobs, count($files))) {
            return $searcher->searchFiles($files, $pattern, $options);
        }

        $chunks = (new WorkSplitter())->split($files, $options->jobs);
        $results = (new WorkerPool())->map(
            $chunks,
            static fn (FileList $chunk): array => $searcher->searchFiles($chunk, $pattern, $options),
            $options->jobs,
        );

        $flattened = [];

        foreach ($results as $chunkResults) {
            foreach ($chunkResults as $result) {
                $flattened[] = $result;
            }
        }

        usort(
            $flattened,
            static fn (AstMatch $left, AstMatch $right): int => [$left->file, $left->startFilePos] <=> [$right->file, $right->startFilePos]
        );

        return $flattened;
    }

    /**
     * @param string|list<string> $paths
     * @return list<AstMatch>
     */
    public static function searchAstIndexed(
        string $pattern,
        string|array $paths,
        ?AstSearchOptions $options = null,
        ?string $indexPath = null,
    ): array {
        $options ??= new AstSearchOptions();

        return (new IndexedAstSearcher())->search($pattern, $paths, $options, $indexPath);
    }

    /**
     * @param string|list<string> $paths
     * @param list<string> $indexPaths
     * @return list<AstMatch>
     */
    public static function searchAstIndexedMany(
        string $pattern,
        string|array $paths,
        array $indexPaths,
        ?AstSearchOptions $options = null,
    ): array {
        $options ??= new AstSearchOptions();

        return (new IndexedAstSearcher())->searchMany($pattern, $paths, $options, $indexPaths);
    }

    /**
     * @param string|list<string> $paths
     * @return list<AstMatch>
     */
    public static function searchAstCached(
        string $pattern,
        string|array $paths,
        ?AstSearchOptions $options = null,
        ?string $indexPath = null,
    ): array {
        $options ??= new AstSearchOptions();

        return (new CachedAstSearcher())->search($pattern, $paths, $options, $indexPath);
    }

    /**
     * @param string|list<string> $paths
     * @param list<string> $indexPaths
     * @return list<AstMatch>
     */
    public static function searchAstCachedMany(
        string $pattern,
        string|array $paths,
        array $indexPaths,
        ?AstSearchOptions $options = null,
    ): array {
        $options ??= new AstSearchOptions();

        return (new CachedAstSearcher())->searchMany($pattern, $paths, $options, $indexPaths);
    }

    /**
     * @param string|list<string> $paths
     * @return list<RewriteResult>
     */
    public static function rewriteAst(
        string $searchPattern,
        string $rewritePattern,
        string|array $paths,
        ?AstSearchOptions $options = null,
    ): array {
        $options ??= new AstSearchOptions();
        $files = self::walk($paths, $options->walkOptions());
        $rewriter = new AstRewriter();

        if (!self::shouldUseRewriteWorkers($options->jobs, count($files))) {
            return $rewriter->rewriteFiles($files, $searchPattern, $rewritePattern, $options);
        }

        $chunks = (new WorkSplitter())->split($files, $options->jobs);
        $results = (new WorkerPool())->map(
            $chunks,
            static fn (FileList $chunk): array => $rewriter->rewriteFiles($chunk, $searchPattern, $rewritePattern, $options),
            $options->jobs,
        );

        $flattened = [];

        foreach ($results as $chunkResults) {
            foreach ($chunkResults as $result) {
                $flattened[] = $result;
            }
        }

        usort(
            $flattened,
            static fn (RewriteResult $left, RewriteResult $right): int => strcmp($left->file, $right->file)
        );

        return $flattened;
    }

    private static function shouldUseTextWorkers(string $pattern, TextSearchOptions $options, int $fileCount): bool
    {
        if ($options->jobs <= 1 || $options->quiet) {
            return false;
        }

        $threshold = $options->jobs * 750;

        if (!$options->countOnly && !$options->filesWithMatches && !$options->filesWithoutMatches) {
            $threshold = $options->jobs * 2_000;
        }

        if (
            $options->fixedString
            && !$options->caseInsensitive
            && !$options->wholeWord
            && !$options->invertMatch
            && $options->beforeContext === 0
            && $options->afterContext === 0
            && $options->maxCount === null
            && strlen($pattern) <= 8
            && preg_match('/^[A-Za-z0-9_]+$/', $pattern) === 1
        ) {
            $threshold = $options->jobs * 4_000;
        }

        return $fileCount > $threshold;
    }

    /**
     * @param list<TextFileResult> $chunkResults
     * @return list<array<string, mixed>>
     */
    private static function encodeTextWorkerResults(mixed $chunkResults, TextResultCodec $codec): array
    {
        if (!is_array($chunkResults)) {
            throw new \RuntimeException('Worker returned invalid text result set.');
        }

        return $codec->encode($chunkResults);
    }

    private static function shouldUseAstWorkers(int $jobs, int $fileCount): bool
    {
        return $jobs > 1 && $fileCount > ($jobs * 750);
    }

    private static function shouldUseRewriteWorkers(int $jobs, int $fileCount): bool
    {
        return $jobs > 1 && $fileCount > ($jobs * 750);
    }
}
