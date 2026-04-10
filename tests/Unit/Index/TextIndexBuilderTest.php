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
        $index = $store->load($buildResult->indexPath, true, true);

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
        $this->assertContains('function', $index->wordForward[$recordsByPath['src/App.php']['id']]);
        $this->assertEqualsCanonicalizing(
            [$recordsByPath['.hidden/Secret.php']['id'], $recordsByPath['ignored.php']['id'], $recordsByPath['src/App.php']['id']],
            $index->wordPostings['function'],
        );

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

    #[Test]
    public function itCoversPrivateIndexBuilderBranches(): void
    {
        $builder = new TextIndexBuilder();
        $otherWorkspace = Workspace::createDirectory('text-index-builder-other');

        try {
            Workspace::writeFile($otherWorkspace, 'src/Other.php', "<?php\nfunction other(): void {}\n");
            $foreignResult = $builder->build($otherWorkspace);

            try {
                $builder->refresh($this->workspace, $foreignResult->indexPath);
                self::fail('Expected index root mismatch.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Index root mismatch', $exception->getMessage());
            }
        } finally {
            Workspace::remove($otherWorkspace);
        }

        $absoluteIndexPath = $this->invokeMethod($builder, 'resolveIndexPath', $this->workspace, '/tmp/custom-text-index');
        $relativeIndexPath = $this->invokeMethod($builder, 'resolveIndexPath', $this->workspace, '.alt-index');
        $scannedFiles = $this->invokeMethod($builder, 'scanFiles', $this->workspace, $this->workspace . '/.phgrep-index');
        $postings = $this->invokeMethod($builder, 'buildPostings', [3 => ['ghi', 'abc'], 1 => ['abc', 'def']]);
        $missingTerms = $this->invokeMethod($builder, 'extractFileTerms', $this->workspace . '/missing.txt');
        $words = $this->invokeMethod($builder, 'extractFileWords', "<?php\nFunction function OTHER\n");
        $hidden = $this->invokeMethod($builder, 'isHiddenPath', '.hidden/Secret.php');
        $visible = $this->invokeMethod($builder, 'isHiddenPath', 'src/App.php');

        $this->assertSame('/tmp/custom-text-index', $absoluteIndexPath);
        $this->assertSame($this->workspace . '/.alt-index', $relativeIndexPath);
        $this->assertCount(4, $scannedFiles);
        $this->assertSame([1, 3], $postings['abc']);
        $this->assertSame(['trigrams' => [], 'words' => []], $missingTerms);
        $this->assertSame(['function', 'other', 'php'], $words);
        $this->assertTrue($hidden);
        $this->assertFalse($visible);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Index root does not exist');

        $this->invokeMethod($builder, 'resolveRootPath', $this->workspace . '/missing');
    }

    #[Test]
    public function itRefreshesByBuildingWhenTheIndexIsMissing(): void
    {
        $builder = new TextIndexBuilder();
        $result = $builder->refresh($this->workspace);

        $this->assertSame($this->workspace . '/.phgrep-index', $result->indexPath);
        $this->assertSame($result->fileCount, $result->addedFiles);
        $this->assertGreaterThan(0, $result->trigramCount);
    }

    /**
     * @return mixed
     */
    private function invokeMethod(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }
}
