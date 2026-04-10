<?php

declare(strict_types=1);

namespace Phgrep\Index;

use Phgrep\Ast\AstMatch;
use Phgrep\Ast\AstSearchOptions;
use Phgrep\Ast\AstSearcher;
use Phgrep\Ast\Pattern;
use Phgrep\Ast\PatternParser;
use Phgrep\Support\Filesystem;
use Phgrep\Walker\FileList;

final class IndexedAstSearcher
{
    private AstIndexStore $store;

    private AstSearcher $astSearcher;

    private PatternParser $patternParser;

    private AstFactQuery $factQuery;

    private AstQueryCacheStore $queryCacheStore;

    public function __construct(
        ?AstIndexStore $store = null,
        ?AstSearcher $astSearcher = null,
        ?PatternParser $patternParser = null,
        ?AstFactQuery $factQuery = null,
        ?AstQueryCacheStore $queryCacheStore = null,
    ) {
        $this->store = $store ?? new AstIndexStore();
        $this->astSearcher = $astSearcher ?? new AstSearcher();
        $this->patternParser = $patternParser ?? new PatternParser();
        $this->factQuery = $factQuery ?? new AstFactQuery();
        $this->queryCacheStore = $queryCacheStore ?? new AstQueryCacheStore();
    }

    /**
     * @param string|list<string> $paths
     * @return list<AstMatch>
     */
    public function search(string $pattern, string|array $paths, AstSearchOptions $options, ?string $indexPath = null): array
    {
        $paths = is_array($paths) ? $paths : [$paths];
        $resolvedPaths = $this->resolvePaths($paths);
        $indexPath = $this->resolveIndexPath($resolvedPaths, $indexPath);

        if ($indexPath === null) {
            throw new \RuntimeException('No AST index found for the requested paths. Build one first.');
        }

        $index = $this->store->load($indexPath);
        $parsedPattern = $this->patternParser->parse($pattern, $options->language);
        $selection = $this->buildSelection($resolvedPaths, $index->rootPath);
        $selectedPaths = [];
        $selectedPathSet = [];
        $fallbackPaths = [];
        $explicitSelections = [];

        foreach ($resolvedPaths as $path) {
            if ($this->isWithinRoot($path, $index->rootPath)) {
                if (is_file($path)) {
                    $explicitSelections[$path] = true;
                }

                continue;
            }

            $fallbackPaths[] = $path;
        }

        foreach ($index->files as $file) {
            $absolutePath = $index->rootPath . '/' . $file['p'];

            if (!$this->matchesSelection($absolutePath, $selection)) {
                continue;
            }

            if (
                !isset($explicitSelections[$absolutePath])
                && !$this->matchesQueryFilters($file, $absolutePath, $index->rootPath, $options)
            ) {
                continue;
            }

            $selectedPaths[] = $absolutePath;
            $selectedPathSet[$absolutePath] = true;
        }

        foreach (array_keys($explicitSelections) as $explicitPath) {
            if (!isset($selectedPathSet[$explicitPath])) {
                $fallbackPaths[] = $explicitPath;
            }
        }

        if ($this->canUseQueryCache($resolvedPaths, $index->rootPath, $options, $explicitSelections, $fallbackPaths)) {
            $cachedMatches = $this->queryCacheStore->load($index->indexPath, $index->builtAt, $pattern, $options);

            if ($cachedMatches !== null) {
                if ($this->canPopulateQueryCache($resolvedPaths, $index->rootPath, $options, $explicitSelections, $fallbackPaths)) {
                    return $cachedMatches;
                }

                return $this->filterCachedMatches($cachedMatches, $selection);
            }
        }

        $candidateIds = $this->candidateIds($index, $parsedPattern);
        $candidatePaths = [];

        if ($candidateIds === null) {
            $candidatePaths = $selectedPaths;
        } else {
            foreach ($index->files as $file) {
                $absolutePath = $index->rootPath . '/' . $file['p'];

                if (!isset($selectedPathSet[$absolutePath]) || !isset($candidateIds[$file['id']])) {
                    continue;
                }

                $candidatePaths[] = $absolutePath;
            }
        }

        $matches = [];

        foreach ($this->astSearcher->searchFiles(new FileList($candidatePaths), $pattern, $options) as $match) {
            $matches[] = $match;
        }

        if ($fallbackPaths !== []) {
            foreach ($this->astSearcher->searchFiles(new FileList($fallbackPaths), $pattern, $options) as $match) {
                $matches[] = $match;
            }
        }

        usort(
            $matches,
            static fn (AstMatch $left, AstMatch $right): int => [$left->file, $left->startFilePos] <=> [$right->file, $right->startFilePos]
        );

        if ($this->canPopulateQueryCache($resolvedPaths, $index->rootPath, $options, $explicitSelections, $fallbackPaths)) {
            $this->queryCacheStore->save($index->indexPath, $index->builtAt, $pattern, $options, $matches);
        }

        return $matches;
    }

