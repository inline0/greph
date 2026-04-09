<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Index;

use Phgrep\Index\IndexedTextSearcher;
use Phgrep\Index\TextIndexStore;
use Phgrep\Phgrep;
use Phgrep\Tests\Support\Workspace;
use Phgrep\Text\TextFileResult;
use Phgrep\Text\TextSearchOptions;
use Phgrep\Walker\FileTypeFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexedTextSearcherTest extends TestCase
{
    private string $workspace;

    private string $externalWorkspace;

    private IndexedTextSearcher $searcher;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('indexed-text-searcher');
        Workspace::writeFile($this->workspace, '.gitignore', "ignored.php\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction visible(): void {}\n");
        Workspace::writeFile($this->workspace, 'src/Util.php', "<?php\nfunction useful(): void {}\n");
        Workspace::writeFile($this->workspace, '.hidden/Hidden.php', "<?php\nfunction hiddenThing(): void {}\n");
        Workspace::writeFile($this->workspace, 'notes.txt', "alpha\nbeta\n");
        Workspace::writeFile($this->workspace, 'ignored.php', "<?php\nfunction ignoredThing(): void {}\n");

        $this->externalWorkspace = Workspace::createDirectory('indexed-text-searcher-external');
        Workspace::writeFile($this->externalWorkspace, 'external.txt', "external needle\n");

        Phgrep::buildTextIndex($this->workspace);
        $this->searcher = new IndexedTextSearcher();
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
        Workspace::remove($this->externalWorkspace);
    }

    #[Test]
    public function itCoversPublicIndexedSearchEdgeCases(): void
    {
        $hiddenResults = $this->searcher->search(
            'hiddenThing',
            $this->workspace . '/.hidden/Hidden.php',
            new TextSearchOptions(fixedString: true),
        );
        $fallbackResults = $this->searcher->search(
            'external',
            $this->externalWorkspace . '/external.txt',
            new TextSearchOptions(fixedString: true),
            $this->workspace . '/.phgrep-index',
        );
        $invertResults = $this->searcher->search(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, invertMatch: true),
        );
        $shortSeedResults = $this->searcher->search(
            'fu',
            $this->workspace,
            new TextSearchOptions(fixedString: true),
        );

        Workspace::remove($this->workspace . '/src/App.php');
        $summaryResults = $this->searcher->search(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, countOnly: true),
        );

        $invertMatches = array_values(array_map(
            static fn (TextFileResult $result): string => basename($result->file),
            array_filter($invertResults, static fn (TextFileResult $result): bool => $result->hasMatches()),
        ));
        $summaryMap = [];

        foreach ($summaryResults as $result) {
            $summaryMap[basename($result->file)] = $result->matchCount();
        }

        $this->assertCount(1, $hiddenResults);
        $this->assertSame(1, $hiddenResults[0]->matchCount());
        $this->assertCount(1, $fallbackResults);
        $this->assertSame(1, $fallbackResults[0]->matchCount());
        $this->assertContains('notes.txt', $invertMatches);
        $this->assertSame(2, array_sum(array_map(
            static fn (TextFileResult $result): int => $result->matchCount(),
            $shortSeedResults,
        )));
        $this->assertSame(0, $summaryMap['App.php']);
    }

    #[Test]
    public function itRejectsMissingPathsAndMissingIndexes(): void
    {
        try {
            $this->searcher->search('needle', $this->workspace . '/missing.txt', new TextSearchOptions(fixedString: true));
            self::fail('Expected missing path lookup to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Path does not exist', $exception->getMessage());
        }

        try {
            $this->searcher->search('needle', $this->externalWorkspace, new TextSearchOptions(fixedString: true));
            self::fail('Expected missing index lookup to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No index found', $exception->getMessage());
        }
    }

    #[Test]
    public function itCoversQueryPlanningHelpersAndFilters(): void
    {
        $store = new TextIndexStore();
        $querySeeds = $this->invokeMethod(
            $this->searcher,
            'querySeeds',
            'foo|barbaz',
            new TextSearchOptions(),
        );
        $shortFixedSeeds = $this->invokeMethod(
            $this->searcher,
            'querySeeds',
            'ab',
            new TextSearchOptions(fixedString: true),
        );
        $candidateIdsWithoutTrigrams = $this->invokeMethod(
            $this->searcher,
            'candidateIds',
            $this->workspace . '/.phgrep-index',
            ['ab'],
        );
        $candidateIdsWithTrigrams = $this->invokeMethod(
            $this->searcher,
            'candidateIds',
            $this->workspace . '/.phgrep-index',
            ['function'],
        );
        $directSummaryAllowed = $this->invokeMethod(
            $this->searcher,
            'canUseDirectLiteralSummary',
            'needle',
            new TextSearchOptions(fixedString: true, filesWithMatches: true),
        );
        $directSummaryRejected = $this->invokeMethod(
            $this->searcher,
            'canUseDirectLiteralSummary',
            '',
            new TextSearchOptions(fixedString: true, filesWithMatches: true),
        );
        $cacheSupported = $this->invokeMethod(
            $this->searcher,
            'supportsQueryCache',
            'needle',
            new TextSearchOptions(fixedString: true),
        );
        $cacheRejected = $this->invokeMethod(
            $this->searcher,
            'supportsQueryCache',
            'needle',
            new TextSearchOptions(
                fixedString: true,
                includeHidden: true,
                respectIgnore: false,
                followSymlinks: true,
                skipBinaryFiles: false,
                includeGitDirectory: true,
                fileTypeFilter: new FileTypeFilter(['php']),
                maxFileSizeBytes: 1,
                globPatterns: ['*.php'],
            ),
        );
        $selection = $this->invokeMethod(
            $this->searcher,
            'buildSelection',
            [
                $this->workspace,
                $this->workspace . '/src/App.php',
                $this->externalWorkspace,
            ],
            $this->workspace,
        );
        $matchesSelection = $this->invokeMethod(
            $this->searcher,
            'matchesSelection',
            $this->workspace . '/src/App.php',
            $selection,
        );
        $doesNotMatchSelection = $this->invokeMethod(
            $this->searcher,
            'matchesSelection',
            $this->externalWorkspace . '/external.txt',
            $selection,
        );
        $globMatch = $this->invokeMethod(
            $this->searcher,
            'matchesGlobPatterns',
            $this->workspace,
            $this->workspace,
            ['indexed-text-searcher*'],
        );
        $globMiss = $this->invokeMethod(
            $this->searcher,
            'matchesGlobPatterns',
            $this->workspace . '/notes.txt',
            $this->workspace,
            ['*.php'],
        );
        $filterMiss = $this->invokeMethod(
            $this->searcher,
            'matchesQueryFilters',
            ['id' => 1, 'p' => 'notes.txt', 's' => 100, 'm' => 1, 'h' => false, 'g' => false, 'o' => 0],
            $this->workspace . '/notes.txt',
            $this->workspace,
            new TextSearchOptions(fixedString: true, maxFileSizeBytes: 10),
        );
        $intersection = $this->invokeMethod($this->searcher, 'intersectSortedFileIds', [1, 3, 5], [2, 3, 6]);
        $locatedIndexPath = $this->invokeMethod(
            $this->searcher,
            'resolveIndexPath',
            [$this->workspace],
            $this->workspace . '/.phgrep-index',
        );
        $unlocatedIndexPath = $this->invokeMethod(
            $this->searcher,
            'resolveIndexPath',
            [$this->externalWorkspace],
            null,
        );

        $this->assertSame(['barbaz'], $querySeeds);
        $this->assertSame([], $shortFixedSeeds);
        $this->assertSame([], $candidateIdsWithoutTrigrams);
        $this->assertArrayHasKey(1, $candidateIdsWithTrigrams);
        $this->assertTrue($directSummaryAllowed);
        $this->assertFalse($directSummaryRejected);
        $this->assertTrue($cacheSupported);
        $this->assertFalse($cacheRejected);
        $this->assertSame([$this->workspace . '/src/App.php' => true], $selection['files']);
        $this->assertSame([$this->workspace], $selection['directories']);
        $this->assertTrue($matchesSelection);
        $this->assertFalse($doesNotMatchSelection);
        $this->assertTrue($globMatch);
        $this->assertFalse($globMiss);
        $this->assertFalse($filterMiss);
        $this->assertSame([3], $intersection);
        $this->assertSame($store->defaultPath($this->workspace), $locatedIndexPath);
        $this->assertNull($unlocatedIndexPath);
    }

    #[Test]
    public function itCoversIndexedTextFallbackSummaryAndLiteralHelpers(): void
    {
        $missingIndexedPath = Workspace::writeFile($this->workspace, 'src/Newer.php', "<?php\nfunction future(): void {}\n");
        $indexPath = $this->workspace . '/.phgrep-index';

        $deletedFallback = $this->searcher->search(
            'function',
            $missingIndexedPath,
            new TextSearchOptions(fixedString: true, countOnly: true),
            $indexPath,
        );
        $externalFallback = $this->searcher->search(
            'external',
            $this->externalWorkspace . '/external.txt',
            new TextSearchOptions(fixedString: true, countOnly: true),
            $indexPath,
        );
        $shortSummary = $this->searcher->search(
            'fu',
            $this->workspace,
            new TextSearchOptions(fixedString: true, countOnly: true),
            $indexPath,
        );
        $caseInsensitiveContains = $this->invokeMethod(
            $this->searcher,
            'contentsContainLiteral',
            "NEEDLE\n",
            'needle',
            true,
        );
        $lastLineCount = $this->invokeMethod(
            $this->searcher,
            'countLiteralMatchingLines',
            "one\nneedle",
            'needle',
            false,
            null,
        );
        $caseInsensitiveCount = $this->invokeMethod(
            $this->searcher,
            'countLiteralMatchingLines',
            "NEEDLE\nhay\n",
            'needle',
            true,
            1,
        );
        $candidateIdsBreak = $this->invokeMethod(
            $this->searcher,
            'candidateIds',
            $indexPath,
            ['function', 'alpha'],
        );
        $emptyRegexSeeds = $this->invokeMethod(
            $this->searcher,
            'querySeeds',
            '.+',
            new TextSearchOptions(),
        );

        $this->assertSame(1, $deletedFallback[0]->matchCount());
        $this->assertSame(1, $externalFallback[0]->matchCount());
        $this->assertSame(2, array_sum(array_map(static fn (TextFileResult $result): int => $result->matchCount(), $shortSummary)));
        $this->assertTrue($caseInsensitiveContains);
        $this->assertSame(1, $lastLineCount);
        $this->assertSame(1, $caseInsensitiveCount);
        $this->assertSame([], $candidateIdsBreak);
        $this->assertSame([], $emptyRegexSeeds);
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
