<?php

declare(strict_types=1);

namespace Phgrep\Index;

use Phgrep\Support\Filesystem;
use Phgrep\Text\TextFileResult;
use Phgrep\Text\TextResultCodec;
use Phgrep\Text\TextSearchOptions;

final class TextQueryCacheStore
{
    private const DIRECTORY = 'queries';

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
        $path = $this->cachePath($index->indexPath, $pattern, $options);

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        $decoded = gzdecode($contents);

        if ($decoded === false) {
            throw new \RuntimeException(sprintf('Indexed query cache is corrupt: %s', $path));
        }

        $payload = unserialize($decoded, ['allowed_classes' => false]);

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
        $payload = gzencode(serialize([
            'built_at' => $index->builtAt,
            'results' => $this->codec->encode($results),
        ]), 1);

        if ($payload === false || @file_put_contents($temporaryPath, $payload) === false) {
            throw new \RuntimeException(sprintf('Failed to write indexed query cache: %s', $path));
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new \RuntimeException(sprintf('Failed to finalize indexed query cache: %s', $path));
        }
    }

    public function clear(string $indexPath): void
    {
        Filesystem::remove($this->directoryPath($indexPath));
    }

    private function directoryPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::DIRECTORY;
    }

    private function cachePath(string $indexPath, string $pattern, TextSearchOptions $options): string
    {
        $key = sha1(json_encode([
            'pattern' => $pattern,
            'fixed' => $options->fixedString,
            'case_insensitive' => $options->caseInsensitive,
            'whole_word' => $options->wholeWord,
        ], JSON_THROW_ON_ERROR));

        return $this->directoryPath($indexPath) . '/' . $key . '.phpbin.gz';
    }
}
