<?php

declare(strict_types=1);

namespace Phgrep\Index;

use Phgrep\Ast\AstMatch;
use Phgrep\Ast\AstSearchOptions;
use Phgrep\Ast\AstSearcher;
use Phgrep\Ast\PatternParser;
use Phgrep\Support\Filesystem;

final class CachedAstSearcher
{
    private AstCacheStore $store;

    private AstSearcher $astSearcher;

    private PatternParser $patternParser;

    private AstFactQuery $factQuery;

    private AstQueryCacheStore $queryCacheStore;

    public function __construct(
        ?AstCacheStore $store = null,
        ?AstSearcher $astSearcher = null,
        ?PatternParser $patternParser = null,
        ?AstFactQuery $factQuery = null,
        ?AstQueryCacheStore $queryCacheStore = null,
    ) {
        $this->store = $store ?? new AstCacheStore();
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
            throw new \RuntimeException('No AST cache found for the requested paths. Build one first.');
        }

        $cache = $this->store->load($indexPath);
        $patternObject = $this->patternParser->parse($pattern, $options->language);
        $selection = $this->buildSelection($resolvedPaths, $cache->rootPath);
        $selectedPaths = [];
        $selectedFileIds = [];

        foreach ($cache->files as $file) {
            $absolutePath = $cache->rootPath . '/' . $file['p'];

            if (
                !$this->matchesSelection($absolutePath, $selection)
                || !$this->matchesQueryFilters($file, $absolutePath, $cache->rootPath, $options)
            ) {
                continue;
            }

            $selectedPaths[$file['id']] = $absolutePath;
            $selectedFileIds[$file['id']] = true;
        }

        if ($this->canUseQueryCache($resolvedPaths, $cache->rootPath, $options)) {
            $cachedMatches = $this->queryCacheStore->load($cache, $pattern, $options);

            if ($cachedMatches !== null) {
                return $this->filterCachedMatches($cachedMatches, $selection);
            }
        }

        $candidateIds = $this->factQuery->candidateIds($cache->facts, $patternObject);
        $matches = [];

        foreach ($cache->files as $file) {
            $fileId = $file['id'];

            if (!isset($selectedFileIds[$fileId])) {
                continue;
            }

            if ($candidateIds !== null && !isset($candidateIds[$fileId])) {
                continue;
            }

            $absolutePath = $selectedPaths[$fileId];
            $statements = $this->store->loadTree($cache->indexPath, $fileId);

            if ($statements === null) {
                foreach ($this->astSearcher->searchFiles(new \Phgrep\Walker\FileList([$absolutePath]), $pattern, $options) as $match) {
                    $matches[] = $match;
                }

                continue;
            }

            foreach (
                $this->astSearcher->searchParsedStatements(
                    $absolutePath,
                    $statements,
                    $patternObject,
                    static fn (): string => (string) (@file_get_contents($absolutePath) ?: ''),
                ) as $match
            ) {
                $matches[] = $match;
            }
        }

        usort(
            $matches,
            static fn (AstMatch $left, AstMatch $right): int => [$left->file, $left->startFilePos] <=> [$right->file, $right->startFilePos]
        );

        if ($this->canPopulateQueryCache($resolvedPaths, $cache->rootPath, $options)) {
            $this->queryCacheStore->save($cache, $pattern, $options, $matches);
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

    private function isWithinRoot(string $path, string $rootPath): bool
    {
        return $path === $rootPath || str_starts_with($path, $rootPath . '/');
    }

    /**
     * @param list<string> $resolvedPaths
     */
    private function canUseQueryCache(array $resolvedPaths, string $rootPath, AstSearchOptions $options): bool
    {
        if (!$this->supportsQueryCache($options)) {
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
     */
    private function canPopulateQueryCache(array $resolvedPaths, string $rootPath, AstSearchOptions $options): bool
    {
        return $this->supportsQueryCache($options)
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
}
