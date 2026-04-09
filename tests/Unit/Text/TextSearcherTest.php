<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Text;

use Phgrep\Tests\Support\Workspace;
use Phgrep\Text\TextFileResult;
use Phgrep\Text\TextSearchOptions;
use Phgrep\Text\TextSearcher;
use Phgrep\Walker\FileList;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextSearcherTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('text-searcher');
        Workspace::writeFile($this->workspace, 'context.txt', "zero\nmatch\nafter one\nafter two\nignored\n");
        Workspace::writeFile($this->workspace, 'count.txt', "match\nmatch\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itSortsWithoutAnExplicitOrder(): void
    {
        $searcher = new TextSearcher();
        $results = [
            new TextFileResult('b.php', []),
            new TextFileResult('a.php', []),
        ];

        $this->assertSame($results, $searcher->sortResults($results));
    }

    #[Test]
    public function itHandlesContextMaxCountAndCountOnlyFastPaths(): void
    {
        $searcher = new TextSearcher();
        $contextResults = $searcher->searchFiles(
            new FileList([$this->workspace . '/context.txt']),
            'match',
            new TextSearchOptions(fixedString: true, beforeContext: 1, afterContext: 2, maxCount: 1),
        );
        $countResults = $searcher->searchFiles(
            new FileList([$this->workspace . '/count.txt']),
            'match',
            new TextSearchOptions(fixedString: true, countOnly: true, maxCount: 1),
        );
        $breakResults = $searcher->searchFiles(
            new FileList([$this->workspace . '/count.txt']),
            'match',
            new TextSearchOptions(fixedString: true, beforeContext: 1, maxCount: 1),
        );
        $missingResults = $searcher->searchFiles(
            new FileList([$this->workspace . '/missing.txt']),
            'match',
            new TextSearchOptions(fixedString: true),
        );

        $this->assertCount(1, $contextResults);
        $this->assertSame('zero', $contextResults[0]->matches[0]->beforeContext[0]['content']);
        $this->assertSame(
            ['after one', 'after two'],
            array_column($contextResults[0]->matches[0]->afterContext, 'content'),
        );
        $this->assertSame(1, $countResults[0]->matchCount());
        $this->assertSame(1, $breakResults[0]->matchCount());
        $this->assertSame(0, $missingResults[0]->matchCount());
    }
}
