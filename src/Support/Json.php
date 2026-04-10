<?php

declare(strict_types=1);

namespace Greph\Support;

final class Json
{
    /**
     * @return array<string, mixed>|list<mixed>
     */
    public static function decode(string $json): array
    {
        /** @var array<string, mixed>|list<mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    public static function decodeFile(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Failed to read JSON file: %s', $path));
        }

        return self::decode($contents);
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    public static function encodeFile(string $path, array $data): void
    {
        Filesystem::ensureDirectory(dirname($path));
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (@file_put_contents($path, $encoded . PHP_EOL) === false) {
            throw new \RuntimeException(sprintf('Failed to write JSON file: %s', $path));
        }
    }
}
