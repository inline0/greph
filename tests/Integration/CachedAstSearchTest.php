<?php

declare(strict_types=1);

namespace Phgrep\Tests\Integration;

use Phgrep\Ast\AstSearchOptions;
use Phgrep\Phgrep;
use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CachedAstSearchTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('cached-ast-search');
        Workspace::writeFile($this->workspace, '.gitignore', "ignored.php\n");
        Workspace::writeFile(
            $this->workspace,
            'src/App.php',
            <<<'PHP'
<?php

$service = new Service();
$items = array(1, 2, 3);
render_widget();
PHP,
        );
        Workspace::writeFile($this->workspace, 'src/Other.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, '.hidden/Hidden.php', "<?php\n\$hidden = new HiddenThing();\n");
        Workspace::writeFile($this->workspace, 'ignored.php', "<?php\n\$ignored = array(1);\n");
        Workspace::writeFile($this->workspace, 'broken.php', "<?php\nif (\n");

        Phgrep::buildAstCache($this->workspace);
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itSearchesThroughCachedAstTrees(): void
    {
        $newMatches = Phgrep::searchAstCached(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );
        $arrayMatches = Phgrep::searchAstCached(
            'array($$$ITEMS)',
            $this->workspace,
            new AstSearchOptions(),
        );
        $functionMatches = Phgrep::searchAstCached(
            'render_widget()',
            $this->workspace,
            new AstSearchOptions(),
        );

        $this->assertCount(1, $newMatches);
        $this->assertCount(1, $arrayMatches);
        $this->assertCount(1, $functionMatches);
        $this->assertSame($this->workspace . '/src/App.php', $newMatches[0]->file);
        $this->assertSame($this->workspace . '/src/App.php', $arrayMatches[0]->file);
        $this->assertSame($this->workspace . '/src/App.php', $functionMatches[0]->file);
    }

    #[Test]
    public function itRefreshesCachedTreesAndRespectsFilters(): void
    {
        $defaultMatches = Phgrep::searchAstCached(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );
        $expandedMatches = Phgrep::searchAstCached(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(includeHidden: true, respectIgnore: false),
        );

        $this->assertCount(1, $defaultMatches);
        $this->assertCount(2, $expandedMatches);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, 'src/Newer.php', "<?php\n\$fresh = new FreshThing();\n");
        Phgrep::refreshAstCache($this->workspace);

        $refreshedMatches = Phgrep::searchAstCached(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );

        $this->assertCount(1, $refreshedMatches);
        $this->assertSame($this->workspace . '/src/Newer.php', $refreshedMatches[0]->file);
    }

    #[Test]
    public function itCachesRootAstQueriesAndInvalidatesThemOnRefresh(): void
    {
        $initialMatches = Phgrep::searchAstCached(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );

        $cacheFiles = glob($this->workspace . '/.phgrep-ast-cache/queries/*.phpbin*') ?: [];

        $this->assertNotSame([], $cacheFiles);
        $this->assertCount(1, $initialMatches);
        $this->assertSame($this->workspace . '/src/App.php', $initialMatches[0]->file);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, 'src/Newer.php', "<?php\n\$fresh = new FreshThing();\n");
        Phgrep::refreshAstCache($this->workspace);

        $refreshedMatches = Phgrep::searchAstCached(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );

        $refreshedCacheFiles = glob($this->workspace . '/.phgrep-ast-cache/queries/*.phpbin*') ?: [];

        $this->assertNotSame([], $refreshedCacheFiles);
        $this->assertCount(1, $refreshedMatches);
        $this->assertSame($this->workspace . '/src/Newer.php', $refreshedMatches[0]->file);
    }
}
