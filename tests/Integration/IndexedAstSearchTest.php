<?php

declare(strict_types=1);

namespace Greph\Tests\Integration;

use Greph\Ast\AstSearchOptions;
use Greph\Greph;
use Greph\Index\IndexLifecycleProfile;
use Greph\Tests\Support\Workspace;
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

        Greph::buildAstIndex($this->workspace);
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itUsesIndexedFactsToNarrowAstSearches(): void
    {
        $newMatches = Greph::searchAstIndexed(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );
        $arrayMatches = Greph::searchAstIndexed(
            'array($$$ITEMS)',
            $this->workspace,
            new AstSearchOptions(),
        );
        $functionMatches = Greph::searchAstIndexed(
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
        $defaultMatches = Greph::searchAstIndexed(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );
        $expandedMatches = Greph::searchAstIndexed(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(includeHidden: true, respectIgnore: false),
        );

        $this->assertCount(1, $defaultMatches);
        $this->assertCount(2, $expandedMatches);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, 'src/Newer.php', "<?php\n\$fresh = new FreshThing();\n");
        Greph::refreshAstIndex($this->workspace);

        $refreshedMatches = Greph::searchAstIndexed(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );

        $this->assertCount(1, $refreshedMatches);
        $this->assertSame($this->workspace . '/src/Newer.php', $refreshedMatches[0]->file);
    }

    #[Test]
    public function itCachesRootAstIndexQueriesAndInvalidatesThemOnRefresh(): void
    {
        $initialMatches = Greph::searchAstIndexed(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );

        $cacheFiles = glob($this->workspace . '/.greph-ast-index/queries/*.phpbin*') ?: [];

        $this->assertNotSame([], $cacheFiles);
        $this->assertCount(1, $initialMatches);
        $this->assertSame($this->workspace . '/src/App.php', $initialMatches[0]->file);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, 'src/Newer.php', "<?php\n\$fresh = new FreshThing();\n");
        Greph::refreshAstIndex($this->workspace);

        $refreshedMatches = Greph::searchAstIndexed(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );

        $refreshedCacheFiles = glob($this->workspace . '/.greph-ast-index/queries/*.phpbin*') ?: [];

        $this->assertNotSame([], $refreshedCacheFiles);
        $this->assertCount(1, $refreshedMatches);
        $this->assertSame($this->workspace . '/src/Newer.php', $refreshedMatches[0]->file);
    }

    #[Test]
    public function itSupportsAstLifecyclesAndMultiIndexSearch(): void
    {
        $refreshWorkspace = Workspace::createDirectory('indexed-ast-lifecycle');
        Workspace::writeFile($refreshWorkspace, 'src/App.php', "<?php\n\$service = new Service();\n");
        Greph::buildAstIndex($refreshWorkspace, lifecycle: IndexLifecycleProfile::OpportunisticRefresh);

        sleep(1);
        Workspace::writeFile($refreshWorkspace, 'src/New.php', "<?php\n\$fresh = new FreshThing();\n");

        $refreshedMatches = Greph::searchAstIndexed('new $CLASS()', $refreshWorkspace);
        $this->assertContains('New.php', array_map(static fn ($match): string => basename($match->file), $refreshedMatches));

        $strictWorkspace = Workspace::createDirectory('indexed-ast-strict');
        Workspace::writeFile($strictWorkspace, 'src/App.php', "<?php\n\$service = new Service();\n");
        Greph::buildAstIndex($strictWorkspace, lifecycle: IndexLifecycleProfile::StrictStaleCheck);

        sleep(1);
        Workspace::writeFile($strictWorkspace, 'src/New.php', "<?php\n\$strict = new StrictThing();\n");

        try {
            Greph::searchAstIndexed('new $CLASS()', $strictWorkspace);
            self::fail('Expected strict stale AST index to reject search.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('AST index is stale', $exception->getMessage());
        }

        $multiWorkspace = Workspace::createDirectory('indexed-ast-many');
        Workspace::writeFile($multiWorkspace, 'core/Core.php', "<?php\n\$core = new CoreThing();\n");
        Workspace::writeFile($multiWorkspace, 'plugins/Demo/Plugin.php', "<?php\n\$plugin = new PluginThing();\n");
        Greph::buildAstIndex($multiWorkspace . '/core');
        Greph::buildAstIndex($multiWorkspace . '/plugins/Demo');

        $manyMatches = Greph::searchAstIndexedMany(
            'new $CLASS()',
            $multiWorkspace,
            [
                $multiWorkspace . '/core/.greph-ast-index',
                $multiWorkspace . '/plugins/Demo/.greph-ast-index',
            ],
        );

        $matched = array_map(static fn ($match): string => basename($match->file), $manyMatches);
        $this->assertContains('Core.php', $matched);
        $this->assertContains('Plugin.php', $matched);

        Workspace::remove($refreshWorkspace);
        Workspace::remove($strictWorkspace);
        Workspace::remove($multiWorkspace);
    }

    #[Test]
    public function itSearchesAstIndexesThroughNamedIndexSets(): void
    {
        $setWorkspace = Workspace::createDirectory('indexed-ast-set');

        try {
            Workspace::writeFile($setWorkspace, 'core/Core.php', "<?php\n\$core = new CoreThing();\n");
            Workspace::writeFile($setWorkspace, 'plugins/Demo/Plugin.php', "<?php\n\$plugin = new PluginThing();\n");
            Workspace::writeFile(
                $setWorkspace,
                '.greph-index-set.json',
                (string) json_encode([
                    'name' => 'wordpress-ast',
                    'indexes' => [
                        ['name' => 'core-ast', 'root' => 'core', 'mode' => 'ast-index', 'priority' => 20],
                        ['name' => 'plugin-ast', 'root' => 'plugins/Demo', 'mode' => 'ast-index', 'priority' => 10],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
            Greph::buildAstIndex($setWorkspace . '/core');
            Greph::buildAstIndex($setWorkspace . '/plugins/Demo');

            $matches = Greph::searchAstIndexedSet(
                'new $CLASS()',
                $setWorkspace,
                new AstSearchOptions(),
                $setWorkspace . '/.greph-index-set.json',
            );

            $matched = array_map(static fn ($match): string => basename($match->file), $matches);
            $this->assertContains('Core.php', $matched);
            $this->assertContains('Plugin.php', $matched);
        } finally {
            Workspace::remove($setWorkspace);
        }
    }
}
