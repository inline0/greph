<?php

declare(strict_types=1);

namespace Phgrep\Walker;

use Phgrep\Exceptions\WalkerException;

final class FileWalker
{
    private BinaryDetector $binaryDetector;

    public function __construct(?BinaryDetector $binaryDetector = null)
    {
        $this->binaryDetector = $binaryDetector ?? new BinaryDetector();
    }

    /**
     * @param string|list<string> $paths
     */
    public function walk(string|array $paths, ?WalkOptions $options = null): FileList
    {
        $options ??= new WalkOptions();
        $paths = is_array($paths) ? $paths : [$paths];

        $files = [];
        $visitedDirectories = [];

        foreach ($paths as $path) {
            $resolvedPath = realpath($path);

            if ($resolvedPath === false) {
                throw new WalkerException(sprintf('Path does not exist: %s', $path));
            }

            $resolvedPath = $this->normalizePath($resolvedPath);
            $ignoreFilter = $options->respectIgnore && is_dir($resolvedPath)
                ? new GitignoreFilter($resolvedPath)
                : null;

            if (is_file($resolvedPath)) {
                if ($this->shouldIncludeFile($resolvedPath, $options, true)) {
                    $files[] = $resolvedPath;
                }

                continue;
            }

            $this->walkDirectory($resolvedPath, $options, $ignoreFilter, $files, $visitedDirectories);
        }

        return new FileList($files);
    }

    /**
     * @param list<string> $files
     * @param array<string, true> $visitedDirectories
     */
    private function walkDirectory(
        string $directory,
        WalkOptions $options,
        ?GitignoreFilter $ignoreFilter,
        array &$files,
        array &$visitedDirectories,
    ): void {
        $realDirectory = realpath($directory);

        if ($realDirectory === false) {
            return;
        }

        $realDirectory = $this->normalizePath($realDirectory);

        if (isset($visitedDirectories[$realDirectory])) {
            return;
        }

        $visitedDirectories[$realDirectory] = true;
        $entries = [];

        foreach (new \FilesystemIterator($directory, \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entries[] = $entry;
            }
        }

        usort(
            $entries,
            static fn (\SplFileInfo $left, \SplFileInfo $right): int => strcmp($left->getFilename(), $right->getFilename())
        );

        foreach ($entries as $entry) {
            $path = $this->normalizePath($entry->getPathname());
            $name = $entry->getFilename();
            $isLink = $entry->isLink();

            if ($isLink && !$options->followSymlinks) {
                continue;
            }

            $isDirectory = $entry->isDir();

            if (!$options->includeHidden && $this->isHidden($name)) {
                continue;
            }

            if (!$options->includeGitDirectory && $isDirectory && $name === '.git') {
                continue;
            }

            if (
                $ignoreFilter !== null
                && $ignoreFilter->shouldIgnore($path, $isDirectory)
            ) {
                continue;
            }

            if ($isDirectory) {
                $this->walkDirectory($path, $options, $ignoreFilter, $files, $visitedDirectories);
                continue;
            }

            if ($this->shouldIncludeFile($path, $options, false)) {
                $files[] = $path;
            }
        }
    }

    private function shouldIncludeFile(string $path, WalkOptions $options, bool $isRootInput): bool
    {
        $name = basename($path);

        if (!$isRootInput && !$options->includeHidden && $this->isHidden($name)) {
            return false;
        }

        $size = filesize($path);

        if (
            $options->maxFileSizeBytes > 0
            && $size !== false
            && $size > $options->maxFileSizeBytes
        ) {
            return false;
        }

        if ($options->fileTypeFilter !== null && !$options->fileTypeFilter->matches($path)) {
            return false;
        }

        if ($options->skipBinaryFiles && $this->binaryDetector->isBinaryFile($path)) {
            return false;
        }

        return true;
    }

    private function isHidden(string $name): bool
    {
        return $name !== '' && $name[0] === '.';
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
