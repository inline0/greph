<?php

declare(strict_types=1);

namespace Greph\Index;

use Greph\Support\Filesystem;
use Greph\Text\LiteralExtractor;
use Greph\Text\TextFileResult;
use Greph\Text\TextSearchOptions;
use Greph\Text\TextSearcher;
use Greph\Walker\FileList;

final class IndexedTextSearcher
{
    private TextSearcher $textSearcher;

    private LiteralExtractor $literalExtractor;

    private TrigramExtractor $trigramExtractor;

    private TextIndexStore $store;

    private TextQueryCacheStore $queryCacheStore;

    public function __construct(
        ?TextSearcher $textSearcher = null,
        ?LiteralExtractor $literalExtractor = null,
        ?TrigramExtractor $trigramExtractor = null,
        ?TextIndexStore $store = null,
        ?TextQueryCacheStore $queryCacheStore = null,
    ) {
        $this->textSearcher = $textSearcher ?? new TextSearcher();
        $this->literalExtractor = $literalExtractor ?? new LiteralExtractor();
        $this->trigramExtractor = $trigramExtractor ?? new TrigramExtractor();
        $this->store = $store ?? new TextIndexStore();
        $this->queryCacheStore = $queryCacheStore ?? new TextQueryCacheStore();
    }

    /**
     * @param string|list<string> $paths
     * @return list<TextFileResult>
     */
    public function search(string $pattern, string|array $paths, TextSearchOptions $options, ?string $indexPath = null): array
    {
        $paths = is_array($paths) ? $paths : [$paths];
        $resolvedPaths = $this->resolvePaths($paths);
        $indexPath = $this->resolveIndexPath($resolvedPaths, $indexPath);

        if ($indexPath === null) {
            throw new \RuntimeException('No index found for the requested paths. Build one with greph-index build first.');
        }

        $index = $this->store->load($indexPath);
        $selectedPaths = [];
        $selectedPathSet = [];
        $fallbackPaths = [];
        $explicitSelections = [];
        $selection = $this->buildSelection($resolvedPaths, $index->rootPath);

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

        if ($this->canUseQueryCache($pattern, $options, $selection, $explicitSelections, $fallbackPaths)) {
            $cachedResults = $this->queryCacheStore->load($index, $pattern, $options);

            if ($cachedResults !== null) {
                if ($this->canPopulateQueryCache($pattern, $options, $resolvedPaths, $explicitSelections, $fallbackPaths, $index)) {
                    return $cachedResults;
                }

                return $this->filterCachedResults($cachedResults, $selectedPaths);
            }
        }

        if ($options->invertMatch) {
            return $this->mergeResults(
                $selectedPaths,
                $fallbackPaths,
                $pattern,
                $options,
                null,
            );
        }

        $wholeWordToken = $this->indexedWholeWordToken($pattern, $options);

        if ($wholeWordToken !== null) {
            $candidateIds = $this->candidateIdsFromWordPostings($index->indexPath, $wholeWordToken);
        } else {
            $seeds = $this->querySeeds($pattern, $options);

            if ($seeds === []) {
                $results = $this->mergeResults(
                    $selectedPaths,
                    $fallbackPaths,
                    $pattern,
                    $options,
                    null,
                );

                if ($this->canPopulateQueryCache($pattern, $options, $resolvedPaths, $explicitSelections, $fallbackPaths, $index)) {
                    $this->queryCacheStore->save($index, $pattern, $options, $results);
                }

                return $results;
            }

            $candidateIds = $this->candidateIds($index->indexPath, $seeds);
        }

        $results = $this->mergeResults(
            $selectedPaths,
            $fallbackPaths,
            $pattern,
            $options,
            $candidateIds,
            $index,
        );

        if ($this->canPopulateQueryCache($pattern, $options, $resolvedPaths, $explicitSelections, $fallbackPaths, $index)) {
            $this->queryCacheStore->save($index, $pattern, $options, $results);
        }

        return $results;
    }

