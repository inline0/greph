<?php

declare(strict_types=1);

namespace Greph\Tests\Integration;

use Greph\Greph;
use Greph\Tests\Support\Workspace;
use Greph\Text\TextSearchOptions;
use Greph\Walker\FileTypeFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextSearchTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('text-search');
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction saveThing(): void {}\n\$foo = new Foo();\n");
        Workspace::writeFile($this->workspace, 'src/Util.php', "<?php\nfunction helper(): void {}\n\$bar = new Bar();\n");
        Workspace::writeFile($this->workspace, 'src/Multi.php', "<?php\nfunction one(): void {}\nfunction two(): void {}\n");
        Workspace::writeFile($this->workspace, 'README.md', "function in markdown\n");
        Workspace::writeFile($this->workspace, 'notes.txt', "plain text\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itFindsLiteralMatchesInPhpFiles(): void
    {
        $results = Greph::searchText(
            'function',
            $this->workspace,
            new TextSearchOptions(fileTypeFilter: new FileTypeFilter(['php'])),
        );

        $matchedLines = [];

        foreach ($results as $result) {
            foreach ($result->matches as $match) {
                $matchedLines[] = [$result->file, $match->line, $match->content];
            }
        }

        usort($matchedLines, static fn (array $left, array $right): int => $left <=> $right);

        $this->assertSame(
            [
                [$this->workspace . '/src/App.php', 2, 'function saveThing(): void {}'],
                [$this->workspace . '/src/Multi.php', 2, 'function one(): void {}'],
                [$this->workspace . '/src/Multi.php', 3, 'function two(): void {}'],
                [$this->workspace . '/src/Util.php', 2, 'function helper(): void {}'],
            ],
            $matchedLines,
        );
    }

    #[Test]
    public function itSupportsRegexSearchAndParallelWorkers(): void
    {
        $results = Greph::searchText(
            '\$[a-z]+ = new [A-Za-z]+\(\)',
            $this->workspace,
            new TextSearchOptions(jobs: 2, fileTypeFilter: new FileTypeFilter(['php'])),
        );

        $matchCount = 0;

        foreach ($results as $result) {
            $matchCount += $result->matchCount();
        }

        $this->assertSame(2, $matchCount);
    }

    #[Test]
    public function itSupportsCountAndFileListingModes(): void
    {
        $countResults = Greph::searchText(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, countOnly: true),
        );
        $filesWithMatches = Greph::searchText(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, filesWithMatches: true),
        );
        $filesWithoutMatches = Greph::searchText(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, filesWithoutMatches: true),
        );

        $countMap = [];
        $filesWithMatchesMap = [];
        $filesWithoutMatchesMap = [];

        foreach ($countResults as $result) {
            $countMap[basename($result->file)] = $result->matchCount();
        }

        foreach ($filesWithMatches as $result) {
            $filesWithMatchesMap[basename($result->file)] = $result->hasMatches();
        }

        foreach ($filesWithoutMatches as $result) {
            $filesWithoutMatchesMap[basename($result->file)] = $result->hasMatches();
        }

        $this->assertSame(1, $countMap['App.php']);
        $this->assertSame(2, $countMap['Multi.php']);
        $this->assertTrue($filesWithMatchesMap['App.php']);
        $this->assertTrue($filesWithMatchesMap['Multi.php']);
        $this->assertFalse($filesWithoutMatchesMap['notes.txt']);
    }

    #[Test]
    public function itSupportsContextInvertMatchAndMaxCount(): void
    {
        $contextResults = Greph::searchText(
            'function',
            $this->workspace . '/src/Multi.php',
            new TextSearchOptions(fixedString: true, beforeContext: 1, afterContext: 1),
        );
        $invertResults = Greph::searchText(
            'function',
            $this->workspace . '/notes.txt',
            new TextSearchOptions(fixedString: true, invertMatch: true),
        );
        $maxCountResults = Greph::searchText(
            'function',
            $this->workspace . '/src/Multi.php',
            new TextSearchOptions(fixedString: true, maxCount: 1),
        );

        $this->assertSame('<?php', $contextResults[0]->matches[0]->beforeContext[0]['content']);
        $this->assertSame('function two(): void {}', $contextResults[0]->matches[0]->afterContext[0]['content']);
        $this->assertSame(1, $invertResults[0]->matches[0]->column);
        $this->assertSame('', $invertResults[0]->matches[0]->matchedText);
        $this->assertSame(1, $maxCountResults[0]->matchCount());
    }
}
