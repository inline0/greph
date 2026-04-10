<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Index;

use Greph\Index\AstIndex;
use Greph\Index\AstIndexBuilder;
use Greph\Index\AstIndexStore;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstIndexStoreTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('ast-index-store');
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
    public function itLoadsRoundTripsAndLocatesIndexes(): void
    {
        $builder = new AstIndexBuilder();
        $store = new AstIndexStore();
        $result = $builder->build($this->workspace);

        $index = $store->load($result->indexPath);

        $this->assertSame($this->workspace . '/.greph-ast-index', $store->defaultPath($this->workspace));
        $this->assertSame($result->indexPath, $store->locateFrom($this->workspace . '/src/App.php'));
        $this->assertSame($result->indexPath, $store->locateFrom($this->workspace));
        $this->assertTrue($store->exists($result->indexPath));
        $this->assertSame($store->version(), $index->version);
        $this->assertNotSame([], $index->facts);
    }

    #[Test]
    public function itRejectsMissingCorruptVersionMismatchedAndUnreadableIndexes(): void
    {
        $store = new AstIndexStore();
        $indexPath = $this->workspace . '/.greph-ast-index';

        try {
            $store->load($indexPath);
            self::fail('Expected missing AST index load to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('AST index does not exist', $exception->getMessage());
        }

        Workspace::writeFile($this->workspace, '.greph-ast-index/metadata.phpbin', serialize([
            'version' => 999,
            'rootPath' => $this->workspace,
            'builtAt' => 1,
            'nextFileId' => 2,
        ]));
        Workspace::writeFile($this->workspace, '.greph-ast-index/files.phpbin', serialize([]));
        Workspace::writeFile($this->workspace, '.greph-ast-index/facts.phpbin', serialize([]));

        try {
            $store->load($indexPath);
            self::fail('Expected AST index version mismatch to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('AST index version mismatch', $exception->getMessage());
        }

        Workspace::writeFile($this->workspace, '.greph-ast-index/metadata.phpbin', serialize([
            'version' => $store->version(),
            'rootPath' => $this->workspace,
            'builtAt' => 1,
            'nextFileId' => 2,
        ]));
        Workspace::writeFile($this->workspace, '.greph-ast-index/facts.phpbin', serialize('bad'));

        try {
            $store->load($indexPath);
            self::fail('Expected corrupt AST index to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('AST index is corrupt', $exception->getMessage());
        }

        Workspace::writeFile($this->workspace, '.greph-ast-index/facts.phpbin', '');

        try {
            $store->load($indexPath);
            self::fail('Expected unreadable AST index file to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to read AST index file', $exception->getMessage());
        }
    }

    #[Test]
    public function itSavesRoundTripsAndClearsQueryDirectories(): void
    {
        $store = new AstIndexStore();
        $indexPath = $this->workspace . '/.greph-ast-index';
        Workspace::writeFile($this->workspace, '.greph-ast-index/queries/stale.txt', 'old');

        $index = new AstIndex(
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
                ],
            ],
        );

        $store->save($index);
        $loaded = $store->load($indexPath);

        $this->assertSame(['render_widget'], $loaded->facts[1]['function_calls']);
        $this->assertTrue($loaded->facts[1]['zero_arg_new']);
        $this->assertFileDoesNotExist($indexPath . '/queries/stale.txt');
    }

    #[Test]
    public function itCoversPrivateAstIndexWriteFailures(): void
    {
        $store = new AstIndexStore();
        $path = $this->workspace . '/.greph-ast-index/custom.phpbin';

        mkdir($path . '.tmp', 0777, true);

        try {
            $this->invokeMethod($store, 'writeAtomic', $path, ['ok' => true]);
            self::fail('Expected AST index write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to write AST index file', $exception->getMessage());
        }

        Workspace::remove($path . '.tmp');
        mkdir($path, 0777, true);

        try {
            $this->invokeMethod($store, 'writeAtomic', $path, ['ok' => true]);
            self::fail('Expected AST index finalize failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to finalize AST index file', $exception->getMessage());
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
