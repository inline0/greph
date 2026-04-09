<?php

declare(strict_types=1);

namespace Phgrep\Index;

use Phgrep\Support\Filesystem;

final class TextIndexStore
{
    private const VERSION = 1;

    private const METADATA_FILE = 'metadata.phpbin';

    private const FILES_FILE = 'files.phpbin';

    private const POSTINGS_FILE = 'postings.phpbin';

    private const FORWARD_FILE = 'forward.phpbin';

    private const POSTINGS_DIRECTORY = 'postings';

    public function defaultPath(string $rootPath): string
    {
        return Filesystem::normalizePath($rootPath) . '/.phgrep-index';
    }

    public function exists(string $indexPath): bool
    {
        return is_file($this->metadataPath($indexPath))
            && is_file($this->filesPath($indexPath))
            && ($this->hasLegacyPostings($indexPath) || is_dir($this->postingsDirectoryPath($indexPath)));
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

    public function load(string $indexPath, bool $includeForward = false, bool $includePostings = false): TextIndex
    {
        if (!$this->exists($indexPath)) {
            throw new \RuntimeException(sprintf('Index does not exist: %s', $indexPath));
        }

        $metadata = $this->decodeFile($this->metadataPath($indexPath));
        $files = $this->decodeFile($this->filesPath($indexPath));
        $postings = $includePostings ? $this->loadAllPostings($indexPath) : [];
        $forward = [];

        if ($includeForward) {
            $forwardPath = $this->forwardPath($indexPath);

            if (is_file($forwardPath)) {
                $forward = $this->decodeFile($forwardPath);
            } else {
                $forward = $this->forwardFromLegacyFiles($files);
            }
        }

        if (
            !is_array($metadata)
            || !is_string($metadata['rootPath'] ?? null)
            || !is_int($metadata['version'] ?? null)
            || !is_int($metadata['builtAt'] ?? null)
            || !is_int($metadata['nextFileId'] ?? null)
            || !is_array($files)
            || !is_array($forward)
        ) {
            throw new \RuntimeException(sprintf('Index is corrupt: %s', $indexPath));
        }

        if ($metadata['version'] !== self::VERSION) {
            throw new \RuntimeException(sprintf('Index version mismatch: %s', $indexPath));
        }

        /** @var list<array{id: int, p: string, s: int, m: int, h: bool, g: bool, t: list<string>, o: int}> $files */
        /** @var array<string, list<int>> $postings */
        return new TextIndex(
            rootPath: $metadata['rootPath'],
            indexPath: Filesystem::normalizePath($indexPath),
            version: $metadata['version'],
            builtAt: $metadata['builtAt'],
            nextFileId: $metadata['nextFileId'],
            files: $files,
            postings: $postings,
            forward: $forward,
        );
    }

    public function save(TextIndex $index): void
    {
        Filesystem::ensureDirectory($index->indexPath);

        $metadata = [
            'version' => $index->version,
            'rootPath' => $index->rootPath,
            'builtAt' => $index->builtAt,
            'nextFileId' => $index->nextFileId,
        ];

        $files = array_map(
            static fn (array $file): array => [
                'id' => $file['id'],
                'p' => $file['p'],
                's' => $file['s'],
                'm' => $file['m'],
                'h' => $file['h'],
                'g' => $file['g'],
                'o' => $file['o'],
            ],
            $index->files,
        );

        $this->writeAtomic($this->metadataPath($index->indexPath), $metadata);
        $this->writeAtomic($this->filesPath($index->indexPath), $files);
        $this->writePostings($index->indexPath, $index->postings);
        $this->writeAtomic($this->forwardPath($index->indexPath), $index->forward);
    }

    public function version(): int
    {
        return self::VERSION;
    }

    /**
     * @param list<string> $trigrams
     * @return array<string, list<int>>
     */
    public function loadSelectedPostings(string $indexPath, array $trigrams): array
    {
        if ($trigrams === []) {
            return [];
        }

        if ($this->hasLegacyPostings($indexPath)) {
            $postings = $this->normalizePostingsPayload(
                $this->decodeFile($this->postingsPath($indexPath)),
                $indexPath,
            );

            $selected = [];

            foreach ($trigrams as $trigram) {
                if (isset($postings[$trigram])) {
                    $selected[$trigram] = $postings[$trigram];
                }
            }

            return $selected;
        }

        $selected = [];
        $bucketedTrigrams = [];

        foreach (array_values(array_unique($trigrams)) as $trigram) {
            $bucket = $this->bucketName($trigram);
            $bucketedTrigrams[$bucket] ??= [];
            $bucketedTrigrams[$bucket][] = $trigram;
        }

        foreach ($bucketedTrigrams as $bucket => $bucketTrigrams) {
            $path = $this->postingsDirectoryPath($indexPath) . '/' . $bucket . '.phpbin';

            if (!is_file($path)) {
                continue;
            }

            $postings = $this->normalizePostingsPayload($this->decodeFile($path), $indexPath);

            foreach ($bucketTrigrams as $trigram) {
                if (isset($postings[$trigram])) {
                    $selected[$trigram] = $postings[$trigram];
                }
            }
        }

        return $selected;
    }

    private function metadataPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::METADATA_FILE;
    }

