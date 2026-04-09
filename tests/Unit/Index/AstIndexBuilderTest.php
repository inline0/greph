<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Index;

use Phgrep\Index\AstIndexBuilder;
use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstIndexBuilderTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('ast-index-builder');
        Workspace::writeFile($this->workspace, '.gitignore', "vendor/\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nnew Service();\n");
        Workspace::writeFile($this->workspace, '.hidden/Hidden.php', "<?php\nnew HiddenService();\n");
        Workspace::writeFile($this->workspace, '.phgrep-ast-index/ignored/Skip.php', "<?php\nnew Skipped();\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itRefreshesFromScratchAndCoversPrivateBuilderHelpers(): void
    {
        $builder = new AstIndexBuilder();

        $refreshed = $builder->refresh($this->workspace);
        $absoluteIndexPath = $this->invokeMethod($builder, 'resolveIndexPath', $this->workspace, '/tmp/custom-ast-index');
        $relativeIndexPath = $this->invokeMethod($builder, 'resolveIndexPath', $this->workspace, '.alt-index');
        $scannedFiles = $this->invokeMethod($builder, 'scanFiles', $this->workspace, $this->workspace . '/.phgrep-ast-index');
        $missingFacts = $this->invokeMethod($builder, 'extractFacts', $this->workspace . '/missing.php');
        $hidden = $this->invokeMethod($builder, 'isHiddenPath', '.hidden/Hidden.php');
        $visible = $this->invokeMethod($builder, 'isHiddenPath', 'src/App.php');

        $this->assertSame(2, $refreshed->fileCount);
        $this->assertSame($this->workspace . '/.phgrep-ast-index', $refreshed->indexPath);
        $this->assertSame('/tmp/custom-ast-index', $absoluteIndexPath);
        $this->assertSame($this->workspace . '/.alt-index', $relativeIndexPath);
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
    public function itRejectsMissingAstIndexRoots(): void
    {
        $builder = new AstIndexBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AST index root does not exist');

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
