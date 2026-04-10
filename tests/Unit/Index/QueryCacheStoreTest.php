<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Index;

use Phgrep\Ast\AstMatch;
use Phgrep\Ast\AstSearchOptions;
use Phgrep\Ast\PatternParser;
use Phgrep\Ast\StoredNode;
use Phgrep\Index\AstQueryCacheStore;
use Phgrep\Index\TextIndex;
use Phgrep\Index\TextQueryCacheStore;
use Phgrep\Tests\Support\Workspace;
use Phgrep\Text\TextFileResult;
use Phgrep\Text\TextMatch;
use Phgrep\Text\TextSearchOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueryCacheStoreTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('query-cache-store');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itRoundTripsAndSeparatesTextQueryCaches(): void
    {
        $store = new TextQueryCacheStore();
        $index = $this->textIndex();
        $normalOptions = new TextSearchOptions(fixedString: true);
        $summaryOptions = new TextSearchOptions(fixedString: true, filesWithMatches: true);
        $results = [
            new TextFileResult('src/App.php', [
                new TextMatch('src/App.php', 2, 1, 'function demo()', 'function'),
            ], 1),
        ];

        $store->save($index, 'function', $normalOptions, $results);
        $store->save($index, 'function', $summaryOptions, $results);

        $loaded = $store->load($index, 'function', $normalOptions);
        $summaryLoaded = $store->load($index, 'function', $summaryOptions);
        $cacheFiles = glob($this->workspace . '/.phgrep-index/queries/*.phpbin*') ?: [];

        $this->assertCount(2, $cacheFiles);
        $this->assertNotNull($loaded);
        $this->assertNotNull($summaryLoaded);
        $this->assertCount(1, $loaded);
        $this->assertCount(1, $summaryLoaded);
        $this->assertSame(1, $loaded[0]->matchCount());
        $this->assertSame(1, $summaryLoaded[0]->matchCount());

        $staleIndex = new TextIndex(
            rootPath: $index->rootPath,
            indexPath: $index->indexPath,
            version: $index->version,
            builtAt: 999,
            nextFileId: $index->nextFileId,
            files: $index->files,
            postings: [],
        );

        $this->assertNull($store->load($staleIndex, 'function', $normalOptions));

        $store->clear($index->indexPath);
        $this->assertNull($store->load($index, 'function', $normalOptions));
    }

    #[Test]
    public function itDetectsCorruptTextQueryCachePayloads(): void
    {
        $store = new TextQueryCacheStore();
        $index = $this->textIndex();
        $options = new TextSearchOptions(fixedString: true);

        $store->save($index, 'function', $options, [new TextFileResult('src/App.php', [], 1)]);
        $path = (glob($this->workspace . '/.phgrep-index/queries/*.phpbin*') ?: [])[0] ?? null;

        $this->assertNotNull($path);
        $rawPayload = file_get_contents($path);
        $legacyPath = $this->invokeMethod($store, 'legacyCachePath', $index->indexPath, 'function', $options);

        $this->assertNotFalse($rawPayload);
        unlink($path);
        file_put_contents($legacyPath, gzencode($rawPayload) ?: '');
        $this->assertNotNull($store->load($index, 'function', $options));

        file_put_contents($path, serialize('bad'));

        try {
            $store->load($index, 'function', $options);
            self::fail('Expected corrupt gzip payload to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Indexed query cache is corrupt', $exception->getMessage());
        }

        file_put_contents($path, serialize(['built_at' => 'bad', 'results' => []]));

        try {
            $store->load($index, 'function', $options);
            self::fail('Expected corrupt payload metadata to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Indexed query cache is corrupt', $exception->getMessage());
        }

        unlink($path);
        file_put_contents($legacyPath, 'not-gzip');
        set_error_handler(static fn (): bool => true);

        try {
            try {
                $store->load($index, 'function', $options);
                self::fail('Expected invalid gzip payload to throw.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Indexed query cache is corrupt', $exception->getMessage());
            }
        } finally {
            restore_error_handler();
        }

        file_put_contents($path, '');
        $this->assertNull($store->load($index, 'function', $options));
    }

    #[Test]
    public function itRoundTripsAndValidatesAstQueryCaches(): void
    {
        $store = new AstQueryCacheStore();
        $pattern = (new PatternParser())->parse('array($$$ITEMS)');
        $options = new AstSearchOptions();
        $indexPath = $this->workspace . '/.phgrep-ast-index';
        $match = new AstMatch(
            file: $this->workspace . '/src/App.php',
            node: $pattern->root,
            captures: [],
            startLine: 2,
            endLine: 2,
            startFilePos: 10,
            endFilePos: 20,
            code: 'array(1, 2, 3)',
        );

        $store->save($indexPath, 123, 'array($$$ITEMS)', $options, [$match]);
        $loaded = $store->load($indexPath, 123, 'array($$$ITEMS)', $options);

        $this->assertNotNull($loaded);
        $this->assertCount(1, $loaded);
        $this->assertSame('array(1, 2, 3)', $loaded[0]->code);
        $this->assertInstanceOf(StoredNode::class, $loaded[0]->node);
        $this->assertNull($store->load($indexPath, 124, 'array($$$ITEMS)', $options));

        $path = (glob($indexPath . '/queries/*.phpbin*') ?: [])[0] ?? null;
        $this->assertNotNull($path);

        $rawPayload = file_get_contents($path);
        $legacyPath = $this->invokeMethod($store, 'legacyCachePath', $indexPath, 'array($$$ITEMS)', $options);

        $this->assertNotFalse($rawPayload);
        unlink($path);
        file_put_contents($legacyPath, gzencode($rawPayload) ?: '');
        $this->assertNotNull($store->load($indexPath, 123, 'array($$$ITEMS)', $options));

        file_put_contents($path, serialize(['built_at' => 123, 'matches' => ['bad']]));

        try {
            $store->load($indexPath, 123, 'array($$$ITEMS)', $options);
            self::fail('Expected invalid AST match payload to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('AST query cache is corrupt', $exception->getMessage());
        }

        file_put_contents($path, serialize([
            'built_at' => 123,
            'matches' => [[
                'f' => '/tmp/demo.php',
                't' => 'Expr_Array',
                'sl' => 2,
                'el' => 2,
                'sp' => 10,
                'ep' => 20,
                'c' => 'array(1, 2, 3)',
                'p' => [],
            ]],
        ]));

        $decoded = $store->load($indexPath, 123, 'array($$$ITEMS)', $options);
        $this->assertInstanceOf(StoredNode::class, $decoded[0]->node);

        file_put_contents($path, serialize('bad'));

        try {
            $store->load($indexPath, 123, 'array($$$ITEMS)', $options);
            self::fail('Expected invalid AST gzip payload to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('AST query cache is corrupt', $exception->getMessage());
        }

        $store->clear($indexPath);
        $this->assertNull($store->load($indexPath, 123, 'array($$$ITEMS)', $options));

        $store->save($indexPath, 123, 'array($$$ITEMS)', $options, [$match]);
        $paths = glob($indexPath . '/queries/*.phpbin*') ?: [];
        $path = $paths[0] ?? null;
        $this->assertNotNull($path);

        file_put_contents($path, '');
        $this->assertNull($store->load($indexPath, 123, 'array($$$ITEMS)', $options));

        unlink($path);
        file_put_contents($legacyPath, 'not-gzip');
        set_error_handler(static fn (): bool => true);

        try {
            try {
                $store->load($indexPath, 123, 'array($$$ITEMS)', $options);
                self::fail('Expected invalid AST gzip payload to throw.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('AST query cache is corrupt', $exception->getMessage());
            }
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function itCoversTextAndAstCacheWriteFailureBranches(): void
    {
        $textStore = new TextQueryCacheStore();
        $astStore = new AstQueryCacheStore();
        $textIndex = $this->textIndex();
        $textOptions = new TextSearchOptions(fixedString: true);
        $astOptions = new AstSearchOptions();
        $astIndexPath = $this->workspace . '/.phgrep-ast-index';
        $textPath = $this->invokeMethod($textStore, 'cachePath', $textIndex->indexPath, 'function', $textOptions);
        $astPath = $this->invokeMethod($astStore, 'cachePath', $astIndexPath, 'array($$$ITEMS)', $astOptions);

        mkdir($textPath . '.tmp', 0777, true);

        try {
            $textStore->save($textIndex, 'function', $textOptions, [new TextFileResult('src/App.php', [], 1)]);
            self::fail('Expected indexed query cache write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to write indexed query cache', $exception->getMessage());
        }

        Workspace::remove($textPath . '.tmp');
        mkdir($textPath, 0777, true);

        try {
            $textStore->save($textIndex, 'function', $textOptions, [new TextFileResult('src/App.php', [], 1)]);
            self::fail('Expected indexed query cache finalize failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to finalize indexed query cache', $exception->getMessage());
        }

        mkdir($astPath . '.tmp', 0777, true);

        try {
            $astStore->save($astIndexPath, 123, 'array($$$ITEMS)', $astOptions, []);
            self::fail('Expected AST query cache write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to write AST query cache', $exception->getMessage());
        }

        Workspace::remove($astPath . '.tmp');
        mkdir($astPath, 0777, true);

        try {
            $astStore->save($astIndexPath, 123, 'array($$$ITEMS)', $astOptions, []);
            self::fail('Expected AST query cache finalize failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to finalize AST query cache', $exception->getMessage());
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

    private function textIndex(): TextIndex
    {
        return new TextIndex(
            rootPath: $this->workspace,
            indexPath: $this->workspace . '/.phgrep-index',
            version: 1,
            builtAt: 123,
            nextFileId: 2,
            files: [
                ['id' => 1, 'p' => 'src/App.php', 's' => 20, 'm' => 1, 'h' => false, 'g' => false, 'o' => 0],
            ],
            postings: [],
            forward: [],
        );
    }
}
