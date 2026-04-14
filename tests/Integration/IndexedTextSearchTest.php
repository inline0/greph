<?php

declare(strict_types=1);

namespace Greph\Tests\Integration;

use Greph\Greph;
use Greph\Index\IndexLifecycleProfile;
use Greph\Tests\Support\Workspace;
use Greph\Text\TextSearchOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexedTextSearchTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('indexed-text-search');
        Workspace::writeFile($this->workspace, '.gitignore', "ignored.php\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction saveThing(): void {}\n\$foo = new Foo();\n");
        Workspace::writeFile($this->workspace, 'src/Util.php', "<?php\nfunction helper(): void {}\n");
        Workspace::writeFile($this->workspace, '.hidden/Hidden.php', "<?php\nfunction hiddenThing(): void {}\n");
        Workspace::writeFile($this->workspace, 'ignored.php', "<?php\nfunction ignoredThing(): void {}\n");
        Workspace::writeFile($this->workspace, 'notes.txt', "plain text\n");

        Greph::buildTextIndex($this->workspace);
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itUsesTheIndexForLiteralAndRegexSearches(): void
    {
        $literalResults = Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true),
        );
        $regexResults = Greph::searchTextIndexed(
            '\$[a-z]+ = new [A-Za-z]+\(\)',
            $this->workspace,
            new TextSearchOptions(),
        );

        $literalMatches = [];
        $regexMatches = [];

        foreach ($literalResults as $result) {
            foreach ($result->matches as $match) {
                $literalMatches[] = [$result->file, $match->content];
            }
        }

        foreach ($regexResults as $result) {
            foreach ($result->matches as $match) {
                $regexMatches[] = $match->content;
            }
        }

        $this->assertCount(2, $literalMatches);
        $this->assertCount(1, $regexMatches);
        $this->assertSame('$foo = new Foo();', $regexMatches[0]);
    }

    #[Test]
    public function itCanIncludeHiddenAndIgnoredFilesWhenRequested(): void
    {
        $defaultResults = Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true),
        );
        $expandedResults = Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, includeHidden: true, respectIgnore: false),
        );

        $defaultCount = array_sum(array_map(static fn ($result): int => $result->matchCount(), $defaultResults));
        $expandedCount = array_sum(array_map(static fn ($result): int => $result->matchCount(), $expandedResults));

        $this->assertSame(2, $defaultCount);
        $this->assertSame(4, $expandedCount);
    }

    #[Test]
    public function itSupportsFileListingAndIncrementalRefresh(): void
    {
        $countResults = Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, countOnly: true),
        );
        $filesWithMatches = Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, filesWithMatches: true),
        );
        $filesWithoutMatches = Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, filesWithoutMatches: true),
        );

        $countMap = [];
        $filesWithMatchesMap = [];

        $withoutMatches = array_values(array_map(static fn ($result): string => basename($result->file), array_filter(
            $filesWithoutMatches,
            static fn ($result): bool => !$result->hasMatches(),
        )));

        foreach ($countResults as $result) {
            $countMap[basename($result->file)] = $result->matchCount();
        }

        foreach ($filesWithMatches as $result) {
            $filesWithMatchesMap[basename($result->file)] = $result->hasMatches();
        }

        $this->assertSame(1, $countMap['App.php']);
        $this->assertSame(1, $countMap['Util.php']);
        $this->assertTrue($filesWithMatchesMap['App.php']);
        $this->assertTrue($filesWithMatchesMap['Util.php']);
        $this->assertContains('notes.txt', $withoutMatches);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, 'src/New.php', "<?php\nfunction newer(): void {}\n");
        Greph::refreshTextIndex($this->workspace);

        $results = Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true),
        );

        $matchedFiles = array_values(array_map(static fn ($result): string => basename($result->file), array_filter(
            $results,
            static fn ($result): bool => $result->hasMatches(),
        )));

        $this->assertNotContains('App.php', $matchedFiles);
        $this->assertContains('New.php', $matchedFiles);
    }

    #[Test]
    public function itUsesWholeWordFilteringWithIndexedCandidates(): void
    {
        Workspace::writeFile($this->workspace, 'src/Subword.php', "<?php\n\$label = 'dysfunction';\n");
        Greph::refreshTextIndex($this->workspace);

        $results = Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, wholeWord: true),
        );

        $matchedFiles = array_values(array_map(
            static fn ($result): string => basename($result->file),
            array_filter($results, static fn ($result): bool => $result->hasMatches()),
        ));

        $this->assertContains('App.php', $matchedFiles);
        $this->assertContains('Util.php', $matchedFiles);
        $this->assertNotContains('Subword.php', $matchedFiles);
    }

    #[Test]
    public function itCachesRootLiteralQueriesAndInvalidatesThemOnRefresh(): void
    {
        $initialResults = Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true),
        );

        $cacheFiles = glob($this->workspace . '/.greph-index/queries/*.phpbin*') ?: [];
        $initialMatchedFiles = array_values(array_map(
            static fn ($result): string => basename($result->file),
            array_filter($initialResults, static fn ($result): bool => $result->hasMatches()),
        ));

        $this->assertNotSame([], $cacheFiles);
        $this->assertContains('App.php', $initialMatchedFiles);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n\$value = 1;\n");
        Greph::refreshTextIndex($this->workspace);

        $refreshedResults = Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true),
        );

        $refreshedCacheFiles = glob($this->workspace . '/.greph-index/queries/*.phpbin*') ?: [];
        $refreshedMatchedFiles = array_values(array_map(
            static fn ($result): string => basename($result->file),
            array_filter($refreshedResults, static fn ($result): bool => $result->hasMatches()),
        ));

        $this->assertNotSame([], $refreshedCacheFiles);
        $this->assertNotContains('App.php', $refreshedMatchedFiles);
    }

    #[Test]
    public function itKeepsSummaryCachesSeparateFromNormalMatchResults(): void
    {
        Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, filesWithMatches: true),
        );

        $normalResults = Greph::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true),
        );

        $matchMap = [];

        foreach ($normalResults as $result) {
            $matchMap[basename($result->file)] = count($result->matches);
        }

        $this->assertSame(1, $matchMap['App.php']);
        $this->assertSame(1, $matchMap['Util.php']);
    }

    #[Test]
    public function itCachesShortLiteralRootQueries(): void
    {
        $firstResults = Greph::searchTextIndexed(
            'fu',
            $this->workspace,
            new TextSearchOptions(fixedString: true),
        );
        $secondResults = Greph::searchTextIndexed(
            'fu',
            $this->workspace,
            new TextSearchOptions(fixedString: true),
        );

        $cacheFiles = glob($this->workspace . '/.greph-index/queries/*.phpbin') ?: [];

        $this->assertNotSame([], $cacheFiles);
        $this->assertSame(
            array_map(static fn ($result): int => $result->matchCount(), $firstResults),
            array_map(static fn ($result): int => $result->matchCount(), $secondResults),
        );
    }

    #[Test]
    public function itSearchesThroughNamedIndexSets(): void
    {
        $setWorkspace = Workspace::createDirectory('indexed-text-set');
        try {
            Workspace::writeFile($setWorkspace, 'core/Core.php', "<?php\nfunction coreThing(): void {}\n");
            Workspace::writeFile($setWorkspace, 'plugins/Demo/Plugin.php', "<?php\nfunction pluginThing(): void {}\n");
            Workspace::writeFile(
                $setWorkspace,
                '.greph-index-set.json',
                (string) json_encode([
                    'name' => 'wordpress-local',
                    'indexes' => [
                        ['name' => 'core-text', 'root' => 'core', 'mode' => 'text', 'lifecycle' => 'static', 'priority' => 20],
                        ['name' => 'plugin-text', 'root' => 'plugins/Demo', 'mode' => 'text', 'lifecycle' => 'opportunistic-refresh', 'priority' => 10],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
            Greph::buildTextIndex($setWorkspace . '/core');
            Greph::buildTextIndex($setWorkspace . '/plugins/Demo');

            $matches = Greph::searchTextIndexedSet(
                'function',
                $setWorkspace,
                new TextSearchOptions(fixedString: true),
                $setWorkspace . '/.greph-index-set.json',
            );

            $matchedFiles = array_values(array_map(
                static fn ($result): string => basename($result->file),
                array_filter($matches, static fn ($result): bool => $result->hasMatches()),
            ));

            $this->assertContains('Core.php', $matchedFiles);
            $this->assertContains('Plugin.php', $matchedFiles);
        } finally {
            Workspace::remove($setWorkspace);
        }
    }

    #[Test]
    public function itSupportsOpportunisticRefreshStrictStaleChecksAndMultiIndexSearch(): void
    {
        $refreshWorkspace = Workspace::createDirectory('indexed-text-lifecycle');
        Workspace::writeFile($refreshWorkspace, 'src/App.php', "<?php\nfunction alpha(): void {}\n");
        Greph::buildTextIndex($refreshWorkspace, lifecycle: IndexLifecycleProfile::OpportunisticRefresh);

        sleep(1);
        Workspace::writeFile($refreshWorkspace, 'src/New.php', "<?php\nfunction beta(): void {}\n");

        $refreshedResults = Greph::searchTextIndexed(
            'beta',
            $refreshWorkspace,
            new TextSearchOptions(fixedString: true),
        );

        $this->assertContains('New.php', array_map(
            static fn ($result): string => basename($result->file),
            array_filter($refreshedResults, static fn ($result): bool => $result->hasMatches()),
        ));

        $strictWorkspace = Workspace::createDirectory('indexed-text-strict');
        Workspace::writeFile($strictWorkspace, 'src/App.php', "<?php\nfunction strictThing(): void {}\n");
        Greph::buildTextIndex($strictWorkspace, lifecycle: IndexLifecycleProfile::StrictStaleCheck);

        sleep(1);
        Workspace::writeFile($strictWorkspace, 'src/New.php', "<?php\nfunction strictFresh(): void {}\n");

        try {
            Greph::searchTextIndexed(
                'strictFresh',
                $strictWorkspace,
                new TextSearchOptions(fixedString: true),
            );
            self::fail('Expected strict stale text index to reject search.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Text index is stale', $exception->getMessage());
        }

        $multiWorkspace = Workspace::createDirectory('indexed-text-many');
        Workspace::writeFile($multiWorkspace, 'core/Core.php', "<?php\nfunction coreThing(): void {}\n");
        Workspace::writeFile($multiWorkspace, 'plugins/Demo/Plugin.php', "<?php\nfunction pluginThing(): void {}\n");
        Greph::buildTextIndex($multiWorkspace . '/core');
        Greph::buildTextIndex($multiWorkspace . '/plugins/Demo');

        $manyResults = Greph::searchTextIndexedMany(
            'function',
            $multiWorkspace,
            [
                $multiWorkspace . '/core/.greph-index',
                $multiWorkspace . '/plugins/Demo/.greph-index',
            ],
            new TextSearchOptions(fixedString: true),
        );

        $matched = array_map(
            static fn ($result): string => basename($result->file),
            array_filter($manyResults, static fn ($result): bool => $result->hasMatches()),
        );

        $this->assertContains('Core.php', $matched);
        $this->assertContains('Plugin.php', $matched);

        Workspace::remove($refreshWorkspace);
        Workspace::remove($strictWorkspace);
        Workspace::remove($multiWorkspace);
    }
}
