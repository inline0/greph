<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Text;

use Greph\Text\AnchoredLiteralSearcher;
use Greph\Text\LineMatch;
use Greph\Text\LiteralSearcher;
use Greph\Text\RegexSearcher;
use Greph\Tests\Support\Workspace;
use Greph\Text\TextFileResult;
use Greph\Text\TextMatcher;
use Greph\Text\TextSearchOptions;
use Greph\Text\TextSearcher;
use Greph\Walker\FileList;
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
        Workspace::writeFile($this->workspace, 'final-line.txt', "alpha\r\nmatch");
        Workspace::writeFile($this->workspace, 'literal-scan.txt', "noise\nprefix match suffix match\nskip\nMATCH again\n");
        Workspace::writeFile($this->workspace, 'whole-word-scan.txt', "match\nmatching\nprefix match suffix\nMAtch\n");
        Workspace::writeFile($this->workspace, 'regex-scan.txt', "noise\n\$foo = new Bar()\n\$foo = new Bar(); \$bar = new Baz()\nvalue = old Bar()\n");
        Workspace::writeFile($this->workspace, 'anchored-scan.txt', "function alpha()\ncall(); more();\n}\n");
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
    public function itSortsUsingAnExplicitFileOrder(): void
    {
        $searcher = new TextSearcher();
        $results = [
            new TextFileResult('z.php', []),
            new TextFileResult('a.php', []),
            new TextFileResult('b.php', []),
        ];

        $sorted = $searcher->sortResults($results, ['b.php', 'a.php']);

        $this->assertSame(['b.php', 'a.php', 'z.php'], array_map(
            static fn (TextFileResult $result): string => $result->file,
            $sorted,
        ));
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
        $finalLineResults = $searcher->searchFiles(
            new FileList([$this->workspace . '/final-line.txt']),
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
        $this->assertSame(1, $finalLineResults[0]->matchCount());
        $this->assertSame(2, $finalLineResults[0]->matches[0]->line);
        $this->assertSame('match', $finalLineResults[0]->matches[0]->content);
    }

    #[Test]
    public function itUsesOccurrenceScanningForPlainLiteralSearches(): void
    {
        $searcher = new TextSearcher();
        $results = $searcher->searchFiles(
            new FileList([$this->workspace . '/literal-scan.txt']),
            'match',
            new TextSearchOptions(fixedString: true, caseInsensitive: true),
        );

        $this->assertSame(2, $results[0]->matchCount());
        $this->assertSame(2, $results[0]->matches[0]->line);
        $this->assertSame(8, $results[0]->matches[0]->column);
        $this->assertSame('match', $results[0]->matches[0]->matchedText);
        $this->assertSame(4, $results[0]->matches[1]->line);
        $this->assertSame('MATCH', $results[0]->matches[1]->matchedText);
    }

    #[Test]
    public function itUsesOccurrenceScanningForAsciiWholeWordLiteralSearches(): void
    {
        $searcher = new TextSearcher();
        $results = $searcher->searchFiles(
            new FileList([$this->workspace . '/whole-word-scan.txt']),
            'match',
            new TextSearchOptions(fixedString: true, caseInsensitive: true, wholeWord: true),
        );

        $this->assertSame(3, $results[0]->matchCount());
        $this->assertSame([1, 3, 4], array_map(
            static fn (LineMatch|\Greph\Text\TextMatch $match): int => $match->line,
            $results[0]->matches,
        ));
        $this->assertSame('match', $results[0]->matches[0]->matchedText);
        $this->assertSame('match', $results[0]->matches[1]->matchedText);
        $this->assertSame('MAtch', $results[0]->matches[2]->matchedText);
    }

    #[Test]
    public function itUsesOccurrenceScanningForRegexSeedLiterals(): void
    {
        $searcher = new TextSearcher();
        $results = $searcher->searchFiles(
            new FileList([$this->workspace . '/regex-scan.txt']),
            '\$[A-Za-z_][A-Za-z0-9_]* = new [A-Za-z_][A-Za-z0-9_]*\(\)',
            new TextSearchOptions(),
        );

        $this->assertSame(2, $results[0]->matchCount());
        $this->assertSame(2, $results[0]->matches[0]->line);
        $this->assertSame(1, $results[0]->matches[0]->column);
        $this->assertSame('$foo = new Bar()', $results[0]->matches[0]->matchedText);
        $this->assertSame(3, $results[0]->matches[1]->line);
        $this->assertSame('$foo = new Bar()', $results[0]->matches[1]->matchedText);
    }

    #[Test]
    public function itUsesOccurrenceScanningForAnchoredRegexLiterals(): void
    {
        $searcher = new TextSearcher();

        $prefixResults = $searcher->searchFiles(
            new FileList([$this->workspace . '/anchored-scan.txt']),
            '^function ',
            new TextSearchOptions(),
        );
        $suffixResults = $searcher->searchFiles(
            new FileList([$this->workspace . '/anchored-scan.txt']),
            '\);$',
            new TextSearchOptions(),
        );
        $fullLineResults = $searcher->searchFiles(
            new FileList([$this->workspace . '/anchored-scan.txt']),
            '^\}$',
            new TextSearchOptions(),
        );

        $this->assertSame(1, $prefixResults[0]->matchCount());
        $this->assertSame(1, $prefixResults[0]->matches[0]->line);
        $this->assertSame(1, $prefixResults[0]->matches[0]->column);
        $this->assertSame('function ', $prefixResults[0]->matches[0]->matchedText);

        $this->assertSame(1, $suffixResults[0]->matchCount());
        $this->assertSame(2, $suffixResults[0]->matches[0]->line);
        $this->assertSame(14, $suffixResults[0]->matches[0]->column);
        $this->assertSame(');', $suffixResults[0]->matches[0]->matchedText);

        $this->assertSame(1, $fullLineResults[0]->matchCount());
        $this->assertSame(3, $fullLineResults[0]->matches[0]->line);
        $this->assertSame(1, $fullLineResults[0]->matches[0]->column);
        $this->assertSame('}', $fullLineResults[0]->matches[0]->matchedText);
    }

    #[Test]
    public function itCoversStreamAndContentsFallbackPaths(): void
    {
        $searcher = new TextSearcher();
        $matcher = new class implements TextMatcher
        {
            public function match(string $line): ?LineMatch
            {
                if (!str_contains($line, 'match')) {
                    return null;
                }

                return new LineMatch(2, 'match');
            }

            public function mayMatchContents(string $contents): bool
            {
                return true;
            }
        };

        $streamResult = $this->invokeMethod(
            $searcher,
            'searchFileWithoutContext',
            $this->workspace . '/count.txt',
            $matcher,
            new TextSearchOptions(countOnly: true, maxCount: 1),
        );
        $streamListResult = $this->invokeMethod(
            $searcher,
            'searchFileWithoutContext',
            $this->workspace . '/count.txt',
            $matcher,
            new TextSearchOptions(filesWithMatches: true),
        );
        $streamMissingResult = $this->invokeMethod(
            $searcher,
            'searchFileWithoutContext',
            $this->workspace . '/missing-stream.txt',
            $matcher,
            new TextSearchOptions(),
        );

        $contentsResult = $this->invokeMethod(
            $searcher,
            'searchContentsWithoutContext',
            'memory.txt',
            "miss\r\nmatch\r\nfinal match",
            $matcher,
            new TextSearchOptions(countOnly: true, maxCount: 1),
        );
        $contentsWithoutMatches = $this->invokeMethod(
            $searcher,
            'searchContentsWithoutContext',
            'memory.txt',
            "alpha\nbeta",
            $matcher,
            new TextSearchOptions(filesWithoutMatches: true, invertMatch: true),
        );
        $quietStreamResult = $this->invokeMethod(
            $searcher,
            'searchFileWithoutContext',
            $this->workspace . '/count.txt',
            $matcher,
            new TextSearchOptions(quiet: true),
        );
        $quietContentsResult = $this->invokeMethod(
            $searcher,
            'searchContentsWithoutContext',
            'memory.txt',
            "miss\r\nmatch\r\nfinal match",
            $matcher,
            new TextSearchOptions(quiet: true),
        );

        $this->assertSame(1, $streamResult->matchCount());
        $this->assertSame(1, $streamListResult->matchCount());
        $this->assertSame(0, $streamMissingResult->matchCount());
        $this->assertSame(1, $contentsResult->matchCount());
        $this->assertSame(1, $contentsWithoutMatches->matchCount());
        $this->assertSame(1, $quietStreamResult->matchCount());
        $this->assertSame([], $quietStreamResult->matches);
        $this->assertSame(1, $quietContentsResult->matchCount());
        $this->assertSame([], $quietContentsResult->matches);
    }

    #[Test]
    public function itCoversFastPathHelpersAndDecisionBranches(): void
    {
        $searcher = new TextSearcher();
        $customMatcher = new class implements TextMatcher
        {
            public function match(string $line): ?LineMatch
            {
                return str_contains($line, 'needle') ? new LineMatch(1, 'needle') : null;
            }

            public function mayMatchContents(string $contents): bool
            {
                return str_contains($contents, 'needle');
            }
        };

        $shouldUseFastPath = $this->invokeMethod(
            $searcher,
            'shouldUseContentsFastPath',
            $customMatcher,
            new TextSearchOptions(),
        );
        $shouldUseFastPathForFilesWithout = $this->invokeMethod(
            $searcher,
            'shouldUseContentsFastPath',
            $customMatcher,
            new TextSearchOptions(filesWithoutMatches: true),
        );
        $literalFastPath = $this->invokeMethod(
            $searcher,
            'shouldUseContentsFastPath',
            new LiteralSearcher('needle'),
            new TextSearchOptions(fixedString: true),
        );
        $regexFastPath = $this->invokeMethod(
            $searcher,
            'shouldUseContentsFastPath',
            new RegexSearcher('new [A-Za-z]+', false, false, 'new '),
            new TextSearchOptions(),
        );
        $anchoredFastPath = $this->invokeMethod(
            $searcher,
            'shouldUseContentsFastPath',
            new AnchoredLiteralSearcher('function ', AnchoredLiteralSearcher::MODE_PREFIX),
            new TextSearchOptions(),
        );
        $literalRegexMatcher = $this->invokeMethod(
            $searcher,
            'createMatcher',
            'function',
            new TextSearchOptions(),
        );
        $prefixRegexMatcher = $this->invokeMethod(
            $searcher,
            'createMatcher',
            '^function ',
            new TextSearchOptions(),
        );

        $regexPrefilterResult = $this->invokeMethod(
            $searcher,
            'searchContentsByRegexPrefilter',
            'memory.txt',
            '$foo = new Bar()',
            new RegexSearcher('\$foo = new [A-Za-z_][A-Za-z0-9_]*\(\)', false, false, 'new '),
            new TextSearchOptions(),
        );
        $regexPrefilterQuietResult = $this->invokeMethod(
            $searcher,
            'searchContentsByRegexPrefilter',
            'memory.txt',
            '$foo = new Bar()',
            new RegexSearcher('\$foo = new [A-Za-z_][A-Za-z0-9_]*\(\)', false, false, 'new '),
            new TextSearchOptions(quiet: true),
        );
        $literalResult = $this->invokeMethod(
            $searcher,
            'searchContentsByLiteral',
            'memory.txt',
            "needle\r\nneedle",
            new LiteralSearcher('needle'),
            new TextSearchOptions(countOnly: true, maxCount: 1, fixedString: true),
        );
        $anchoredLiteralResult = $this->invokeMethod(
            $searcher,
            'searchContentsByAnchoredLiteral',
            'memory.txt',
            "function demo()\ncall(); more();\n}\n",
            new AnchoredLiteralSearcher(');', AnchoredLiteralSearcher::MODE_SUFFIX),
            new TextSearchOptions(),
        );

        $this->assertFalse($shouldUseFastPath);
        $this->assertTrue($shouldUseFastPathForFilesWithout);
        $this->assertTrue($literalFastPath);
        $this->assertTrue($regexFastPath);
        $this->assertTrue($anchoredFastPath);
        $this->assertInstanceOf(LiteralSearcher::class, $literalRegexMatcher);
        $this->assertInstanceOf(AnchoredLiteralSearcher::class, $prefixRegexMatcher);
        $this->assertSame(1, $regexPrefilterResult->matchCount());
        $this->assertSame(1, $regexPrefilterQuietResult->matchCount());
        $this->assertCount(0, $regexPrefilterQuietResult->matches);
        $this->assertSame(1, $literalResult->matchCount());
        $this->assertSame(1, $anchoredLiteralResult->matchCount());
        $this->assertSame(2, $anchoredLiteralResult->matches[0]->line);
    }

    #[Test]
    public function itCoversRemainingStreamAndContentsBranches(): void
    {
        $searcher = new TextSearcher();
        $matcher = new class implements TextMatcher
        {
            public function match(string $line): ?LineMatch
            {
                return str_contains($line, 'match') ? new LineMatch(3, 'match', ['value' => 'match']) : null;
            }

            public function mayMatchContents(string $contents): bool
            {
                return true;
            }
        };

        $streamResult = $this->invokeMethod(
            $searcher,
            'searchFileWithStreamWithoutContext',
            $this->workspace . '/context.txt',
            $matcher,
            new TextSearchOptions(maxCount: 1),
        );
        $contentsResult = $this->invokeMethod(
            $searcher,
            'searchContentsWithoutContext',
            'tail.txt',
            "alpha\ntail match",
            $matcher,
            new TextSearchOptions(maxCount: 1),
        );
        $contentsCountOnly = $this->invokeMethod(
            $searcher,
            'searchContentsWithoutContext',
            'count.txt',
            "match\nmatch",
            $matcher,
            new TextSearchOptions(countOnly: true),
        );
        $streamCountOnly = $this->invokeMethod(
            $searcher,
            'searchFileWithStreamWithoutContext',
            $this->workspace . '/count.txt',
            $matcher,
            new TextSearchOptions(countOnly: true),
        );

        $this->assertCount(1, $streamResult->matches);
        $this->assertSame('match', $streamResult->matches[0]->matchedText);
        $this->assertSame(['value' => 'match'], $streamResult->matches[0]->captures);
        $this->assertCount(1, $contentsResult->matches);
        $this->assertSame('tail match', $contentsResult->matches[0]->content);
        $this->assertSame(2, $contentsCountOnly->matchCount());
        $this->assertSame(2, $streamCountOnly->matchCount());
    }

    /**
     * @return mixed
     */
    private function invokeMethod(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }
}
