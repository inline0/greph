<?php

declare(strict_types=1);

namespace Phgrep\Index;

use Phgrep\Support\Filesystem;
use Phgrep\Walker\FileWalker;
use Phgrep\Walker\GitignoreFilter;
use Phgrep\Walker\WalkOptions;

final class TextIndexBuilder
{
    private const MAX_INDEXED_FILE_SIZE_BYTES = 10485760;

    private FileWalker $fileWalker;

    private TrigramExtractor $trigramExtractor;

    private TextIndexStore $store;

    public function __construct(
        ?FileWalker $fileWalker = null,
        ?TrigramExtractor $trigramExtractor = null,
        ?TextIndexStore $store = null,
    ) {
        $this->fileWalker = $fileWalker ?? new FileWalker();
        $this->trigramExtractor = $trigramExtractor ?? new TrigramExtractor();
        $this->store = $store ?? new TextIndexStore();
    }

    public function build(string $rootPath, ?string $indexPath = null): IndexBuildResult
    {
        $rootPath = $this->resolveRootPath($rootPath);
        $indexPath = $this->resolveIndexPath($rootPath, $indexPath);
        $scannedFiles = $this->scanFiles($rootPath, $indexPath);
        $files = [];
        $forward = [];
        $nextFileId = 1;

        foreach ($scannedFiles as $scan) {
            $trigrams = $this->extractFileTrigrams($scan['absolutePath']);
            $files[] = [
                'id' => $nextFileId,
                'p' => $scan['relativePath'],
                's' => $scan['size'],
                'm' => $scan['mtime'],
                'h' => $scan['hidden'],
                'g' => $scan['ignored'],
                'o' => $scan['order'],
            ];
            $forward[$nextFileId] = $trigrams;
            $nextFileId++;
        }

        $index = new TextIndex(
            rootPath: $rootPath,
            indexPath: $indexPath,
            version: $this->store->version(),
            builtAt: time(),
            nextFileId: $nextFileId,
            files: $files,
            postings: $this->buildPostings($forward),
            forward: $forward,
        );
        $this->store->save($index);

        return new IndexBuildResult(
            rootPath: $rootPath,
            indexPath: $indexPath,
            fileCount: count($files),
            trigramCount: count($index->postings),
            addedFiles: count($files),
            updatedFiles: 0,
            deletedFiles: 0,
            unchangedFiles: 0,
        );
    }

    public function refresh(string $rootPath, ?string $indexPath = null): IndexBuildResult
    {
        $rootPath = $this->resolveRootPath($rootPath);
        $indexPath = $this->resolveIndexPath($rootPath, $indexPath);

        if (!$this->store->exists($indexPath)) {
            return $this->build($rootPath, $indexPath);
        }

        $existingIndex = $this->store->load($indexPath, true);

        if ($existingIndex->rootPath !== $rootPath) {
            throw new \RuntimeException(sprintf(
                'Index root mismatch: expected %s, found %s',
                $rootPath,
                $existingIndex->rootPath,
            ));
        }

        $scannedFiles = $this->scanFiles($rootPath, $indexPath);
        $existingByPath = [];
        $existingForward = $existingIndex->forward;

        foreach ($existingIndex->files as $file) {
            $existingByPath[$file['p']] = $file;
        }

        $files = [];
        $forward = [];
        $nextFileId = $existingIndex->nextFileId;
        $addedFiles = 0;
        $updatedFiles = 0;
        $unchangedFiles = 0;

        foreach ($scannedFiles as $scan) {
            $existing = $existingByPath[$scan['relativePath']] ?? null;

            if (
                is_array($existing)
                && $existing['s'] === $scan['size']
                && $existing['m'] === $scan['mtime']
            ) {
                $files[] = [
                    'id' => $existing['id'],
                    'p' => $scan['relativePath'],
                    's' => $scan['size'],
                    'm' => $scan['mtime'],
                    'h' => $scan['hidden'],
                    'g' => $scan['ignored'],
                    'o' => $scan['order'],
                ];
                $forward[$existing['id']] = $existingForward[$existing['id']] ?? $this->extractFileTrigrams($scan['absolutePath']);
                $unchangedFiles++;
                unset($existingByPath[$scan['relativePath']]);
                continue;
            }

            $fileId = is_array($existing) ? $existing['id'] : $nextFileId++;
            $trigrams = $this->extractFileTrigrams($scan['absolutePath']);
            $files[] = [
                'id' => $fileId,
                'p' => $scan['relativePath'],
                's' => $scan['size'],
                'm' => $scan['mtime'],
                'h' => $scan['hidden'],
                'g' => $scan['ignored'],
                'o' => $scan['order'],
            ];
            $forward[$fileId] = $trigrams;

            if (is_array($existing)) {
                $updatedFiles++;
                unset($existingByPath[$scan['relativePath']]);
            } else {
                $addedFiles++;
            }
        }

        $deletedFiles = count($existingByPath);
        $index = new TextIndex(
            rootPath: $rootPath,
            indexPath: $indexPath,
            version: $this->store->version(),
            builtAt: time(),
            nextFileId: $nextFileId,
            files: $files,
            postings: $this->buildPostings($forward),
            forward: $forward,
        );
        $this->store->save($index);

        return new IndexBuildResult(
            rootPath: $rootPath,
            indexPath: $indexPath,
            fileCount: count($files),
            trigramCount: count($index->postings),
            addedFiles: $addedFiles,
            updatedFiles: $updatedFiles,
            deletedFiles: $deletedFiles,
            unchangedFiles: $unchangedFiles,
        );
    }

