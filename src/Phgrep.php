<?php

declare(strict_types=1);

namespace Phgrep;

use Phgrep\Ast\AstMatch;
use Phgrep\Ast\AstRewriter;
use Phgrep\Ast\AstSearchOptions;
use Phgrep\Ast\AstSearcher;
use Phgrep\Index\AstIndexBuildResult;
use Phgrep\Index\AstIndexBuilder;
use Phgrep\Index\IndexBuildResult;
use Phgrep\Index\IndexedAstSearcher;
use Phgrep\Index\IndexedTextSearcher;
use Phgrep\Index\TextIndexBuilder;
use Phgrep\Ast\RewriteResult;
use Phgrep\Parallel\WorkSplitter;
use Phgrep\Parallel\WorkerPool;
use Phgrep\Text\TextFileResult;
use Phgrep\Text\TextResultCodec;
use Phgrep\Text\TextSearcher;
use Phgrep\Text\TextSearchOptions;
use Phgrep\Walker\FileList;
use Phgrep\Walker\FileWalker;
use Phgrep\Walker\WalkOptions;

final class Phgrep
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

        if (!self::shouldUseWorkers($options->jobs, count($files))) {
            return $searcher->searchFiles($files, $pattern, $options);
        }

        $chunks = (new WorkSplitter())->split($files, $options->jobs);
        $results = (new WorkerPool())->map(
            $chunks,
            static fn (FileList $chunk): array => $searcher->searchFiles($chunk, $pattern, $options),
            $options->jobs,
            static function (mixed $chunkResults) use ($codec): array {
                if (!is_array($chunkResults)) {
                    throw new \RuntimeException('Worker returned invalid text result set.');
                }

                /** @var list<TextFileResult> $chunkResults */
                return $codec->encode($chunkResults);
            },
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

    public static function buildTextIndex(string $rootPath = '.', ?string $indexPath = null): IndexBuildResult
    {
        return (new TextIndexBuilder())->build($rootPath, $indexPath);
    }

    public static function refreshTextIndex(string $rootPath = '.', ?string $indexPath = null): IndexBuildResult
    {
        return (new TextIndexBuilder())->refresh($rootPath, $indexPath);
    }

    public static function buildAstIndex(string $rootPath = '.', ?string $indexPath = null): AstIndexBuildResult
    {
        return (new AstIndexBuilder())->build($rootPath, $indexPath);
    }

    public static function refreshAstIndex(string $rootPath = '.', ?string $indexPath = null): AstIndexBuildResult
    {
        return (new AstIndexBuilder())->refresh($rootPath, $indexPath);
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
     * @return list<AstMatch>
     */
    public static function searchAst(string $pattern, string|array $paths, ?AstSearchOptions $options = null): array
    {
        $options ??= new AstSearchOptions();
        $files = self::walk($paths, $options->walkOptions());
        $searcher = new AstSearcher();

        if (!self::shouldUseWorkers($options->jobs, count($files))) {
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

        if (!self::shouldUseWorkers($options->jobs, count($files))) {
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

    private static function shouldUseWorkers(int $jobs, int $fileCount): bool
    {
        return $jobs > 1 && $fileCount > ($jobs * 750);
    }
}
