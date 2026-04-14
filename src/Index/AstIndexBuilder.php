<?php

declare(strict_types=1);

namespace Greph\Index;

use Greph\Support\Filesystem;
use Greph\Walker\FileWalker;

final class AstIndexBuilder
{
    private FileWalker $fileWalker;

    private AstFactExtractor $factExtractor;

    private AstIndexStore $store;

    private IndexFileScanner $scanner;

    public function __construct(
        ?FileWalker $fileWalker = null,
        ?AstFactExtractor $factExtractor = null,
        ?AstIndexStore $store = null,
        ?IndexFileScanner $scanner = null,
    ) {
        $this->fileWalker = $fileWalker ?? new FileWalker();
        $this->factExtractor = $factExtractor ?? new AstFactExtractor();
        $this->store = $store ?? new AstIndexStore();
        $this->scanner = $scanner ?? new IndexFileScanner($this->fileWalker);
    }

    public function build(
        string $rootPath,
        ?string $indexPath = null,
        IndexLifecycle|IndexLifecycleProfile|string|null $lifecycle = null,
    ): AstIndexBuildResult {
        $start = hrtime(true);
        $rootPath = $this->resolveRootPath($rootPath);
        $indexPath = $this->resolveIndexPath($rootPath, $indexPath);
        $lifecycle = IndexLifecycle::normalize($lifecycle);
        $scannedFiles = $this->scanFiles($rootPath, $indexPath);
        $files = [];
        $facts = [];
        $nextFileId = 1;

        foreach ($scannedFiles as $scan) {
            $files[] = [
                'id' => $nextFileId,
                'p' => $scan['relativePath'],
                's' => $scan['size'],
                'm' => $scan['mtime'],
                'h' => $scan['hidden'],
                'g' => $scan['ignored'],
                'o' => $scan['order'],
            ];
            $facts[$nextFileId] = $this->extractFacts($scan['absolutePath']);
            $nextFileId++;
        }

        $index = new AstIndex(
            rootPath: $rootPath,
            indexPath: $indexPath,
            version: $this->store->version(),
            builtAt: time(),
            buildDurationMs: (hrtime(true) - $start) / 1_000_000,
            lifecycle: $lifecycle,
            nextFileId: $nextFileId,
            files: $files,
            facts: $facts,
        );
        $this->store->save($index);

        return new AstIndexBuildResult(
            rootPath: $rootPath,
            indexPath: $indexPath,
            fileCount: count($files),
            factCount: count($facts),
            buildDurationMs: $index->buildDurationMs,
            addedFiles: count($files),
            updatedFiles: 0,
            deletedFiles: 0,
            unchangedFiles: 0,
        );
    }

    public function refresh(
        string $rootPath,
        ?string $indexPath = null,
        IndexLifecycle|IndexLifecycleProfile|string|null $lifecycle = null,
    ): AstIndexBuildResult {
        $start = hrtime(true);
        $rootPath = $this->resolveRootPath($rootPath);
        $indexPath = $this->resolveIndexPath($rootPath, $indexPath);

        if (!$this->store->exists($indexPath)) {
            return $this->build($rootPath, $indexPath, $lifecycle);
        }

        $existingIndex = $this->store->load($indexPath);
        $lifecycle = $lifecycle !== null
            ? IndexLifecycle::normalize($lifecycle)
            : $existingIndex->lifecycle;
        $scannedFiles = $this->scanFiles($rootPath, $indexPath);
        $existingByPath = [];

        foreach ($existingIndex->files as $file) {
            $existingByPath[$file['p']] = $file;
        }

        $files = [];
        $facts = [];
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
                $facts[$existing['id']] = $existingIndex->facts[$existing['id']] ?? $this->extractFacts($scan['absolutePath']);
                $unchangedFiles++;
                unset($existingByPath[$scan['relativePath']]);
                continue;
            }

            $fileId = is_array($existing) ? $existing['id'] : $nextFileId++;
            $files[] = [
                'id' => $fileId,
                'p' => $scan['relativePath'],
                's' => $scan['size'],
                'm' => $scan['mtime'],
                'h' => $scan['hidden'],
                'g' => $scan['ignored'],
                'o' => $scan['order'],
            ];
            $facts[$fileId] = $this->extractFacts($scan['absolutePath']);

            if (is_array($existing)) {
                $updatedFiles++;
                unset($existingByPath[$scan['relativePath']]);
            } else {
                $addedFiles++;
            }
        }

        $deletedFiles = count($existingByPath);
        $index = new AstIndex(
            rootPath: $rootPath,
            indexPath: $indexPath,
            version: $this->store->version(),
            builtAt: time(),
            buildDurationMs: (hrtime(true) - $start) / 1_000_000,
            lifecycle: $lifecycle,
            nextFileId: $nextFileId,
            files: $files,
            facts: $facts,
        );
        $this->store->save($index);

        return new AstIndexBuildResult(
            rootPath: $rootPath,
            indexPath: $indexPath,
            fileCount: count($files),
            factCount: count($facts),
            buildDurationMs: $index->buildDurationMs,
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
            throw new \RuntimeException(sprintf('AST index root does not exist: %s', $rootPath));
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
        $scannedFiles = $this->scanner->scanPhp($rootPath, $indexPath);

        foreach ($scannedFiles as &$scan) {
            $scan['hidden'] = $this->isHiddenPath($scan['relativePath']);
        }
        unset($scan);

        return $scannedFiles;
    }

    /**
     * @return array{
     *   zero_arg_new: bool,
     *   long_array: bool,
     *   function_calls: list<string>,
     *   method_calls: list<string>,
     *   static_calls: list<string>,
     *   new_targets: list<string>,
     *   classes: list<string>,
     *   interfaces: list<string>,
     *   traits: list<string>
     * }
     */
    private function extractFacts(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [
                'zero_arg_new' => false,
                'long_array' => false,
                'function_calls' => [],
                'method_calls' => [],
                'static_calls' => [],
                'new_targets' => [],
                'classes' => [],
                'interfaces' => [],
                'traits' => [],
            ];
        }

        return $this->factExtractor->extract($contents);
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
