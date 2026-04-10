<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Support;

use Greph\Support\Filesystem;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilesystemTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('filesystem');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itCreatesCopiesAndRemovesDirectories(): void
    {
        $source = $this->workspace . '/source';
        $destination = $this->workspace . '/destination';

        Filesystem::ensureDirectory($source . '/nested');
        file_put_contents($source . '/nested/file.txt', "line one\nline two");
        symlink('nested/file.txt', $source . '/link.txt');

        Filesystem::copyDirectory($source, $destination);

        $this->assertFileExists($destination . '/nested/file.txt');
        $this->assertSame("line one\nline two", file_get_contents($destination . '/nested/file.txt'));
        $this->assertTrue(is_link($destination . '/link.txt'));
        $this->assertSame('nested/file.txt', readlink($destination . '/link.txt'));

        Filesystem::remove($destination);

        $this->assertDirectoryDoesNotExist($destination);
    }

    #[Test]
    public function itNormalizesRelativePathsAndCountsLines(): void
    {
        $path = Workspace::writeFile($this->workspace, 'nested/file.txt', "alpha\nbeta\ngamma");
        symlink($path, $this->workspace . '/link.txt');

        $this->assertSame('nested/file.txt', Filesystem::relativePath($this->workspace, $path));
        $this->assertSame('link.txt', Filesystem::relativePath($this->workspace, $this->workspace . '/link.txt'));
        $this->assertSame('.', Filesystem::relativePath($this->workspace, $this->workspace));
        $this->assertSame('/tmp/path', Filesystem::normalizePath('/tmp/path/'));
        $this->assertSame(3, Filesystem::lineCount($path));
        $this->assertSame(0, Filesystem::lineCount(Workspace::writeFile($this->workspace, 'empty.txt', '')));
        $this->assertSame('/tmp/outside.txt', Filesystem::relativePath($this->workspace, '/tmp/outside.txt'));
    }

    #[Test]
    public function itHandlesEmptyMissingAndFailureCases(): void
    {
        Filesystem::ensureDirectory('');
        Filesystem::remove($this->workspace . '/missing');

        $blockingFile = Workspace::writeFile($this->workspace, 'blocking.txt', 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create directory: ' . $blockingFile);
        Filesystem::ensureDirectory($blockingFile);
    }

    #[Test]
    public function itThrowsForFailedSymlinkAndFileCopies(): void
    {
        $symlinkSource = $this->workspace . '/symlink-source';
        $symlinkDestination = $this->workspace . '/symlink-destination';
        $fileSource = $this->workspace . '/file-source';
        $fileDestination = $this->workspace . '/file-destination';

        Filesystem::ensureDirectory($symlinkSource);
        Filesystem::ensureDirectory($fileSource);
        Workspace::writeFile($symlinkSource, 'target.txt', 'target');
        Workspace::writeFile($fileSource, 'plain.txt', 'plain');
        symlink('target.txt', $symlinkSource . '/link.txt');
        Filesystem::ensureDirectory($symlinkDestination);
        Filesystem::ensureDirectory($fileDestination . '/plain.txt');
        Workspace::writeFile($symlinkDestination, 'link.txt', 'occupied');

        try {
            Filesystem::copyDirectory($symlinkSource, $symlinkDestination);
            $this->fail('Expected symlink copy failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to copy symlink', $exception->getMessage());
        }

        try {
            Filesystem::copyDirectory($fileSource, $fileDestination);
            $this->fail('Expected file copy failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to copy file', $exception->getMessage());
        }
    }
}
