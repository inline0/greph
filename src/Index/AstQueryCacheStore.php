<?php

declare(strict_types=1);

namespace Phgrep\Index;

use Phgrep\Ast\AstMatch;
use Phgrep\Ast\AstSearchOptions;
use Phgrep\Support\Filesystem;

final class AstQueryCacheStore
{
    private const DIRECTORY = 'queries';

    /**
     * @return list<AstMatch>|null
     */
    public function load(AstCache $cache, string $pattern, AstSearchOptions $options): ?array
    {
        $path = $this->cachePath($cache->indexPath, $pattern, $options);

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        $decoded = gzdecode($contents);

        if ($decoded === false) {
            throw new \RuntimeException(sprintf('AST query cache is corrupt: %s', $path));
        }

        $payload = unserialize($decoded, ['allowed_classes' => true]);

        if (
            !is_array($payload)
            || !is_int($payload['built_at'] ?? null)
            || !is_array($payload['matches'] ?? null)
        ) {
            throw new \RuntimeException(sprintf('AST query cache is corrupt: %s', $path));
        }

        if ($payload['built_at'] !== $cache->builtAt) {
            return null;
        }

        $matches = $payload['matches'];

        foreach ($matches as $match) {
            if (!$match instanceof AstMatch) {
                throw new \RuntimeException(sprintf('AST query cache is corrupt: %s', $path));
            }
        }

        /** @var list<AstMatch> $matches */
        return $matches;
    }

    /**
     * @param list<AstMatch> $matches
     */
    public function save(AstCache $cache, string $pattern, AstSearchOptions $options, array $matches): void
    {
        $path = $this->cachePath($cache->indexPath, $pattern, $options);
        $directory = dirname($path);
        Filesystem::ensureDirectory($directory);
        $temporaryPath = $path . '.tmp';
        $payload = gzencode(serialize([
            'built_at' => $cache->builtAt,
            'matches' => $matches,
        ]), 1);

        if ($payload === false || @file_put_contents($temporaryPath, $payload) === false) {
            throw new \RuntimeException(sprintf('Failed to write AST query cache: %s', $path));
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new \RuntimeException(sprintf('Failed to finalize AST query cache: %s', $path));
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

    private function cachePath(string $indexPath, string $pattern, AstSearchOptions $options): string
    {
        $key = sha1(json_encode([
            'pattern' => $pattern,
            'language' => $options->language,
        ], JSON_THROW_ON_ERROR));

        return $this->directoryPath($indexPath) . '/' . $key . '.phpbin.gz';
    }
}
