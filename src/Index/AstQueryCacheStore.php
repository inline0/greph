<?php

declare(strict_types=1);

namespace Phgrep\Index;

use Phgrep\Ast\AstMatch;
use Phgrep\Ast\AstMatchCodec;
use Phgrep\Ast\AstSearchOptions;
use Phgrep\Support\Filesystem;

final class AstQueryCacheStore
{
    private const DIRECTORY = 'queries';

    private const EXTENSION = '.phpbin';

    private const LEGACY_EXTENSION = '.phpbin.gz';

    private AstMatchCodec $codec;

    public function __construct(?AstMatchCodec $codec = null)
    {
        $this->codec = $codec ?? new AstMatchCodec();
    }

    /**
     * @return list<AstMatch>|null
     */
    public function load(string $indexPath, int $builtAt, string $pattern, AstSearchOptions $options): ?array
    {
        $path = $this->locateCachePath($indexPath, $pattern, $options);

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
            || !is_array($payload['matches'] ?? null)
        ) {
            throw new \RuntimeException(sprintf('AST query cache is corrupt: %s', $path));
        }

        if ($payload['built_at'] !== $builtAt) {
            return null;
        }

        $matches = $payload['matches'];

        if ($matches === []) {
            return [];
        }

        if ($matches[0] instanceof AstMatch) {
            foreach ($matches as $match) {
                if (!$match instanceof AstMatch) {
                    throw new \RuntimeException(sprintf('AST query cache is corrupt: %s', $path));
                }
            }

            /** @var list<AstMatch> $matches */
            return $matches;
        }

        try {
            return $this->codec->decode($matches);
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException(sprintf('AST query cache is corrupt: %s', $path), 0, $exception);
        }
    }

    /**
     * @param list<AstMatch> $matches
     */
    public function save(string $indexPath, int $builtAt, string $pattern, AstSearchOptions $options, array $matches): void
    {
        $path = $this->cachePath($indexPath, $pattern, $options);
        $directory = dirname($path);
        Filesystem::ensureDirectory($directory);
        $temporaryPath = $path . '.tmp';
        $legacyPath = $this->legacyCachePath($indexPath, $pattern, $options);
        $payload = serialize([
            'built_at' => $builtAt,
            'matches' => $this->codec->encode($matches),
        ]);

        if (@file_put_contents($temporaryPath, $payload) === false) {
            throw new \RuntimeException(sprintf('Failed to write AST query cache: %s', $path));
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new \RuntimeException(sprintf('Failed to finalize AST query cache: %s', $path));
        }

        @unlink($legacyPath);
    }

    public function clear(string $indexPath): void
    {
        Filesystem::remove($this->directoryPath($indexPath));
    }

    private function directoryPath(string $indexPath): string
    {
        return Filesystem::normalizePath($indexPath) . '/' . self::DIRECTORY;
    }

    private function locateCachePath(string $indexPath, string $pattern, AstSearchOptions $options): ?string
    {
        $path = $this->cachePath($indexPath, $pattern, $options);

        if (is_file($path)) {
            return $path;
        }

        $legacyPath = $this->legacyCachePath($indexPath, $pattern, $options);

        return is_file($legacyPath) ? $legacyPath : null;
    }

    private function cachePath(string $indexPath, string $pattern, AstSearchOptions $options): string
    {
        $key = sha1(json_encode([
            'pattern' => $pattern,
            'language' => $options->language,
        ], JSON_THROW_ON_ERROR));

        return $this->directoryPath($indexPath) . '/' . $key . self::EXTENSION;
    }

    private function legacyCachePath(string $indexPath, string $pattern, AstSearchOptions $options): string
    {
        return substr($this->cachePath($indexPath, $pattern, $options), 0, -strlen(self::EXTENSION)) . self::LEGACY_EXTENSION;
    }

    private function decodePayload(string $contents, string $path): mixed
    {
        if (str_ends_with($path, self::LEGACY_EXTENSION)) {
            $contents = gzdecode($contents);

            if ($contents === false) {
                throw new \RuntimeException(sprintf('AST query cache is corrupt: %s', $path));
            }
        }

        return unserialize($contents, ['allowed_classes' => true]);
    }
}
