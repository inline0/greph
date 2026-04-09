<?php

declare(strict_types=1);

namespace Phgrep\Tests\Integration;

use Phgrep\Ast\AstSearchOptions;
use Phgrep\Phgrep;
use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexedAstSearchTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('indexed-ast-search');
        Workspace::writeFile($this->workspace, '.gitignore', "ignored.php\n");
        Workspace::writeFile(
            $this->workspace,
            'src/App.php',
            <<<'PHP'
<?php

$service = new Service();
$items = array(1, 2, 3);
render_widget();
$value->run();
PHP,
        );
        Workspace::writeFile($this->workspace, 'src/Other.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, '.hidden/Hidden.php', "<?php\n\$hidden = new HiddenThing();\n");
        Workspace::writeFile($this->workspace, 'ignored.php', "<?php\n\$ignored = array(1);\n");

        Phgrep::buildAstIndex($this->workspace);
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itUsesIndexedFactsToNarrowAstSearches(): void
    {
        $newMatches = Phgrep::searchAstIndexed(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );
        $arrayMatches = Phgrep::searchAstIndexed(
            'array($$$ITEMS)',
            $this->workspace,
            new AstSearchOptions(),
        );
        $functionMatches = Phgrep::searchAstIndexed(
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
    public function itHonorsVisibilityAndIgnoreFiltersAndRefreshesIncrementally(): void
    {
        $defaultMatches = Phgrep::searchAstIndexed(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );
        $expandedMatches = Phgrep::searchAstIndexed(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(includeHidden: true, respectIgnore: false),
        );

        $this->assertCount(1, $defaultMatches);
        $this->assertCount(2, $expandedMatches);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, 'src/Newer.php', "<?php\n\$fresh = new FreshThing();\n");
        Phgrep::refreshAstIndex($this->workspace);

        $refreshedMatches = Phgrep::searchAstIndexed(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );

        $this->assertCount(1, $refreshedMatches);
        $this->assertSame($this->workspace . '/src/Newer.php', $refreshedMatches[0]->file);
    }
}
