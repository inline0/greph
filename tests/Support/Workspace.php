<?php

declare(strict_types=1);

namespace Greph\Tests\Support;

use Greph\Support\Filesystem;

final class Workspace
{
    public static function createDirectory(string $prefix): string
    {
        $path = self::root() . '/' . $prefix . '-' . bin2hex(random_bytes(8));

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Failed to create workspace directory: %s', $path));
        }

        $realPath = realpath($path);

        if ($realPath === false) {
            throw new \RuntimeException(sprintf('Failed to resolve workspace directory: %s', $path));
        }

        return str_replace('\\', '/', $realPath);
    }

    public static function writeFile(string $root, string $relativePath, string $contents): string
    {
        $path = $root . '/' . ltrim($relativePath, '/');
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Failed to create directory: %s', $directory));
        }

        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(sprintf('Failed to write file: %s', $path));
        }

        return $path;
    }

    public static function remove(string $path): void
    {
        Filesystem::remove($path);
    }

    public static function copyDirectory(string $source, string $destination): void
    {
        Filesystem::copyDirectory($source, $destination);
    }

    private static function root(): string
    {
        $path = sys_get_temp_dir() . '/greph-tests';

        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Failed to create workspace root: %s', $path));
        }

        return $path;
    }
}
