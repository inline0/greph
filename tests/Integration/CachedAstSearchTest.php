<?php

declare(strict_types=1);

namespace Greph\Tests\Integration;

use Greph\Ast\AstSearchOptions;
use Greph\Greph;
use Greph\Index\IndexLifecycleProfile;
use Greph\Tests\Support\Workspace;
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

        Greph::buildAstCache($this->workspace);
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itSearchesThroughCachedAstTrees(): void
    {
        $newMatches = Greph::searchAstCached(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );
        $arrayMatches = Greph::searchAstCached(
            'array($$$ITEMS)',
            $this->workspace,
            new AstSearchOptions(),
        );
        $functionMatches = Greph::searchAstCached(
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
        $defaultMatches = Greph::searchAstCached(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );
        $expandedMatches = Greph::searchAstCached(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(includeHidden: true, respectIgnore: false),
        );

        $this->assertCount(1, $defaultMatches);
        $this->assertCount(2, $expandedMatches);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, 'src/Newer.php', "<?php\n\$fresh = new FreshThing();\n");
        Greph::refreshAstCache($this->workspace);

        $refreshedMatches = Greph::searchAstCached(
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
        $initialMatches = Greph::searchAstCached(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );

        $cacheFiles = glob($this->workspace . '/.greph-ast-cache/queries/*.phpbin*') ?: [];

        $this->assertNotSame([], $cacheFiles);
        $this->assertCount(1, $initialMatches);
        $this->assertSame($this->workspace . '/src/App.php', $initialMatches[0]->file);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, 'src/Newer.php', "<?php\n\$fresh = new FreshThing();\n");
        Greph::refreshAstCache($this->workspace);

        $refreshedMatches = Greph::searchAstCached(
            'new $CLASS()',
            $this->workspace,
            new AstSearchOptions(),
        );

        $refreshedCacheFiles = glob($this->workspace . '/.greph-ast-cache/queries/*.phpbin*') ?: [];

        $this->assertNotSame([], $refreshedCacheFiles);
        $this->assertCount(1, $refreshedMatches);
        $this->assertSame($this->workspace . '/src/Newer.php', $refreshedMatches[0]->file);
    }

    #[Test]
    public function itSupportsCachedAstLifecyclesAndMultiIndexSearch(): void
    {
        $refreshWorkspace = Workspace::createDirectory('cached-ast-lifecycle');
        Workspace::writeFile($refreshWorkspace, 'src/App.php', "<?php\n\$service = new Service();\n");
        Greph::buildAstCache($refreshWorkspace, lifecycle: IndexLifecycleProfile::OpportunisticRefresh);

        sleep(1);
        Workspace::writeFile($refreshWorkspace, 'src/New.php', "<?php\n\$fresh = new FreshThing();\n");

        $refreshedMatches = Greph::searchAstCached('new $CLASS()', $refreshWorkspace);
        $this->assertContains('New.php', array_map(static fn ($match): string => basename($match->file), $refreshedMatches));

        $strictWorkspace = Workspace::createDirectory('cached-ast-strict');
        Workspace::writeFile($strictWorkspace, 'src/App.php', "<?php\n\$service = new Service();\n");
        Greph::buildAstCache($strictWorkspace, lifecycle: IndexLifecycleProfile::StrictStaleCheck);

        sleep(1);
        Workspace::writeFile($strictWorkspace, 'src/New.php', "<?php\n\$strict = new StrictThing();\n");

        try {
            Greph::searchAstCached('new $CLASS()', $strictWorkspace);
            self::fail('Expected strict stale AST cache to reject search.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('AST cache is stale', $exception->getMessage());
        }

        $multiWorkspace = Workspace::createDirectory('cached-ast-many');
        Workspace::writeFile($multiWorkspace, 'core/Core.php', "<?php\n\$core = new CoreThing();\n");
        Workspace::writeFile($multiWorkspace, 'plugins/Demo/Plugin.php', "<?php\n\$plugin = new PluginThing();\n");
        Greph::buildAstCache($multiWorkspace . '/core');
        Greph::buildAstCache($multiWorkspace . '/plugins/Demo');

        $manyMatches = Greph::searchAstCachedMany(
            'new $CLASS()',
            $multiWorkspace,
            [
                $multiWorkspace . '/core/.greph-ast-cache',
                $multiWorkspace . '/plugins/Demo/.greph-ast-cache',
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
    public function itSearchesCachedAstIndexesThroughNamedIndexSets(): void
    {
        $setWorkspace = Workspace::createDirectory('cached-ast-set');

        try {
            Workspace::writeFile($setWorkspace, 'core/Core.php', "<?php\n\$core = new CoreThing();\n");
            Workspace::writeFile($setWorkspace, 'plugins/Demo/Plugin.php', "<?php\n\$plugin = new PluginThing();\n");
            Workspace::writeFile(
                $setWorkspace,
                '.greph-index-set.json',
                (string) json_encode([
                    'name' => 'wordpress-cache',
                    'indexes' => [
                        ['name' => 'core-cache', 'root' => 'core', 'mode' => 'ast-cache', 'priority' => 20],
                        ['name' => 'plugin-cache', 'root' => 'plugins/Demo', 'mode' => 'ast-cache', 'priority' => 10],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
            Greph::buildAstCache($setWorkspace . '/core');
            Greph::buildAstCache($setWorkspace . '/plugins/Demo');

            $matches = Greph::searchAstCachedSet(
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
