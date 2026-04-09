<?php

declare(strict_types=1);

namespace Phgrep\Index;

use Phgrep\Support\Filesystem;
use Phgrep\Text\LiteralExtractor;
use Phgrep\Text\TextFileResult;
use Phgrep\Text\TextSearchOptions;
use Phgrep\Text\TextSearcher;
use Phgrep\Walker\FileList;

final class IndexedTextSearcher
{
    private TextSearcher $textSearcher;

    private LiteralExtractor $literalExtractor;

    private TrigramExtractor $trigramExtractor;

    private TextIndexStore $store;

    public function __construct(
        ?TextSearcher $textSearcher = null,
        ?LiteralExtractor $literalExtractor = null,
        ?TrigramExtractor $trigramExtractor = null,
        ?TextIndexStore $store = null,
    ) {
        $this->textSearcher = $textSearcher ?? new TextSearcher();
        $this->literalExtractor = $literalExtractor ?? new LiteralExtractor();
        $this->trigramExtractor = $trigramExtractor ?? new TrigramExtractor();
        $this->store = $store ?? new TextIndexStore();
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
            throw new \RuntimeException('No index found for the requested paths. Build one with phgrep-index build first.');
        }

        $index = $this->store->load($indexPath);
        $selectedPaths = [];
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

            if (!$this->matchesSelections($absolutePath, $resolvedPaths, $index->rootPath)) {
                continue;
            }

            if (
                !isset($explicitSelections[$absolutePath])
                && !$this->matchesQueryFilters($file, $absolutePath, $index->rootPath, $options)
            ) {
                continue;
            }

            $selectedPaths[] = $absolutePath;
        }

        foreach (array_keys($explicitSelections) as $explicitPath) {
            if (!in_array($explicitPath, $selectedPaths, true)) {
                $fallbackPaths[] = $explicitPath;
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

        $seed = $this->querySeed($pattern, $options);

        if ($seed === null || strlen($seed) < 3) {
            return $this->mergeResults(
                $selectedPaths,
                $fallbackPaths,
                $pattern,
                $options,
                null,
            );
        }

        $candidateIds = $this->candidateIds($index->indexPath, $seed);

        return $this->mergeResults(
            $selectedPaths,
            $fallbackPaths,
            $pattern,
            $options,
            $candidateIds,
            $index,
        );
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

        if ($candidateIds === null || $index === null) {
            foreach ($this->textSearcher->searchFiles(new FileList($selectedPaths), $pattern, $options) as $result) {
                $resultsByPath[$result->file] = $result;
            }
        } else {
            $candidatePaths = [];

            foreach ($index->files as $file) {
                $absolutePath = $index->rootPath . '/' . $file['p'];

                if (!in_array($absolutePath, $selectedPaths, true) || !isset($candidateIds[$file['id']])) {
                    continue;
                }

                $candidatePaths[] = $absolutePath;
            }

            foreach ($this->textSearcher->searchFiles(new FileList($candidatePaths), $pattern, $options) as $result) {
                $resultsByPath[$result->file] = $result;
            }

            foreach ($selectedPaths as $selectedPath) {
                if (!isset($resultsByPath[$selectedPath])) {
                    $resultsByPath[$selectedPath] = new TextFileResult($selectedPath, [], 0);
                }
            }
        }

        if ($fallbackPaths !== []) {
            foreach ($this->textSearcher->searchFiles(new FileList($fallbackPaths), $pattern, $options) as $result) {
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

        return $this->textSearcher->sortResults($orderedResults, $order);
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
     * @return array<int, true>
     */
    private function candidateIds(string $indexPath, string $seed): array
    {
        $trigrams = $this->trigramExtractor->extract($seed);

        if ($trigrams === []) {
            return [];
        }

        $firstTrigram = array_shift($trigrams);
        $postings = $this->store->loadSelectedPostings($indexPath, array_merge([$firstTrigram], $trigrams));
        $candidateIds = array_fill_keys($postings[$firstTrigram] ?? [], true);

        foreach ($trigrams as $trigram) {
            $postingSet = array_fill_keys($postings[$trigram] ?? [], true);

            $candidateIds = array_intersect_key($candidateIds, $postingSet);

            if ($candidateIds === []) {
                break;
            }
        }

        return $candidateIds;
    }

    private function querySeed(string $pattern, TextSearchOptions $options): ?string
    {
        if ($options->fixedString) {
            return $pattern;
        }

        return $this->literalExtractor->extract($pattern);
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
     */
    private function matchesSelections(string $absolutePath, array $resolvedPaths, string $rootPath): bool
    {
        foreach ($resolvedPaths as $path) {
            if (!$this->isWithinRoot($path, $rootPath)) {
                continue;
            }

            if (is_file($path) && $absolutePath === $path) {
                return true;
            }

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