    /**
     * @param list<AstMatch> $matches
     * @param array{files: array<string, true>, directories: list<string>} $selection
     * @return list<AstMatch>
     */
    private function filterCachedMatches(array $matches, array $selection): array
    {
        $filtered = [];

        foreach ($matches as $match) {
            if ($this->matchesSelection($match->file, $selection)) {
                $filtered[] = $match;
            }
        }

        return $filtered;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function resolvePaths(array $paths): array
    {
        $resolvedPaths = [];

        foreach ($paths as $path) {
            $resolvedPath = realpath($path);

            if ($resolvedPath === false) {
                throw new \RuntimeException(sprintf('Path does not exist: %s', $path));
            }

            $resolvedPaths[] = Filesystem::normalizePath($resolvedPath);
        }

        return $resolvedPaths;
    }

    /**
     * @param list<string> $resolvedPaths
     */
    private function resolveIndexPath(array $resolvedPaths, ?string $indexPath): ?string
    {
        if ($indexPath !== null && $indexPath !== '') {
            return Filesystem::normalizePath($indexPath);
        }

        foreach ($resolvedPaths as $path) {
            $located = $this->store->locateFrom($path);

            if ($located !== null) {
                return $located;
            }
        }

        return null;
    }

    /**
     * @param array{files: array<string, true>, directories: list<string>} $selection
     */
    private function matchesSelection(string $absolutePath, array $selection): bool
    {
        if (isset($selection['files'][$absolutePath])) {
            return true;
        }

        foreach ($selection['directories'] as $path) {
            if ($absolutePath === $path || str_starts_with($absolutePath, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $resolvedPaths
     * @return array{files: array<string, true>, directories: list<string>}
     */
    private function buildSelection(array $resolvedPaths, string $rootPath): array
    {
        $selection = [
            'files' => [],
            'directories' => [],
        ];

        foreach ($resolvedPaths as $path) {
            if (!$this->isWithinRoot($path, $rootPath)) {
                continue;
            }

            if (is_file($path)) {
                $selection['files'][$path] = true;
                continue;
            }

            $selection['directories'][] = $path;
        }

        return $selection;
    }

    private function isWithinRoot(string $path, string $rootPath): bool
    {
        return $path === $rootPath || str_starts_with($path, $rootPath . '/');
    }

    /**
     * @param list<string> $resolvedPaths
     * @param array<string, true> $explicitSelections
     * @param list<string> $fallbackPaths
     */
    private function canUseQueryCache(
        array $resolvedPaths,
        string $rootPath,
        AstSearchOptions $options,
        array $explicitSelections,
        array $fallbackPaths,
    ): bool {
        if (!$this->supportsQueryCache($options) || $explicitSelections !== [] || $fallbackPaths !== []) {
            return false;
        }

        foreach ($resolvedPaths as $path) {
            if (!$this->isWithinRoot($path, $rootPath)) {
                return false;
            }
        }

        return $resolvedPaths !== [];
    }

    /**
     * @param list<string> $resolvedPaths
     * @param array<string, true> $explicitSelections
     * @param list<string> $fallbackPaths
     */
    private function canPopulateQueryCache(
        array $resolvedPaths,
        string $rootPath,
        AstSearchOptions $options,
        array $explicitSelections,
        array $fallbackPaths,
    ): bool {
        return $this->supportsQueryCache($options)
            && $explicitSelections === []
            && $fallbackPaths === []
            && count($resolvedPaths) === 1
            && $resolvedPaths[0] === $rootPath;
    }

    private function supportsQueryCache(AstSearchOptions $options): bool
    {
        return $options->language === 'php'
            && $options->respectIgnore
            && !$options->includeHidden
            && !$options->followSymlinks
            && $options->skipBinaryFiles
            && !$options->includeGitDirectory
            && $options->fileTypeFilter === null
            && $options->maxFileSizeBytes === 10485760
            && $options->globPatterns === []
            && $options->skipParseErrors
            && !$options->dryRun
            && !$options->interactive;
    }

    /**
     * @param array{id: int, p: string, s: int, m: int, h: bool, g: bool, o: int} $file
     */
    private function matchesQueryFilters(array $file, string $absolutePath, string $rootPath, AstSearchOptions $options): bool
    {
        if (!$options->includeHidden && $file['h']) {
            return false;
        }

        if ($options->respectIgnore && $file['g']) {
            return false;
        }

        if ($options->maxFileSizeBytes > 0 && $file['s'] > $options->maxFileSizeBytes) {
            return false;
        }

        if ($options->fileTypeFilter !== null && !$options->fileTypeFilter->matches($absolutePath)) {
            return false;
        }

        return $this->matchesGlobPatterns($absolutePath, $rootPath, $options->globPatterns);
    }

    /**
     * @param list<string> $globPatterns
     */
    private function matchesGlobPatterns(string $path, string $rootPath, array $globPatterns): bool
    {
        if ($globPatterns === []) {
            return true;
        }

        $path = Filesystem::normalizePath($path);
        $rootPath = Filesystem::normalizePath($rootPath);
        $relativePath = $path;

        if (str_starts_with($path, $rootPath . '/')) {
            $relativePath = substr($path, strlen($rootPath) + 1);
        } elseif ($path === $rootPath) {
            $relativePath = basename($path);
        }

        $basename = basename($path);

        foreach ($globPatterns as $pattern) {
            $pattern = str_replace('\\', '/', $pattern);

            if (
                fnmatch($pattern, $basename)
                || fnmatch($pattern, $relativePath, FNM_PATHNAME)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, true>|null
     */
    private function candidateIds(AstIndex $index, Pattern $pattern): ?array
    {
        return $this->factQuery->candidateIds($index->facts, $pattern);
    }
}
