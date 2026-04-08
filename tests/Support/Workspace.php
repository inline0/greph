<?php

declare(strict_types=1);

namespace Phgrep\Tests\Support;

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
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $entries = new \FilesystemIterator($path, \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS);

        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                self::remove($entry->getPathname());
            }
        }

        @rmdir($path);
    }

    private static function root(): string
    {
        $path = sys_get_temp_dir() . '/phgrep-tests';

        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Failed to create workspace root: %s', $path));
        }

        return $path;
    }
}
