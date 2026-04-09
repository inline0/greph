<?php

declare(strict_types=1);

namespace Phgrep\Tests\Integration;

use Phgrep\Phgrep;
use Phgrep\Tests\Support\Workspace;
use Phgrep\Text\TextSearchOptions;
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

        Phgrep::buildTextIndex($this->workspace);
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itUsesTheIndexForLiteralAndRegexSearches(): void
    {
        $literalResults = Phgrep::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true),
        );
        $regexResults = Phgrep::searchTextIndexed(
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
        $defaultResults = Phgrep::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true),
        );
        $expandedResults = Phgrep::searchTextIndexed(
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
        $countResults = Phgrep::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, countOnly: true),
        );
        $filesWithMatches = Phgrep::searchTextIndexed(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, filesWithMatches: true),
        );
        $filesWithoutMatches = Phgrep::searchTextIndexed(
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
        Phgrep::refreshTextIndex($this->workspace);

        $results = Phgrep::searchTextIndexed(
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
}