    /**
     * @param list<string> $selectedPaths
     * @param list<string> $fallbackPaths
     * @param array<int, true>|null $candidateIds
     * @return list<TextFileResult>
     */
    private function mergeResults(
        array $selectedPaths,
        array $fallbackPaths,
        string $pattern,
        TextSearchOptions $options,
        ?array $candidateIds,
        ?TextIndex $index = null,
    ): array {
        $resultsByPath = [];
        $canUseDirectLiteralSummary = $this->canUseDirectLiteralSummary($pattern, $options);

        if ($candidateIds === null || $index === null) {
            $searchResults = $canUseDirectLiteralSummary
                ? $this->searchLiteralSummaryFiles($selectedPaths, $pattern, $options)
                : $this->textSearcher->searchFiles(new FileList($selectedPaths), $pattern, $options);

            foreach ($searchResults as $result) {
                $resultsByPath[$result->file] = $result;
            }
        } else {
            $candidatePaths = [];
            $selectedPathSet = array_fill_keys($selectedPaths, true);

            foreach ($index->files as $file) {
                $absolutePath = $index->rootPath . '/' . $file['p'];

                if (!isset($selectedPathSet[$absolutePath]) || !isset($candidateIds[$file['id']])) {
                    continue;
                }

                $candidatePaths[] = $absolutePath;
            }

            $searchResults = $canUseDirectLiteralSummary
                ? $this->searchLiteralSummaryFiles($candidatePaths, $pattern, $options)
                : $this->textSearcher->searchFiles(new FileList($candidatePaths), $pattern, $options);

            foreach ($searchResults as $result) {
                $resultsByPath[$result->file] = $result;
            }

            foreach ($selectedPaths as $selectedPath) {
                if (!isset($resultsByPath[$selectedPath])) {
                    $resultsByPath[$selectedPath] = new TextFileResult($selectedPath, [], 0);
                }
            }
        }

        if ($fallbackPaths !== []) {
            $searchResults = $canUseDirectLiteralSummary
                ? $this->searchLiteralSummaryFiles($fallbackPaths, $pattern, $options)
                : $this->textSearcher->searchFiles(new FileList($fallbackPaths), $pattern, $options);

            foreach ($searchResults as $result) {
                $resultsByPath[$result->file] = $result;
            }
        }

        $order = array_values(array_unique([...$selectedPaths, ...$fallbackPaths]));
        $orderedResults = [];

        foreach ($order as $path) {
            if (isset($resultsByPath[$path])) {
                $orderedResults[] = $resultsByPath[$path];
            }
        }

        return $orderedResults;
    }

    private function canUseDirectLiteralSummary(string $pattern, TextSearchOptions $options): bool
    {
        if ($pattern === '') {
            return false;
        }

        if (!$options->fixedString || $options->wholeWord || $options->invertMatch) {
            return false;
        }

        if ($options->beforeContext > 0 || $options->afterContext > 0) {
            return false;
        }

        return $options->countOnly || $options->filesWithMatches || $options->filesWithoutMatches;
    }

    /**
     * @param array{files: array<string, true>, directories: list<string>} $selection
     * @param array<string, true> $explicitSelections
     * @param list<string> $fallbackPaths
     */
    private function canUseQueryCache(
        string $pattern,
        TextSearchOptions $options,
        array $selection,
        array $explicitSelections,
        array $fallbackPaths,
    ): bool {
        if (!$this->supportsQueryCache($pattern, $options)) {
            return false;
        }

        if ($explicitSelections !== [] || $fallbackPaths !== []) {
            return false;
        }

        return $selection['directories'] !== [];
    }

    /**
     * @param list<string> $resolvedPaths
     * @param array<string, true> $explicitSelections
     * @param list<string> $fallbackPaths
     */
    private function canPopulateQueryCache(
        string $pattern,
        TextSearchOptions $options,
        array $resolvedPaths,
        array $explicitSelections,
        array $fallbackPaths,
        TextIndex $index,
    ): bool {
        if (!$this->supportsQueryCache($pattern, $options)) {
            return false;
        }

        if ($explicitSelections !== [] || $fallbackPaths !== [] || count($resolvedPaths) !== 1) {
            return false;
        }

        return $resolvedPaths[0] === $index->rootPath;
    }

