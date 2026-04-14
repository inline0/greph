<?php

declare(strict_types=1);

namespace Greph\Index;

use Greph\Support\Filesystem;

final class AstIndexStore
{
    private const VERSION = 1;

    private const METADATA_FILE = 'metadata.phpbin';

    private const FILES_FILE = 'files.phpbin';

    private const FACTS_FILE = 'facts.phpbin';

    private AstQueryCacheStore $queryCacheStore;

    public function __construct(?AstQueryCacheStore $queryCacheStore = null)
    {
        $this->queryCacheStore = $queryCacheStore ?? new AstQueryCacheStore();
    }

    public function defaultPath(string $rootPath): string
    {
        return Filesystem::normalizePath($rootPath) . '/.greph-ast-index';
    }

    public function exists(string $indexPath): bool
    {
        return is_file($this->metadataPath($indexPath))
            && is_file($this->filesPath($indexPath))
            && is_file($this->factsPath($indexPath));
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

    public function load(string $indexPath): AstIndex
    {
        if (!$this->exists($indexPath)) {
            throw new \RuntimeException(sprintf('AST index does not exist: %s', $indexPath));
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
            throw new \RuntimeException(sprintf('AST index is corrupt: %s', $indexPath));
        }

        if ($metadata['version'] !== self::VERSION) {
            throw new \RuntimeException(sprintf('AST index version mismatch: %s', $indexPath));
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
         *   traits: list<string>
         * }> $facts
         */
        return new AstIndex(
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

    public function save(AstIndex $index): void
    {
        Filesystem::ensureDirectory($index->indexPath);

        $metadata = [
            'version' => $index->version,
            'rootPath' => $index->rootPath,
            'builtAt' => $index->builtAt,
            'buildDurationMs' => $index->buildDurationMs,
            'nextFileId' => $index->nextFileId,
            ...$index->lifecycle->toMetadata(),
        ];

        $this->writeAtomic($this->metadataPath($index->indexPath), $metadata);
        $this->writeAtomic($this->filesPath($index->indexPath), $index->files);
        $this->writeAtomic($this->factsPath($index->indexPath), $index->facts);
        $this->queryCacheStore->clear($index->indexPath);
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

    private function decodeFile(string $path): mixed
    {
        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            throw new \RuntimeException(sprintf('Failed to read AST index file: %s', $path));
        }

        return unserialize($contents, ['allowed_classes' => false]);
    }

    private function writeAtomic(string $path, mixed $payload): void
    {
        $directory = dirname($path);
        Filesystem::ensureDirectory($directory);
        $temporaryPath = $path . '.tmp';

        if (@file_put_contents($temporaryPath, serialize($payload)) === false) {
            throw new \RuntimeException(sprintf('Failed to write AST index file: %s', $path));
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new \RuntimeException(sprintf('Failed to finalize AST index file: %s', $path));
        }
    }
}
