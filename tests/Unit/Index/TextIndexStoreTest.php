<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Index;

use Greph\Index\TextIndex;
use Greph\Index\TextIndexBuilder;
use Greph\Index\TextIndexStore;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextIndexStoreTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('text-index-store');
        Workspace::writeFile($this->workspace, '.gitignore', "ignored.php\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction visible(): void {}\n");
        Workspace::writeFile($this->workspace, 'src/Other.php', "<?php\nfunction other(): void {}\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itLoadsRoundTripsLocatesAndSupportsLegacyPostings(): void
    {
        $builder = new TextIndexBuilder();
        $store = new TextIndexStore();
        $result = $builder->build($this->workspace);

        $index = $store->load($result->indexPath, includeForward: true, includePostings: true);
        $selected = $store->loadSelectedPostings($result->indexPath, ['fun', 'xyz']);
        $selectedWords = $store->loadSelectedWordPostings($result->indexPath, ['function', 'missing']);

        $this->assertSame($this->workspace . '/.greph-index', $store->defaultPath($this->workspace));
        $this->assertSame($result->indexPath, $store->locateFrom($this->workspace . '/src/App.php'));
        $this->assertTrue($store->exists($result->indexPath));
        $this->assertSame($store->version(), $index->version);
        $this->assertNotSame([], $index->postings);
        $this->assertNotSame([], $index->wordPostings);
        $this->assertArrayHasKey('fun', $selected);
        $this->assertSame($index->wordPostings['function'], $selectedWords['function']);
        $this->assertSame([], $store->loadSelectedPostings($result->indexPath, []));
        $this->assertSame([], $store->loadSelectedWordPostings($result->indexPath, []));

        file_put_contents($result->indexPath . '/postings.phpbin', serialize(['t:fun' => [1, 2]]));
        Workspace::remove($result->indexPath . '/postings');

        $legacySelected = $store->loadSelectedPostings($result->indexPath, ['fun']);
        $legacyIndex = $store->load($result->indexPath, includePostings: true);

        $this->assertSame([1, 2], $legacySelected['fun']);
        $this->assertSame([1, 2], $legacyIndex->postings['fun']);
        $this->assertSame($index->wordPostings, $legacyIndex->wordPostings);

        Workspace::writeFile($this->workspace, '.greph-index/files.phpbin', serialize([
            ['id' => 1, 'p' => 'src/App.php', 's' => 10, 'm' => 1, 'h' => false, 'g' => false, 't' => ['fun', 99], 'o' => 0],
        ]));
        Workspace::writeFile($this->workspace, '.greph-index/postings.phpbin', serialize(['fun' => [1], 'bad' => 'skip']));
        Workspace::remove($result->indexPath . '/forward.phpbin');

        $legacyForwardIndex = $store->load($result->indexPath, includeForward: true, includePostings: true);

        $this->assertSame([1 => ['fun']], $legacyForwardIndex->forward);
        $this->assertSame([1], $legacyForwardIndex->postings['fun']);
        $this->assertArrayNotHasKey('bad', $legacyForwardIndex->postings);
    }

    #[Test]
    public function itRejectsMissingCorruptAndMismatchedIndexes(): void
    {
        $store = new TextIndexStore();
        $indexPath = $this->workspace . '/.greph-index';

        try {
            $store->load($indexPath);
            self::fail('Expected missing index load to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Index does not exist', $exception->getMessage());
        }

        Workspace::writeFile($this->workspace, '.greph-index/metadata.phpbin', serialize([
            'version' => 999,
            'rootPath' => $this->workspace,
            'builtAt' => 1,
            'nextFileId' => 2,
        ]));
        Workspace::writeFile($this->workspace, '.greph-index/files.phpbin', serialize([]));
        Workspace::writeFile($this->workspace, '.greph-index/postings.phpbin', serialize([]));

        try {
            $store->load($indexPath);
            self::fail('Expected version mismatch to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Index version mismatch', $exception->getMessage());
        }

        Workspace::writeFile($this->workspace, '.greph-index/metadata.phpbin', serialize([
            'version' => $store->version(),
            'rootPath' => $this->workspace,
            'builtAt' => 1,
            'nextFileId' => 2,
        ]));
        Workspace::writeFile($this->workspace, '.greph-index/postings.phpbin', serialize('bad'));

        try {
            $store->loadSelectedPostings($indexPath, ['fun']);
            self::fail('Expected corrupt postings payload to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Index is corrupt', $exception->getMessage());
        }

        Workspace::writeFile($this->workspace, '.greph-index/metadata.phpbin', '');

        try {
            $store->load($indexPath);
            self::fail('Expected empty metadata file to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to read index file', $exception->getMessage());
        }
    }

    #[Test]
    public function itSavesAndClearsQueryDirectoriesOnRoundTrip(): void
    {
        $store = new TextIndexStore();
        $indexPath = $this->workspace . '/.greph-index';
        Workspace::writeFile($this->workspace, '.greph-index/queries/stale.txt', 'old');

        $index = new TextIndex(
            rootPath: $this->workspace,
            indexPath: $indexPath,
            version: $store->version(),
            builtAt: 10,
            buildDurationMs: 12.5,
            nextFileId: 2,
            files: [
                ['id' => 1, 'p' => 'src/App.php', 's' => 10, 'm' => 1, 'h' => false, 'g' => false, 'o' => 0],
            ],
            postings: ['fun' => [1]],
            forward: [1 => ['fun']],
            wordPostings: ['function' => [1]],
            wordForward: [1 => ['function']],
        );

        $store->save($index);
        $loaded = $store->load($indexPath, includeForward: true, includePostings: true);

        $this->assertSame(['fun' => [1]], $loaded->postings);
        $this->assertSame([1 => ['fun']], $loaded->forward);
        $this->assertSame(['function' => [1]], $loaded->wordPostings);
        $this->assertSame([1 => ['function']], $loaded->wordForward);
        $this->assertFileDoesNotExist($indexPath . '/queries/stale.txt');
    }

    #[Test]
    public function itCoversPrivateWriteAndLegacyForwardBranches(): void
    {
        $store = new TextIndexStore();
        $indexPath = $this->workspace . '/.greph-index';
        $writePath = $indexPath . '/custom.phpbin';

        mkdir($writePath . '.tmp', 0777, true);

        try {
            $this->invokeMethod($store, 'writeAtomic', $writePath, ['ok' => true]);
            self::fail('Expected index write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to write index file', $exception->getMessage());
        }

        Workspace::remove($writePath . '.tmp');
        mkdir($writePath, 0777, true);

        try {
            $this->invokeMethod($store, 'writeAtomic', $writePath, ['ok' => true]);
            self::fail('Expected index finalize failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to finalize index file', $exception->getMessage());
        }

        $temporaryDirectory = $indexPath . '/postings.tmp';
        $postingsDirectory = $indexPath . '/postings';
        mkdir($temporaryDirectory, 0777, true);
        mkdir($postingsDirectory, 0777, true);
        file_put_contents($postingsDirectory . '/existing.txt', 'busy');

        try {
            $this->invokeMethod($store, 'finalizePostingsDirectory', $temporaryDirectory, $postingsDirectory, $indexPath);
            self::fail('Expected postings finalize failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to finalize index postings', $exception->getMessage());
        }

        $legacyForward = $this->invokeMethod($store, 'forwardFromLegacyFiles', [
            ['id' => 1, 't' => ['fun', 99]],
            ['id' => 'bad', 't' => ['skip']],
        ]);

        $this->assertSame([1 => ['fun']], $legacyForward);

        Workspace::writeFile($this->workspace, '.greph-index/metadata.phpbin', serialize([
            'version' => $store->version(),
            'rootPath' => $this->workspace,
            'builtAt' => 1,
        ]));
        Workspace::writeFile($this->workspace, '.greph-index/files.phpbin', serialize([]));
        Workspace::writeFile($this->workspace, '.greph-index/postings.phpbin', serialize([]));

        try {
            $store->load($indexPath);
            self::fail('Expected corrupt metadata to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Index is corrupt', $exception->getMessage());
        }
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
