<?php

declare(strict_types=1);

namespace Greph\Index;

use Greph\Support\Filesystem;
use PhpParser\Node;

final class AstCacheStore
{
    private const VERSION = 1;

    private const METADATA_FILE = 'metadata.phpbin';

    private const FILES_FILE = 'files.phpbin';

    private const FACTS_FILE = 'facts.phpbin';

    private const TREES_DIRECTORY = 'trees';

    private AstQueryCacheStore $queryCacheStore;

    public function __construct(?AstQueryCacheStore $queryCacheStore = null)
    {
        $this->queryCacheStore = $queryCacheStore ?? new AstQueryCacheStore();
    }

    public function defaultPath(string $rootPath): string
    {
        return Filesystem::normalizePath($rootPath) . '/.greph-ast-cache';
    }

    public function exists(string $indexPath): bool
    {
        return is_file($this->metadataPath($indexPath))
            && is_file($this->filesPath($indexPath))
            && is_file($this->factsPath($indexPath))
            && is_dir($this->treesDirectoryPath($indexPath));
    }

    public function locateFrom(string $path): ?string
    {
        $path = Filesystem::normalizePath(realpath($path) ?: $path);

        if (is_file($path)) {
            $path = dirname($path);
        }

        while (true) {
            $candidate = $this->defaultPath($path);

            if ($this->exists($candidate)) {
                return $candidate;
            }

            $parent = dirname($path);

            if ($parent === $path) {
                return null;
            }

            $path = $parent;
        }
    }

    public function load(string $indexPath): AstCache
    {
        if (!$this->exists($indexPath)) {
            throw new \RuntimeException(sprintf('AST cache does not exist: %s', $indexPath));
        }

        $metadata = $this->decodeFile($this->metadataPath($indexPath));
        $files = $this->decodeFile($this->filesPath($indexPath));
        $facts = $this->decodeFile($this->factsPath($indexPath));

        if (
            !is_array($metadata)
            || !is_string($metadata['rootPath'] ?? null)
            || !is_int($metadata['version'] ?? null)
            || !is_int($metadata['builtAt'] ?? null)
            || !(is_int($metadata['buildDurationMs'] ?? null) || is_float($metadata['buildDurationMs'] ?? null) || !array_key_exists('buildDurationMs', $metadata))
            || !is_int($metadata['nextFileId'] ?? null)
            || !is_array($files)
            || !is_array($facts)
        ) {
            throw new \RuntimeException(sprintf('AST cache is corrupt: %s', $indexPath));
        }

        if ($metadata['version'] !== self::VERSION) {
            throw new \RuntimeException(sprintf('AST cache version mismatch: %s', $indexPath));
        }

        /** @var list<array{id: int, p: string, s: int, m: int, h: bool, g: bool, o: int}> $files */
        /** @var array<int, array{
         *   zero_arg_new: bool,
         *   long_array: bool,
         *   function_calls: list<string>,
         *   method_calls: list<string>,
         *   static_calls: list<string>,
         *   new_targets: list<string>,
         *   classes: list<string>,
         *   interfaces: list<string>,
         *   traits: list<string>,
         *   cached: bool
         * }> $facts
         */
        return new AstCache(
            rootPath: $metadata['rootPath'],
            indexPath: Filesystem::normalizePath($indexPath),
            version: $metadata['version'],
            builtAt: $metadata['builtAt'],
            buildDurationMs: (float) ($metadata['buildDurationMs'] ?? 0.0),
            lifecycle: IndexLifecycle::fromMetadata($metadata),
            nextFileId: $metadata['nextFileId'],
            files: $files,
            facts: $facts,
        );
    }

