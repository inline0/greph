<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Walker;

use Phgrep\Exceptions\WalkerException;
use Phgrep\Tests\Support\Workspace;
use Phgrep\Walker\FileTypeFilter;
use Phgrep\Walker\FileWalker;
use Phgrep\Walker\WalkOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileWalkerTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('file-walker');

        Workspace::writeFile($this->workspace, '.gitignore', "vendor/\n");
        Workspace::writeFile($this->workspace, '.phgrepignore', "cache/\n");
        Workspace::writeFile($this->workspace, '.git/info/exclude', "notes.txt\n");
        Workspace::writeFile($this->workspace, 'src/.gitignore', "*.skip\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n");
        Workspace::writeFile($this->workspace, 'src/AppTest.php', "<?php\n");
        Workspace::writeFile($this->workspace, 'src/.hidden.php', "<?php\n");
        Workspace::writeFile($this->workspace, 'src/ignored.skip', "skip\n");
        Workspace::writeFile($this->workspace, 'vendor/pkg/lib.php', "<?php\n");
        Workspace::writeFile($this->workspace, 'cache/tmp.php', "<?php\n");
        Workspace::writeFile($this->workspace, 'notes.txt', "notes\n");
        Workspace::writeFile($this->workspace, 'assets/logo.bin', "GIF89a\0binary");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itWalksDirectoriesAndRespectsIgnoreFilesByDefault(): void
    {
        $files = (new FileWalker())->walk($this->workspace)->paths();
        $expected = [
            $this->workspace . '/assets/logo.bin',
            $this->workspace . '/src/App.php',
            $this->workspace . '/src/AppTest.php',
        ];

        sort($files);
        sort($expected);

        $this->assertSame(
            $expected,
            $files
        );
    }

    #[Test]
    public function itCanIncludeHiddenFilesFilterTypesAndSkipBinaryFiles(): void
    {
        $options = new WalkOptions(
            includeHidden: true,
            skipBinaryFiles: true,
            fileTypeFilter: new FileTypeFilter(['php']),
        );

        $files = (new FileWalker())->walk($this->workspace, $options)->paths();
        $expected = [
            $this->workspace . '/src/.hidden.php',
            $this->workspace . '/src/App.php',
            $this->workspace . '/src/AppTest.php',
        ];

        sort($files);
        sort($expected);

        $this->assertSame(
            $expected,
            $files
        );
    }

    #[Test]
    public function itThrowsForMissingPaths(): void
    {
        $this->expectException(WalkerException::class);

        (new FileWalker())->walk($this->workspace . '/missing');
    }
}