    private function filesPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::FILES_FILE;
    }

    private function postingsPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::POSTINGS_FILE;
    }

    private function postingsDirectoryPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::POSTINGS_DIRECTORY;
    }

    private function forwardPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::FORWARD_FILE;
    }

    private function decodeFile(string $path): mixed
    {
        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            throw new \RuntimeException(sprintf('Failed to read index file: %s', $path));
        }

        return unserialize($contents, ['allowed_classes' => false]);
    }

    private function writeAtomic(string $path, mixed $payload): void
    {
        $directory = dirname($path);
        Filesystem::ensureDirectory($directory);
        $temporaryPath = $path . '.tmp';

        if (@file_put_contents($temporaryPath, serialize($payload)) === false) {
            throw new \RuntimeException(sprintf('Failed to write index file: %s', $path));
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new \RuntimeException(sprintf('Failed to finalize index file: %s', $path));
        }
    }

    /**
     * @return array<string, list<int>>
     */
    private function loadAllPostings(string $indexPath): array
    {
        if ($this->hasLegacyPostings($indexPath)) {
            return $this->normalizePostingsPayload(
                $this->decodeFile($this->postingsPath($indexPath)),
                $indexPath,
            );
        }

        $postings = [];
        $directory = $this->postingsDirectoryPath($indexPath);

        foreach (glob($directory . '/*.phpbin') ?: [] as $path) {
            $bucket = $this->normalizePostingsPayload($this->decodeFile($path), $indexPath);

            foreach ($bucket as $trigram => $fileIds) {
                $postings[$trigram] = $fileIds;
            }
        }

        ksort($postings);

        return $postings;
    }

    /**
     * @param array<string, list<int>> $postings
     */
    private function writePostings(string $indexPath, array $postings): void
    {
        $postingsDirectory = $this->postingsDirectoryPath($indexPath);
        $temporaryDirectory = $postingsDirectory . '.tmp';
        $bucketedPostings = [];

        foreach ($postings as $trigram => $fileIds) {
            $trigram = (string) $trigram;
            $bucket = $this->bucketName($trigram);
            $bucketedPostings[$bucket] ??= [];
            $bucketedPostings[$bucket][$this->postingKey($trigram)] = $fileIds;
        }

        Filesystem::remove($temporaryDirectory);
        Filesystem::ensureDirectory($temporaryDirectory);

        foreach ($bucketedPostings as $bucket => $bucketPostings) {
            ksort($bucketPostings);
            $this->writeAtomic($temporaryDirectory . '/' . $bucket . '.phpbin', $bucketPostings);
        }

        Filesystem::remove($postingsDirectory);

        if (!@rename($temporaryDirectory, $postingsDirectory)) {
            Filesystem::remove($temporaryDirectory);

            throw new \RuntimeException(sprintf('Failed to finalize index postings: %s', $indexPath));
        }

        @unlink($this->postingsPath($indexPath));
    }

    private function hasLegacyPostings(string $indexPath): bool
    {
        return is_file($this->postingsPath($indexPath));
    }

    private function bucketName(string $trigram): string
    {
        return substr(md5($trigram), 0, 2);
    }

    private function postingKey(string $trigram): string
    {
        return 't:' . $trigram;
    }

    /**
     * @return array<string, list<int>>
     */
    private function normalizePostingsPayload(mixed $payload, string $indexPath): array
    {
        if (!is_array($payload)) {
            throw new \RuntimeException(sprintf('Index is corrupt: %s', $indexPath));
        }

        $postings = [];

        foreach ($payload as $trigram => $fileIds) {
            if (!is_array($fileIds)) {
                continue;
            }

            $postings[$this->decodePostingKey((string) $trigram)] = array_values(array_filter($fileIds, static fn (mixed $value): bool => is_int($value)));
        }

        return $postings;
    }

    private function decodePostingKey(string $trigram): string
    {
        if (str_starts_with($trigram, 't:')) {
            return substr($trigram, 2);
        }

        return $trigram;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return array<int, list<string>>
     */
    private function forwardFromLegacyFiles(array $files): array
    {
        $forward = [];

        foreach ($files as $file) {
            $fileId = $file['id'] ?? null;
            $trigrams = $file['t'] ?? null;

            if (!is_int($fileId) || !is_array($trigrams)) {
                continue;
            }

            $forward[$fileId] = array_values(array_filter($trigrams, static fn (mixed $value): bool => is_string($value)));
        }

        return $forward;
    }
}