    private function resolveRootPath(string $rootPath): string
    {
        $resolvedPath = realpath($rootPath);

        if ($resolvedPath === false || !is_dir($resolvedPath)) {
            throw new \RuntimeException(sprintf('Index root does not exist: %s', $rootPath));
        }

        return Filesystem::normalizePath($resolvedPath);
    }

    private function resolveIndexPath(string $rootPath, ?string $indexPath): string
    {
        if ($indexPath === null || $indexPath === '') {
            return $this->store->defaultPath($rootPath);
        }

        if (str_starts_with($indexPath, '/')) {
            return Filesystem::normalizePath($indexPath);
        }

        return Filesystem::normalizePath($rootPath . '/' . $indexPath);
    }

    /**
     * @return list<array{absolutePath: string, relativePath: string, size: int, mtime: int, hidden: bool, ignored: bool, order: int}>
     */
    private function scanFiles(string $rootPath, string $indexPath): array
    {
        $files = $this->fileWalker->walk(
            $rootPath,
            new WalkOptions(
                respectIgnore: false,
                includeHidden: true,
                skipBinaryFiles: true,
                includeGitDirectory: false,
                maxFileSizeBytes: self::MAX_INDEXED_FILE_SIZE_BYTES,
            ),
        );
        $ignoreFilter = new GitignoreFilter($rootPath);
        $scannedFiles = [];
        $order = 0;

        foreach ($files as $file) {
            if ($this->isInsideIndexPath($file, $indexPath)) {
                continue;
            }

            $relativePath = Filesystem::relativePath($rootPath, $file);
            $size = filesize($file);
            $mtime = filemtime($file);

            $scannedFiles[] = [
                'absolutePath' => $file,
                'relativePath' => $relativePath,
                'size' => is_int($size) ? $size : 0,
                'mtime' => is_int($mtime) ? $mtime : 0,
                'hidden' => $this->isHiddenPath($relativePath),
                'ignored' => $ignoreFilter->shouldIgnore($file, false),
                'order' => $order++,
            ];
        }

        return $scannedFiles;
    }

    private function isInsideIndexPath(string $path, string $indexPath): bool
    {
        $path = Filesystem::normalizePath($path);
        $indexPath = Filesystem::normalizePath($indexPath);

        return $path === $indexPath || str_starts_with($path, $indexPath . '/');
    }

    /**
     * @param array<int, list<string>> $forward
     * @return array<string, list<int>>
     */
    private function buildPostings(array $forward): array
    {
        $postings = [];

        foreach ($forward as $fileId => $trigrams) {
            foreach ($trigrams as $trigram) {
                $postings[$trigram] ??= [];
                $postings[$trigram][] = $fileId;
            }
        }

        foreach ($postings as &$fileIds) {
            sort($fileIds);
        }
        unset($fileIds);

        ksort($postings);

        return $postings;
    }

    /**
     * @return list<string>
     */
    private function extractFileTrigrams(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        return $this->trigramExtractor->extract($contents);
    }

    private function isHiddenPath(string $relativePath): bool
    {
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return true;
            }
        }

        return false;
    }
}
