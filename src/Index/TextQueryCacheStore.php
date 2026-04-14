<?php

declare(strict_types=1);

namespace Greph\Index;

use Greph\Support\Filesystem;
use Greph\Text\TextFileResult;
use Greph\Text\TextResultCodec;
use Greph\Text\TextSearchOptions;

final class TextQueryCacheStore
{
    private const DIRECTORY = 'queries';

    private const EXTENSION = '.phpbin';

    private const LEGACY_EXTENSION = '.phpbin.gz';

    private TextResultCodec $codec;

    public function __construct(?TextResultCodec $codec = null)
    {
        $this->codec = $codec ?? new TextResultCodec();
    }

    /**
     * @return list<TextFileResult>|null
     */
    public function load(TextIndex $index, string $pattern, TextSearchOptions $options): ?array
    {
        $path = $this->locateCachePath($index->indexPath, $pattern, $options);

        if ($path === null) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        $payload = $this->decodePayload($contents, $path);

        if (
            !is_array($payload)
            || !is_int($payload['built_at'] ?? null)
            || !is_array($payload['results'] ?? null)
        ) {
            throw new \RuntimeException(sprintf('Indexed query cache is corrupt: %s', $path));
        }

        if ($payload['built_at'] !== $index->builtAt) {
            return null;
        }

        return $this->codec->decode($payload['results']);
    }

    /**
     * @param list<TextFileResult> $results
     */
    public function save(TextIndex $index, string $pattern, TextSearchOptions $options, array $results): void
    {
        $path = $this->cachePath($index->indexPath, $pattern, $options);
        $directory = dirname($path);
        Filesystem::ensureDirectory($directory);
        $temporaryPath = $path . '.tmp';
        $legacyPath = $this->legacyCachePath($index->indexPath, $pattern, $options);
        $payload = serialize([
            'built_at' => $index->builtAt,
            'results' => $this->codec->encode($results),
        ]);

        if (@file_put_contents($temporaryPath, $payload) === false) {
            throw new \RuntimeException(sprintf('Failed to write indexed query cache: %s', $path));
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new \RuntimeException(sprintf('Failed to finalize indexed query cache: %s', $path));
        }

        @unlink($legacyPath);
    }

    public function clear(string $indexPath): void
    {
        Filesystem::remove($this->directoryPath($indexPath));
    }

    /**
     * @return array{count: int, size: int}
     */
    public function stats(string $indexPath): array
    {
        $directory = $this->directoryPath($indexPath);

        if (!is_dir($directory)) {
            return ['count' => 0, 'size' => 0];
        }

        $count = 0;
        $size = 0;

        foreach (glob($directory . '/*.phpbin*') ?: [] as $path) {
            $count++;
            $entrySize = filesize($path);
            $size += is_int($entrySize) ? $entrySize : 0;
        }

        return ['count' => $count, 'size' => $size];
    }

    private function directoryPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::DIRECTORY;
    }

    private function locateCachePath(string $indexPath, string $pattern, TextSearchOptions $options): ?string
    {
        $path = $this->cachePath($indexPath, $pattern, $options);

        if (is_file($path)) {
            return $path;
        }

        $legacyPath = $this->legacyCachePath($indexPath, $pattern, $options);

        return is_file($legacyPath) ? $legacyPath : null;
    }

    private function cachePath(string $indexPath, string $pattern, TextSearchOptions $options): string
    {
        $key = sha1(json_encode([
            'pattern' => $pattern,
            'fixed' => $options->fixedString,
            'case_insensitive' => $options->caseInsensitive,
            'whole_word' => $options->wholeWord,
            'count_only' => $options->countOnly,
            'files_with_matches' => $options->filesWithMatches,
            'files_without_matches' => $options->filesWithoutMatches,
            'quiet' => $options->quiet,
            'collect_captures' => $options->collectCaptures,
        ], JSON_THROW_ON_ERROR));

        return $this->directoryPath($indexPath) . '/' . $key . self::EXTENSION;
    }

    private function legacyCachePath(string $indexPath, string $pattern, TextSearchOptions $options): string
    {
        return substr($this->cachePath($indexPath, $pattern, $options), 0, -strlen(self::EXTENSION)) . self::LEGACY_EXTENSION;
    }

    private function decodePayload(string $contents, string $path): mixed
    {
        if (str_ends_with($path, self::LEGACY_EXTENSION)) {
            $contents = gzdecode($contents);

            if ($contents === false) {
                throw new \RuntimeException(sprintf('Indexed query cache is corrupt: %s', $path));
            }
        }

        return unserialize($contents, ['allowed_classes' => false]);
    }
}