    public function save(AstCache $cache): void
    {
        Filesystem::ensureDirectory($cache->indexPath);
        Filesystem::ensureDirectory($this->treesDirectoryPath($cache->indexPath));

        $metadata = [
            'version' => $cache->version,
            'rootPath' => $cache->rootPath,
            'builtAt' => $cache->builtAt,
            'buildDurationMs' => $cache->buildDurationMs,
            'nextFileId' => $cache->nextFileId,
            ...$cache->lifecycle->toMetadata(),
        ];

        $this->writeAtomic($this->metadataPath($cache->indexPath), $metadata);
        $this->writeAtomic($this->filesPath($cache->indexPath), $cache->files);
        $this->writeAtomic($this->factsPath($cache->indexPath), $cache->facts);
        $this->queryCacheStore->clear($cache->indexPath);
    }

    /**
     * @param list<Node> $statements
     */
    public function saveTree(string $indexPath, int $fileId, array $statements): void
    {
        $path = $this->treePath($indexPath, $fileId);
        $directory = dirname($path);
        Filesystem::ensureDirectory($directory);
        $temporaryPath = $path . '.tmp';
        $payload = gzencode(serialize($statements), 1);

        if ($payload === false || @file_put_contents($temporaryPath, $payload) === false) {
            throw new \RuntimeException(sprintf('Failed to write AST tree cache: %s', $path));
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new \RuntimeException(sprintf('Failed to finalize AST tree cache: %s', $path));
        }
    }

    /**
     * @return list<Node>|null
     */
    public function loadTree(string $indexPath, int $fileId): ?array
    {
        $path = $this->treePath($indexPath, $fileId);

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        $decoded = gzdecode($contents);

        if ($decoded === false) {
            throw new \RuntimeException(sprintf('AST tree cache is corrupt: %s', $path));
        }

        /** @var mixed $statements */
        $statements = unserialize($decoded, ['allowed_classes' => true]);

        if (!is_array($statements)) {
            return null;
        }

        /** @var list<Node> $statements */
        return $statements;
    }

    /**
     * @param array<int, true> $activeFileIds
     */
    public function pruneTrees(string $indexPath, array $activeFileIds): void
    {
        $directory = $this->treesDirectoryPath($indexPath);

        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*.phpbin.gz') ?: [] as $path) {
            $basename = basename($path, '.phpbin.gz');
            $fileId = ctype_digit($basename) ? (int) $basename : null;

            if ($fileId !== null && !isset($activeFileIds[$fileId])) {
                @unlink($path);
            }
        }
    }

    /**
     * @return array{count: int, size: int}
     */
    public function treeStats(string $indexPath): array
    {
        $directory = $this->treesDirectoryPath($indexPath);

        if (!is_dir($directory)) {
            return ['count' => 0, 'size' => 0];
        }

        $count = 0;
        $size = 0;

        foreach (glob($directory . '/*.phpbin.gz') ?: [] as $path) {
            $count++;
            $entrySize = filesize($path);
            $size += is_int($entrySize) ? $entrySize : 0;
        }

        return ['count' => $count, 'size' => $size];
    }

    public function version(): int
    {
        return self::VERSION;
    }

    private function metadataPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::METADATA_FILE;
    }

    private function filesPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::FILES_FILE;
    }

    private function factsPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::FACTS_FILE;
    }

    private function treesDirectoryPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::TREES_DIRECTORY;
    }

    private function treePath(string $indexPath, int $fileId): string
    {
        return $this->treesDirectoryPath($indexPath) . '/' . $fileId . '.phpbin.gz';
    }

    private function decodeFile(string $path): mixed
    {
        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            throw new \RuntimeException(sprintf('Failed to read AST cache file: %s', $path));
        }

        return unserialize($contents, ['allowed_classes' => false]);
    }

    private function writeAtomic(string $path, mixed $payload): void
    {
        $directory = dirname($path);
        Filesystem::ensureDirectory($directory);
        $temporaryPath = $path . '.tmp';

        if (@file_put_contents($temporaryPath, serialize($payload)) === false) {
            throw new \RuntimeException(sprintf('Failed to write AST cache file: %s', $path));
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new \RuntimeException(sprintf('Failed to finalize AST cache file: %s', $path));
        }
    }
}