    private function supportsQueryCache(string $pattern, TextSearchOptions $options): bool
    {
        if ($pattern === '' || $options->invertMatch) {
            return false;
        }

        if ($options->beforeContext > 0 || $options->afterContext > 0 || $options->maxCount !== null) {
            return false;
        }

        if (
            $options->includeHidden
            || !$options->respectIgnore
            || $options->followSymlinks
            || !$options->skipBinaryFiles
            || $options->includeGitDirectory
            || $options->fileTypeFilter !== null
            || $options->globPatterns !== []
            || $options->maxFileSizeBytes !== 10485760
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param list<TextFileResult> $cachedResults
     * @param list<string> $selectedPaths
     * @return list<TextFileResult>
     */
    private function filterCachedResults(array $cachedResults, array $selectedPaths): array
    {
        $selected = array_fill_keys($selectedPaths, true);
        $results = [];

        foreach ($cachedResults as $result) {
            if (isset($selected[$result->file])) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param list<string> $paths
     * @return list<TextFileResult>
     */
    private function searchLiteralSummaryFiles(array $paths, string $pattern, TextSearchOptions $options): array
    {
        $results = [];

        foreach ($paths as $path) {
            $contents = @file_get_contents($path);

            if ($contents === false) {
                $results[] = new TextFileResult($path, [], 0);
                continue;
            }

            if ($options->countOnly) {
                $results[] = new TextFileResult(
                    $path,
                    [],
                    $this->countLiteralMatchingLines($contents, $pattern, $options->caseInsensitive, $options->maxCount),
                );
                continue;
            }

            $matched = $this->contentsContainLiteral($contents, $pattern, $options->caseInsensitive);
            $results[] = new TextFileResult($path, [], $matched ? 1 : 0);
        }

        return $results;
    }

    private function contentsContainLiteral(string $contents, string $pattern, bool $caseInsensitive): bool
    {
        return $caseInsensitive
            ? stripos($contents, $pattern) !== false
            : strpos($contents, $pattern) !== false;
    }

    private function countLiteralMatchingLines(
        string $contents,
        string $pattern,
        bool $caseInsensitive,
        ?int $maxCount,
    ): int {
        $count = 0;
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $newlinePosition = strpos($contents, "\n", $offset);

            if ($newlinePosition === false) {
                $rawLine = substr($contents, $offset);
                $offset = $length;
            } else {
                $rawLine = substr($contents, $offset, $newlinePosition - $offset);
                $offset = $newlinePosition + 1;
            }

            $line = rtrim($rawLine, "\r");
            $matched = $caseInsensitive
                ? stripos($line, $pattern) !== false
                : strpos($line, $pattern) !== false;

            if (!$matched) {
                continue;
            }

            $count++;

            if ($maxCount !== null && $count >= $maxCount) {
                break;
            }
        }

        return $count;
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
     * @param list<string> $seeds
     * @return array<int, true>
     */
    private function candidateIds(string $indexPath, array $seeds): array
    {
        $trigrams = [];

        foreach ($seeds as $seed) {
            $trigrams = [...$trigrams, ...$this->trigramExtractor->extract($seed)];
        }

        $trigrams = array_values(array_unique($trigrams));

        if ($trigrams === []) {
            return [];
        }

        $postings = $this->store->loadSelectedPostings($indexPath, $trigrams);
        $trigramLists = [];

        foreach ($trigrams as $trigram) {
            $fileIds = $postings[$trigram] ?? [];

            if ($fileIds === []) {
                return [];
            }

            $trigramLists[] = [
                'trigram' => $trigram,
                'fileIds' => $fileIds,
            ];
        }

        usort(
            $trigramLists,
            static fn (array $left, array $right): int => count($left['fileIds']) <=> count($right['fileIds'])
        );

        $candidateFileIds = $trigramLists[0]['fileIds'];

        foreach (array_slice($trigramLists, 1) as $postingList) {
            $candidateFileIds = $this->intersectSortedFileIds($candidateFileIds, $postingList['fileIds']);

            if ($candidateFileIds === []) {
                break;
            }
        }

        return array_fill_keys($candidateFileIds, true);
    }

    /**
     * @return array<int, true>
     */
    private function candidateIdsFromWordPostings(string $indexPath, string $word): array
    {
        $postings = $this->store->loadSelectedWordPostings($indexPath, [$word]);
        $fileIds = $postings[$word] ?? [];

        if ($fileIds === []) {
            return [];
        }

        return array_fill_keys($fileIds, true);
    }

    /**
     * @return list<string>
     */
    private function querySeeds(string $pattern, TextSearchOptions $options): array
    {
        if ($options->fixedString) {
            return strlen($pattern) >= 3 ? [$pattern] : [];
        }

        $segments = $this->literalExtractor->extractSegments($pattern);
        $segments = array_values(array_filter($segments, static fn (string $segment): bool => strlen($segment) >= 3));

        if ($segments === []) {
            return [];
        }

        // Alternation makes "all segments are required" unsafe, so stay conservative there.
        if (str_contains($pattern, '|')) {
            return [$segments[0]];
        }

        return array_slice($segments, 0, 3);
    }

    private function indexedWholeWordToken(string $pattern, TextSearchOptions $options): ?string
    {
        if (!$options->fixedString || !$options->wholeWord || $pattern === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $pattern) !== 1) {
            return null;
        }

        return strtolower($pattern);
    }

    /**
     * @param array{id: int, p: string, s: int, m: int, h: bool, g: bool, o: int} $file
     */
    private function matchesQueryFilters(array $file, string $absolutePath, string $rootPath, TextSearchOptions $options): bool
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
     * @param list<int> $left
     * @param list<int> $right
     * @return list<int>
     */
    private function intersectSortedFileIds(array $left, array $right): array
    {
        $intersection = [];
        $leftIndex = 0;
        $rightIndex = 0;
        $leftCount = count($left);
        $rightCount = count($right);

        while ($leftIndex < $leftCount && $rightIndex < $rightCount) {
            $leftValue = $left[$leftIndex];
            $rightValue = $right[$rightIndex];

            if ($leftValue === $rightValue) {
                $intersection[] = $leftValue;
                $leftIndex++;
                $rightIndex++;
                continue;
            }

            if ($leftValue < $rightValue) {
                $leftIndex++;
                continue;
            }

            $rightIndex++;
        }

        return $intersection;
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
