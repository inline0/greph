<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Index;

use Greph\Greph;
use Greph\Index\AstCacheStore;
use Greph\Index\AstIndexStore;
use Greph\Index\IndexFreshnessInspector;
use Greph\Index\TextIndexStore;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexFreshnessInspectorTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('index-freshness-inspector');
        Workspace::writeFile($this->workspace, '.gitignore', "ignored.php\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction demo(): void {}\n");
        Workspace::writeFile($this->workspace, 'src/Notes.txt', "alpha\n");
        Greph::buildTextIndex($this->workspace);
        Greph::buildAstIndex($this->workspace);
        Greph::buildAstCache($this->workspace);
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itDetectsFreshAndStaleTextAndAstIndexes(): void
    {
        $inspector = new IndexFreshnessInspector();
        $textStore = new TextIndexStore();
        $astStore = new AstIndexStore();
        $cacheStore = new AstCacheStore();

        $freshText = $inspector->inspectText($textStore->load($this->workspace . '/.greph-index'));
        $freshAst = $inspector->inspectAstIndex($astStore->load($this->workspace . '/.greph-ast-index'));
        $freshCache = $inspector->inspectAstCache($cacheStore->load($this->workspace . '/.greph-ast-cache'));

        $this->assertFalse($freshText->stale);
        $this->assertFalse($freshAst->stale);
        $this->assertFalse($freshCache->stale);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, 'src/New.php', "<?php\nfunction newer(): void {}\n");
        Workspace::remove($this->workspace . '/src/Notes.txt');

        $staleText = $inspector->inspectText($textStore->load($this->workspace . '/.greph-index'));
        $staleAst = $inspector->inspectAstIndex($astStore->load($this->workspace . '/.greph-ast-index'));
        $staleCache = $inspector->inspectAstCache($cacheStore->load($this->workspace . '/.greph-ast-cache'));

        $this->assertTrue($staleText->stale);
        $this->assertSame(1, $staleText->addedFiles);
        $this->assertSame(1, $staleText->updatedFiles);
        $this->assertSame(1, $staleText->deletedFiles);
        $this->assertGreaterThan(0, $staleText->changedBytes);
        $this->assertSame(3, $staleText->changedFileCount());
        $this->assertTrue($staleAst->stale);
        $this->assertTrue($staleCache->stale);
    }
}
