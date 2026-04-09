<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Output;

use Phgrep\Output\GrepFormatter;
use Phgrep\Text\TextFileResult;
use Phgrep\Text\TextMatch;
use Phgrep\Text\TextSearchOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GrepFormatterTest extends TestCase
{
    #[Test]
    public function itFormatsCountsFileListsAndContext(): void
    {
        $formatter = new GrepFormatter();
        $match = new TextMatch(
            file: 'src/App.php',
            line: 4,
            column: 1,
            content: 'needle',
            matchedText: 'needle',
            captures: [],
            beforeContext: [['line' => 3, 'content' => 'before']],
            afterContext: [['line' => 5, 'content' => 'after']],
        );
        $matchResult = new TextFileResult('src/App.php', [$match], 2);
        $emptyResult = new TextFileResult('src/Other.php', [], 0);

        $countOutput = $formatter->format(
            [$matchResult, $emptyResult],
            new TextSearchOptions(countOnly: true, showFileNames: true),
        );
        $countWithoutFilesOutput = $formatter->format(
            [$matchResult, $emptyResult],
            new TextSearchOptions(countOnly: true, showFileNames: false),
        );
        $filesWithMatchesOutput = $formatter->format(
            [$matchResult, $emptyResult],
            new TextSearchOptions(filesWithMatches: true),
        );
        $filesWithoutMatchesOutput = $formatter->format(
            [$matchResult, $emptyResult],
            new TextSearchOptions(filesWithoutMatches: true),
        );
        $contextOutput = $formatter->format(
            [$matchResult],
            new TextSearchOptions(showFileNames: true, showLineNumbers: true),
        );
        $contentOnlyOutput = $formatter->format(
            [new TextFileResult('src/App.php', [new TextMatch('src/App.php', 4, 1, 'needle', 'needle', [])])],
            new TextSearchOptions(showFileNames: false, showLineNumbers: false),
        );

        $this->assertSame("src/App.php:2\nsrc/Other.php:0\n", $countOutput);
        $this->assertSame("2\n0\n", $countWithoutFilesOutput);
        $this->assertSame("src/App.php\n", $filesWithMatchesOutput);
        $this->assertSame("src/Other.php\n", $filesWithoutMatchesOutput);
        $this->assertSame("src/App.php-3-before\nsrc/App.php:4:needle\nsrc/App.php-5-after\n", $contextOutput);
        $this->assertSame("needle\n", $contentOnlyOutput);
        $this->assertSame('', $formatter->format([], new TextSearchOptions()));
    }
}
