<?php

declare(strict_types=1);

namespace Greph\Index;

use Greph\Ast\Parsers\ParserFactory;
use Greph\Exceptions\ParseException;
use Greph\Support\Filesystem;
use Greph\Walker\FileWalker;

final class AstCacheBuilder
{
    private FileWalker $fileWalker;

    private AstFactExtractor $factExtractor;

    private AstCacheStore $store;

    private ParserFactory $parserFactory;

    private IndexFileScanner $scanner;

    public function __construct(
        ?FileWalker $fileWalker = null,
        ?AstFactExtractor $factExtractor = null,
        ?AstCacheStore $store = null,
        ?ParserFactory $parserFactory = null,
        ?IndexFileScanner $scanner = null,
    ) {
        $this->fileWalker = $fileWalker ?? new FileWalker();
        $this->factExtractor = $factExtractor ?? new AstFactExtractor();
        $this->store = $store ?? new AstCacheStore();
        $this->parserFactory = $parserFactory ?? new ParserFactory();
        $this->scanner = $scanner ?? new IndexFileScanner($this->fileWalker);
    }

    public function build(
        string $rootPath,
        ?string $indexPath = null,
        IndexLifecycle|IndexLifecycleProfile|string|null $lifecycle = null,
    ): AstCacheBuildResult {
        $start = hrtime(true);
        $rootPath = $this->resolveRootPath($rootPath);
        $indexPath = $this->resolveIndexPath($rootPath, $indexPath);
        Filesystem::remove($indexPath);

        return $this->rebuild($rootPath, $indexPath, null, $start, IndexLifecycle::normalize($lifecycle));
    }

    public function refresh(
        string $rootPath,
        ?string $indexPath = null,
        IndexLifecycle|IndexLifecycleProfile|string|null $lifecycle = null,
    ): AstCacheBuildResult {
        $start = hrtime(true);
        $rootPath = $this->resolveRootPath($rootPath);
        $indexPath = $this->resolveIndexPath($rootPath, $indexPath);
        $existing = $this->store->exists($indexPath) ? $this->store->load($indexPath) : null;
        $lifecycle = $lifecycle !== null
            ? IndexLifecycle::normalize($lifecycle)
            : ($existing !== null ? $existing->lifecycle : new IndexLifecycle());

        return $this->rebuild($rootPath, $indexPath, $existing, $start, $lifecycle);
    }

    private function resolveRootPath(string $rootPath): string
    {
        $resolvedPath = realpath($rootPath);

        if ($resolvedPath === false || !is_dir($resolvedPath)) {
            throw new \RuntimeException(sprintf('AST cache root does not exist: %s', $rootPath));
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

    private function rebuild(
        string $rootPath,
        string $indexPath,
        ?AstCache $existing,
        int $start,
        IndexLifecycle $lifecycle,
    ): AstCacheBuildResult {
        $scannedFiles = $this->scanFiles($rootPath, $indexPath);
        $existingByPath = [];

        if ($existing !== null) {
            foreach ($existing->files as $file) {
                $existingByPath[$file['p']] = $file;
            }
        }

        $files = [];
        $facts = [];
        $nextFileId = $existing !== null ? $existing->nextFileId : 1;
        $cachedTreeCount = 0;
        $addedFiles = 0;
        $updatedFiles = 0;
        $unchangedFiles = 0;
        $activeFileIds = [];
        $parser = $this->parserFactory->forLanguage('php');

        Filesystem::ensureDirectory($indexPath);

        foreach ($scannedFiles as $scan) {
            $existingFile = $existingByPath[$scan['relativePath']] ?? null;
            $fileId = is_array($existingFile) ? $existingFile['id'] : $nextFileId++;
            $treeCached = false;

            if (
                is_array($existingFile)
                && $existingFile['s'] === $scan['size']
                && $existingFile['m'] === $scan['mtime']
            ) {
                $treeCached = ($existing?->facts[$fileId]['cached'] ?? false);
                $facts[$fileId] = $existing?->facts[$fileId] ?? $this->extractFacts($scan['absolutePath']);
                $unchangedFiles++;
                unset($existingByPath[$scan['relativePath']]);
            } else {
                $facts[$fileId] = $this->extractFacts($scan['absolutePath']);
                $contents = @file_get_contents($scan['absolutePath']);

                if ($contents !== false) {
                    try {
                        $statements = $parser->parseStatements($contents);
                        $this->store->saveTree($indexPath, $fileId, $statements);
                        $treeCached = true;
                    } catch (ParseException) {
                        $treeCached = false;
                    }
                }

                if (is_array($existingFile)) {
                    $updatedFiles++;
                    unset($existingByPath[$scan['relativePath']]);
                } else {
                    $addedFiles++;
                }
            }

            $facts[$fileId]['cached'] = $treeCached;
            $files[] = [
                'id' => $fileId,
                'p' => $scan['relativePath'],
                's' => $scan['size'],
                'm' => $scan['mtime'],
                'h' => $scan['hidden'],
                'g' => $scan['ignored'],
                'o' => $scan['order'],
            ];
            $activeFileIds[$fileId] = true;

            if ($treeCached) {
                $cachedTreeCount++;
            }
        }

        $deletedFiles = count($existingByPath);
        $cache = new AstCache(
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
        $this->store->save($cache);
        $this->store->pruneTrees($indexPath, $activeFileIds);

        return new AstCacheBuildResult(
            rootPath: $rootPath,
            indexPath: $indexPath,
            fileCount: count($files),
            cachedTreeCount: $cachedTreeCount,
            buildDurationMs: $cache->buildDurationMs,
            addedFiles: $addedFiles,
            updatedFiles: $updatedFiles,
            deletedFiles: $deletedFiles,
            unchangedFiles: $unchangedFiles,
        );
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
     *   traits: list<string>,
     *   cached?: bool
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
