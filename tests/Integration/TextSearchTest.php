<?php

declare(strict_types=1);

namespace Phgrep\Tests\Integration;

use Phgrep\Phgrep;
use Phgrep\Tests\Support\Workspace;
use Phgrep\Text\TextSearchOptions;
use Phgrep\Walker\FileTypeFilter;
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
        Workspace::writeFile($this->workspace, 'README.md', "function in markdown\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itFindsLiteralMatchesInPhpFiles(): void
    {
        $results = Phgrep::searchText(
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
                [$this->workspace . '/src/Util.php', 2, 'function helper(): void {}'],
            ],
            $matchedLines,
        );
    }

    #[Test]
    public function itSupportsRegexSearchAndParallelWorkers(): void
    {
        $results = Phgrep::searchText(
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
}
