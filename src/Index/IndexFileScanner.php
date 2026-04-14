<?php

declare(strict_types=1);

namespace Greph\Index;

use Greph\Support\Filesystem;
use Greph\Walker\FileTypeFilter;
use Greph\Walker\FileWalker;
use Greph\Walker\GitignoreFilter;
use Greph\Walker\WalkOptions;

final class IndexFileScanner
{
    private const MAX_INDEXED_FILE_SIZE_BYTES = 10485760;

    /**
     * @var list<string>
     */
    private const INTERNAL_INDEX_DIRECTORIES = [
        '.greph-index',
        '.greph-ast-index',
        '.greph-ast-cache',
    ];

    private FileWalker $fileWalker;

    public function __construct(?FileWalker $fileWalker = null)
    {
        $this->fileWalker = $fileWalker ?? new FileWalker();
    }

    /**
     * @return list<array{absolutePath: string, relativePath: string, size: int, mtime: int, hidden: bool, ignored: bool, order: int}>
     */
    public function scanText(string $rootPath, string $indexPath): array
    {
        return $this->scan($rootPath, $indexPath, false);
    }

    /**
     * @return list<array{absolutePath: string, relativePath: string, size: int, mtime: int, hidden: bool, ignored: bool, order: int}>
     */
    public function scanPhp(string $rootPath, string $indexPath): array
    {
        return $this->scan($rootPath, $indexPath, true);
    }

    /**
     * @return list<array{absolutePath: string, relativePath: string, size: int, mtime: int, hidden: bool, ignored: bool, order: int}>
     */
    private function scan(string $rootPath, string $indexPath, bool $phpOnly): array
    {
        $files = $this->fileWalker->walk(
            $rootPath,
            new WalkOptions(
                respectIgnore: false,
                includeHidden: true,
                skipBinaryFiles: true,
                includeGitDirectory: false,
                fileTypeFilter: $phpOnly ? new FileTypeFilter(['php']) : null,
                maxFileSizeBytes: self::MAX_INDEXED_FILE_SIZE_BYTES,
            ),
        );
        $ignoreFilter = new GitignoreFilter($rootPath);
        $scannedFiles = [];
        $order = 0;

        foreach ($files as $file) {
            if ($this->isInsideIndexPath($file, $indexPath) || $this->isInsideInternalIndexDirectory($rootPath, $file)) {
                continue;
            }

            $relativePath = Filesystem::relativePath($rootPath, $file);
            $size = filesize($file);
            $mtime = filemtime($file);

            $scannedFiles[] = [
                'absolutePath' => $file,
                'relativePath' => $relativePath,
                'size' => is_int($size) ? $size : 0,
                'mtime' => is_int($mtime) ? $mtime : 0,
                'hidden' => $this->isHiddenPath($relativePath),
                'ignored' => $ignoreFilter->shouldIgnore($file, false),
                'order' => $order++,
            ];
        }

        return $scannedFiles;
    }

    private function isInsideIndexPath(string $path, string $indexPath): bool
    {
        $path = Filesystem::normalizePath($path);
        $indexPath = Filesystem::normalizePath($indexPath);

        return $path === $indexPath || str_starts_with($path, $indexPath . '/');
    }

    private function isInsideInternalIndexDirectory(string $rootPath, string $path): bool
    {
        $relativePath = Filesystem::relativePath($rootPath, $path);

        foreach (explode('/', $relativePath) as $segment) {
            if (in_array($segment, self::INTERNAL_INDEX_DIRECTORIES, true)) {
                return true;
            }
        }

        return false;
    }

    private function isHiddenPath(string $relativePath): bool
    {
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return true;
            }
        }

        return false;
    }
}
