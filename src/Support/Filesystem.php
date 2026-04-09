<?php

declare(strict_types=1);

namespace Phgrep\Support;

final class Filesystem
{
    public static function ensureDirectory(string $path): void
    {
        if ($path === '') {
            return;
        }

        if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Failed to create directory: %s', $path));
        }
    }

    public static function copyDirectory(string $source, string $destination): void
    {
        self::ensureDirectory($destination);
        $iterator = new \FilesystemIterator($source, \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            $targetPath = $destination . '/' . $entry->getFilename();

            if ($entry->isDir() && !$entry->isLink()) {
                self::copyDirectory($entry->getPathname(), $targetPath);
                continue;
            }

            if ($entry->isLink()) {
                $linkTarget = readlink($entry->getPathname());

                if ($linkTarget === false || !@symlink($linkTarget, $targetPath)) {
                    throw new \RuntimeException(sprintf('Failed to copy symlink: %s', $entry->getPathname()));
                }

                continue;
            }

            if (!@copy($entry->getPathname(), $targetPath)) {
                throw new \RuntimeException(sprintf('Failed to copy file: %s', $entry->getPathname()));
            }
        }
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

        $iterator = new \FilesystemIterator($path, \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo) {
                self::remove($entry->getPathname());
            }
        }

        @rmdir($path);
    }

    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    public static function relativePath(string $basePath, string $path): string
    {
        $basePath = self::normalizePath(realpath($basePath) ?: $basePath);
        $path = self::normalizePath(realpath($path) ?: $path);

        if ($path === $basePath) {
            return '.';
        }

        if (str_starts_with($path, $basePath . '/')) {
            return substr($path, strlen($basePath) + 1);
        }

        return $path;
    }

    public static function lineCount(string $path): int
    {
        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            return 0;
        }

        return substr_count($contents, "\n") + (str_ends_with($contents, "\n") ? 0 : 1);
    }
}
