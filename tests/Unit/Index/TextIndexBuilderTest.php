<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Index;

use Phgrep\Index\TextIndexBuilder;
use Phgrep\Index\TextIndexStore;
use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextIndexBuilderTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('text-index-builder');
        Workspace::writeFile($this->workspace, '.gitignore', "ignored.php\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction visible(): void {}\n");
        Workspace::writeFile($this->workspace, '.hidden/Secret.php', "<?php\nfunction hidden(): void {}\n");
        Workspace::writeFile($this->workspace, 'ignored.php', "<?php\nfunction ignored(): void {}\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itBuildsAndRefreshesATrigramIndex(): void
    {
        $builder = new TextIndexBuilder();
        $store = new TextIndexStore();

        $buildResult = $builder->build($this->workspace);
        $index = $store->load($buildResult->indexPath, true);

        $this->assertSame(4, $buildResult->fileCount);
        $this->assertGreaterThan(0, $buildResult->trigramCount);
        $this->assertSame($this->workspace . '/.phgrep-index', $buildResult->indexPath);
        $this->assertSame($this->workspace, $index->rootPath);
        $this->assertCount(4, $index->files);
        $this->assertTrue($store->exists($buildResult->indexPath));

        $recordsByPath = [];

        foreach ($index->files as $file) {
            $recordsByPath[$file['p']] = $file;
        }

        $this->assertTrue($recordsByPath['.hidden/Secret.php']['h']);
        $this->assertTrue($recordsByPath['ignored.php']['g']);
        $this->assertContains('fun', $index->forward[$recordsByPath['src/App.php']['id']]);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction changed(): void {}\n");
        Workspace::writeFile($this->workspace, 'src/New.php', "<?php\nfunction added(): void {}\n");
        @unlink($this->workspace . '/.hidden/Secret.php');

        $refreshResult = $builder->refresh($this->workspace);
        $refreshedIndex = $store->load($refreshResult->indexPath, true);

        $this->assertSame(1, $refreshResult->addedFiles);
        $this->assertSame(1, $refreshResult->updatedFiles);
        $this->assertSame(1, $refreshResult->deletedFiles);
        $this->assertSame(2, $refreshResult->unchangedFiles);
        $this->assertCount(4, $refreshedIndex->files);

        $refreshedPaths = array_map(
            static fn (array $file): string => $file['p'],
            $refreshedIndex->files,
        );

        $this->assertNotContains('.phgrep-index/files.phpbin', $refreshedPaths);
        $this->assertNotContains('.phgrep-index/metadata.phpbin', $refreshedPaths);
        $this->assertNotContains('.phgrep-index/postings.phpbin', $refreshedPaths);
    }
}
