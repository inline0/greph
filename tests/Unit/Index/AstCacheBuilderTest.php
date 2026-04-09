<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Index;

use Phgrep\Index\AstCacheBuilder;
use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstCacheBuilderTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('ast-cache-builder');
        Workspace::writeFile($this->workspace, '.gitignore', "vendor/\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nnew Service();\n");
        Workspace::writeFile($this->workspace, '.hidden/Hidden.php', "<?php\nnew HiddenService();\n");
        Workspace::writeFile($this->workspace, '.phgrep-ast-cache/ignored/Skip.php', "<?php\nnew Skipped();\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itRefreshesFromScratchAndCoversPrivateCacheBuilderHelpers(): void
    {
        $builder = new AstCacheBuilder();

        $refreshed = $builder->refresh($this->workspace);
        $absoluteIndexPath = $this->invokeMethod($builder, 'resolveIndexPath', $this->workspace, '/tmp/custom-ast-cache');
        $relativeIndexPath = $this->invokeMethod($builder, 'resolveIndexPath', $this->workspace, '.alt-cache');
        $scannedFiles = $this->invokeMethod($builder, 'scanFiles', $this->workspace, $this->workspace . '/.phgrep-ast-cache');
        $missingFacts = $this->invokeMethod($builder, 'extractFacts', $this->workspace . '/missing.php');
        $hidden = $this->invokeMethod($builder, 'isHiddenPath', '.hidden/Hidden.php');
        $visible = $this->invokeMethod($builder, 'isHiddenPath', 'src/App.php');

        $this->assertSame(2, $refreshed->fileCount);
        $this->assertSame($this->workspace . '/.phgrep-ast-cache', $refreshed->indexPath);
        $this->assertSame('/tmp/custom-ast-cache', $absoluteIndexPath);
        $this->assertSame($this->workspace . '/.alt-cache', $relativeIndexPath);
        $this->assertCount(2, $scannedFiles);
        $this->assertSame(['.hidden/Hidden.php', 'src/App.php'], array_column($scannedFiles, 'relativePath'));
        $this->assertTrue($hidden);
        $this->assertFalse($visible);
        $this->assertSame([
            'zero_arg_new' => false,
            'long_array' => false,
            'function_calls' => [],
            'method_calls' => [],
            'static_calls' => [],
            'new_targets' => [],
            'classes' => [],
            'interfaces' => [],
            'traits' => [],
        ], $missingFacts);
    }

    #[Test]
    public function itRejectsMissingAstCacheRoots(): void
    {
        $builder = new AstCacheBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AST cache root does not exist');

        $this->invokeMethod($builder, 'resolveRootPath', $this->workspace . '/missing');
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
