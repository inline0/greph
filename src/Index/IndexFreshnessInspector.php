<?php

declare(strict_types=1);

namespace Greph\Index;

final class IndexFreshnessInspector
{
    private IndexFileScanner $scanner;

    public function __construct(?IndexFileScanner $scanner = null)
    {
        $this->scanner = $scanner ?? new IndexFileScanner();
    }

    public function inspectText(TextIndex $index): IndexFreshness
    {
        return $this->inspect($index->files, $this->scanner->scanText($index->rootPath, $index->indexPath));
    }

    public function inspectAstIndex(AstIndex $index): IndexFreshness
    {
        return $this->inspect($index->files, $this->scanner->scanPhp($index->rootPath, $index->indexPath));
    }

    public function inspectAstCache(AstCache $cache): IndexFreshness
    {
        return $this->inspect($cache->files, $this->scanner->scanPhp($cache->rootPath, $cache->indexPath));
    }

    /**
     * @param list<array{id: int, p: string, s: int, m: int, h: bool, g: bool, o: int}> $indexedFiles
     * @param list<array{absolutePath: string, relativePath: string, size: int, mtime: int, hidden: bool, ignored: bool, order: int}> $scannedFiles
     */
    private function inspect(array $indexedFiles, array $scannedFiles): IndexFreshness
    {
        $indexedByPath = [];

        foreach ($indexedFiles as $file) {
            $indexedByPath[$file['p']] = $file;
        }

        $addedFiles = 0;
        $updatedFiles = 0;
        $unchangedFiles = 0;
        $changedBytes = 0;

        foreach ($scannedFiles as $scan) {
            $existing = $indexedByPath[$scan['relativePath']] ?? null;

            if ($existing === null) {
                $addedFiles++;
                $changedBytes += $scan['size'];
                continue;
            }

            if ($existing['s'] === $scan['size'] && $existing['m'] === $scan['mtime']) {
                $unchangedFiles++;
            } else {
                $updatedFiles++;
                $changedBytes += max((int) $existing['s'], $scan['size']);
            }

            unset($indexedByPath[$scan['relativePath']]);
        }

        $deletedFiles = count($indexedByPath);

        foreach ($indexedByPath as $file) {
            $changedBytes += (int) $file['s'];
        }

        return new IndexFreshness(
            stale: $addedFiles > 0 || $updatedFiles > 0 || $deletedFiles > 0,
            addedFiles: $addedFiles,
            updatedFiles: $updatedFiles,
            deletedFiles: $deletedFiles,
            unchangedFiles: $unchangedFiles,
            changedBytes: $changedBytes,
        );
    }
}
