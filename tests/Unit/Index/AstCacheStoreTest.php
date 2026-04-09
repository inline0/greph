<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Index;

use Phgrep\Index\AstCache;
use Phgrep\Index\AstCacheBuilder;
use Phgrep\Index\AstCacheStore;
use Phgrep\Tests\Support\Workspace;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Stmt\Expression;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstCacheStoreTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('ast-cache-store');
        Workspace::writeFile($this->workspace, '.gitignore', "ignored.php\n");
        Workspace::writeFile(
            $this->workspace,
            'src/App.php',
            "<?php\n\$service = new Service();\nrender_widget();\n\$client->send(\$message);\n",
        );
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itLoadsRoundTripsLocatesAndHandlesTrees(): void
    {
        $builder = new AstCacheBuilder();
        $store = new AstCacheStore();
        $result = $builder->build($this->workspace);

        $cache = $store->load($result->indexPath);
        $this->assertSame($this->workspace . '/.phgrep-ast-cache', $store->defaultPath($this->workspace));
        $this->assertSame($result->indexPath, $store->locateFrom($this->workspace . '/src/App.php'));
        $this->assertSame($result->indexPath, $store->locateFrom($this->workspace));
        $this->assertTrue($store->exists($result->indexPath));
        $this->assertNotNull($store->loadTree($result->indexPath, 1));

        $statements = [new Expression(new ConstFetch(new \PhpParser\Node\Name('true')))];
        $store->saveTree($result->indexPath, 99, $statements);

        $loadedTree = $store->loadTree($result->indexPath, 99);
        $this->assertIsArray($loadedTree);
        $this->assertCount(1, $loadedTree);

        Workspace::remove($result->indexPath . '/trees/99.phpbin.gz');
        $this->assertNull($store->loadTree($result->indexPath, 99));

        Workspace::writeFile($result->indexPath, 'trees/50.phpbin.gz', '');
        $this->assertNull($store->loadTree($result->indexPath, 50));

        Workspace::writeFile($result->indexPath, 'trees/51.phpbin.gz', gzencode(serialize('bad')) ?: '');
        $this->assertNull($store->loadTree($result->indexPath, 51));

        Workspace::writeFile($result->indexPath, 'trees/52.phpbin.gz', 'not-gzip');

        set_error_handler(static fn (): bool => true);

        try {
            $store->loadTree($result->indexPath, 52);
            self::fail('Expected corrupt AST tree cache to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('AST tree cache is corrupt', $exception->getMessage());
        } finally {
            restore_error_handler();
        }

        Workspace::writeFile($result->indexPath, 'trees/not-a-file.phpbin.gz', gzencode(serialize([])) ?: '');
        $store->pruneTrees($result->indexPath, [1 => true]);

        $this->assertFileExists($result->indexPath . '/trees/1.phpbin.gz');
        $this->assertFileDoesNotExist($result->indexPath . '/trees/52.phpbin.gz');
    }

    #[Test]
    public function itRejectsMissingCorruptVersionMismatchedAndUnreadableCaches(): void
    {
        $store = new AstCacheStore();
        $indexPath = $this->workspace . '/.phgrep-ast-cache';

        try {
            $store->load($indexPath);
            self::fail('Expected missing AST cache load to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('AST cache does not exist', $exception->getMessage());
        }

        Workspace::writeFile($this->workspace, '.phgrep-ast-cache/metadata.phpbin', serialize([
            'version' => 999,
            'rootPath' => $this->workspace,
            'builtAt' => 1,
            'nextFileId' => 2,
        ]));
        Workspace::writeFile($this->workspace, '.phgrep-ast-cache/files.phpbin', serialize([]));
        Workspace::writeFile($this->workspace, '.phgrep-ast-cache/facts.phpbin', serialize([]));
        mkdir($indexPath . '/trees', 0777, true);

        try {
            $store->load($indexPath);
            self::fail('Expected AST cache version mismatch to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('AST cache version mismatch', $exception->getMessage());
        }

        Workspace::writeFile($this->workspace, '.phgrep-ast-cache/metadata.phpbin', serialize([
            'version' => $store->version(),
            'rootPath' => $this->workspace,
            'builtAt' => 1,
            'nextFileId' => 2,
        ]));
        Workspace::writeFile($this->workspace, '.phgrep-ast-cache/facts.phpbin', serialize('bad'));

        try {
            $store->load($indexPath);
            self::fail('Expected corrupt AST cache to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('AST cache is corrupt', $exception->getMessage());
        }

        Workspace::writeFile($this->workspace, '.phgrep-ast-cache/facts.phpbin', '');

        try {
            $store->load($indexPath);
            self::fail('Expected unreadable AST cache file to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to read AST cache file', $exception->getMessage());
        }
    }

    #[Test]
    public function itSavesRoundTripsAndClearsQueryDirectories(): void
    {
        $store = new AstCacheStore();
        $indexPath = $this->workspace . '/.phgrep-ast-cache';
        Workspace::writeFile($this->workspace, '.phgrep-ast-cache/queries/stale.txt', 'old');

        $cache = new AstCache(
            rootPath: $this->workspace,
            indexPath: $indexPath,
            version: $store->version(),
            builtAt: 10,
            nextFileId: 2,
            files: [
                ['id' => 1, 'p' => 'src/App.php', 's' => 10, 'm' => 1, 'h' => false, 'g' => false, 'o' => 0],
            ],
            facts: [
                1 => [
                    'zero_arg_new' => true,
                    'long_array' => false,
                    'function_calls' => ['render_widget'],
                    'method_calls' => ['send'],
                    'static_calls' => [],
                    'new_targets' => ['Service'],
                    'classes' => [],
                    'interfaces' => [],
                    'traits' => [],
                    'cached' => true,
                ],
            ],
        );

        $store->save($cache);
        $loaded = $store->load($indexPath);

        $this->assertTrue($loaded->facts[1]['cached']);
        $this->assertSame(['render_widget'], $loaded->facts[1]['function_calls']);
        $this->assertFileDoesNotExist($indexPath . '/queries/stale.txt');
    }

    #[Test]
    public function itCoversPrivateWriteTreeAndPruneBranches(): void
    {
        $store = new AstCacheStore();
        $indexPath = $this->workspace . '/.phgrep-ast-cache';
        $treePath = $this->invokeMethod($store, 'treePath', $indexPath, 77);
        $writePath = $indexPath . '/custom.phpbin';
        $statements = [new Expression(new ConstFetch(new \PhpParser\Node\Name('true')))];

        mkdir(dirname($treePath . '.tmp'), 0777, true);
        mkdir($treePath . '.tmp', 0777, true);

        try {
            $store->saveTree($indexPath, 77, $statements);
            self::fail('Expected AST tree write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to write AST tree cache', $exception->getMessage());
        }

        Workspace::remove($treePath . '.tmp');
        mkdir($treePath, 0777, true);

        try {
            $store->saveTree($indexPath, 77, $statements);
            self::fail('Expected AST tree finalize failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to finalize AST tree cache', $exception->getMessage());
        }

        mkdir($writePath . '.tmp', 0777, true);

        try {
            $this->invokeMethod($store, 'writeAtomic', $writePath, ['ok' => true]);
            self::fail('Expected AST cache write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to write AST cache file', $exception->getMessage());
        }

        Workspace::remove($writePath . '.tmp');
        mkdir($writePath, 0777, true);

        try {
            $this->invokeMethod($store, 'writeAtomic', $writePath, ['ok' => true]);
            self::fail('Expected AST cache finalize failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to finalize AST cache file', $exception->getMessage());
        }

        $store->pruneTrees($this->workspace . '/missing-cache', []);
        $this->assertDirectoryDoesNotExist($this->workspace . '/missing-cache/trees');
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
