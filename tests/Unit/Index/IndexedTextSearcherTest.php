<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Index;

use Greph\Index\IndexedTextSearcher;
use Greph\Index\TextIndexStore;
use Greph\Greph;
use Greph\Tests\Support\Workspace;
use Greph\Text\LiteralSearcher;
use Greph\Text\TextFileResult;
use Greph\Text\TextSearchOptions;
use Greph\Walker\FileTypeFilter;
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

        Greph::buildTextIndex($this->workspace);
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
            $this->workspace . '/.greph-index',
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
        $quietResults = $this->searcher->search(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, quiet: true),
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
        $this->assertCount(1, $quietResults);
        $this->assertSame(1, $quietResults[0]->matchCount());
        $this->assertContains('notes.txt', $invertMatches);
        $this->assertSame(2, array_sum(array_map(
            static fn (TextFileResult $result): int => $result->matchCount(),
            $shortSeedResults,
        )));
        $this->assertSame(0, $summaryMap['App.php']);
    }

    #[Test]
    public function itUsesWordPostingsForIndexedWholeWordQueries(): void
    {
        Workspace::writeFile($this->workspace, 'src/Subword.php', "<?php\n\$label = 'dysfunction';\n");
        Greph::refreshTextIndex($this->workspace);

        $results = $this->searcher->search(
            'function',
            $this->workspace,
            new TextSearchOptions(fixedString: true, wholeWord: true),
        );

        $matches = [];

        foreach ($results as $result) {
            if ($result->hasMatches()) {
                $matches[] = basename($result->file);
            }
        }

        $this->assertContains('App.php', $matches);
        $this->assertContains('Util.php', $matches);
        $this->assertNotContains('Subword.php', $matches);
    }

    #[Test]
    public function itVerifiesCaseSensitiveWholeWordSummariesAgainstFileContents(): void
    {
        Workspace::writeFile($this->workspace, 'src/Lower.php', "<?php\n\$value = 'needle';\n");
        Workspace::writeFile($this->workspace, 'src/Upper.php', "<?php\n\$value = 'Needle';\n");
        Workspace::writeFile($this->workspace, 'src/Accent.php', "<?php\n\$value = 'éneedle';\n");
        Greph::refreshTextIndex($this->workspace);

        $matchingFiles = static function (array $results): array {
            $files = [];

            foreach ($results as $result) {
                if ($result instanceof TextFileResult && $result->hasMatches()) {
                    $files[] = basename($result->file);
                }
            }

            sort($files);

            return $files;
        };

        $caseSensitive = $this->searcher->search(
            'Needle',
            $this->workspace,
            new TextSearchOptions(fixedString: true, wholeWord: true, filesWithMatches: true),
        );
        $caseSensitiveQuiet = $this->searcher->search(
            'NEEDLE',
            $this->workspace,
            new TextSearchOptions(fixedString: true, wholeWord: true, quiet: true),
        );
        $caseInsensitive = $this->searcher->search(
            'NEEDLE',
            $this->workspace,
            new TextSearchOptions(fixedString: true, caseInsensitive: true, wholeWord: true, filesWithMatches: true),
        );
        $plan = $this->searcher->plan(
            'Needle',
            $this->workspace,
            new TextSearchOptions(fixedString: true, wholeWord: true, filesWithMatches: true),
        );

        $this->assertSame(['Upper.php'], $matchingFiles($caseSensitive));
        $this->assertSame([], $matchingFiles($caseSensitiveQuiet));
        $this->assertSame(['Lower.php', 'Upper.php'], $matchingFiles($caseInsensitive));
        $this->assertSame($matchingFiles(Greph::searchText(
            'NEEDLE',
            $this->workspace,
            new TextSearchOptions(fixedString: true, caseInsensitive: true, wholeWord: true, filesWithMatches: true),
        )), $matchingFiles($caseInsensitive));
        $this->assertIsArray($plan['plan']);
        $this->assertSame('word-postings', $plan['plan']['candidate_source']);
        $this->assertArrayNotHasKey('direct_word_summary', $plan['plan']);
    }

    #[Test]
    public function itKeepsEveryAlternationBranchInIndexedRegexSearch(): void
    {
        Workspace::writeFile($this->workspace, 'src/Branches.php', "<?php\n\$first = 'apple';\n\$second = 'banana';\n");
        Greph::refreshTextIndex($this->workspace);

        $indexed = $this->searcher->search('apple|banana', $this->workspace, new TextSearchOptions());
        $grouped = $this->searcher->search('= \'(apple|banana)\'', $this->workspace, new TextSearchOptions());
        $plan = $this->searcher->plan('apple|banana', $this->workspace, new TextSearchOptions());
        $groupedPlan = $this->searcher->plan('= \'(apple|banana)\';', $this->workspace, new TextSearchOptions());

        $lines = static function (array $results): array {
            $lines = [];

            foreach ($results as $result) {
                if (!$result instanceof TextFileResult) {
                    continue;
                }

                foreach ($result->matches as $match) {
                    $lines[] = $match->line;
                }
            }

            return $lines;
        };

        $this->assertSame([2, 3], $lines($indexed));
        $this->assertSame([2, 3], $lines($grouped));
        $this->assertIsArray($plan['plan']);
        $this->assertSame('full-scan', $plan['plan']['candidate_source']);
        $this->assertIsArray($groupedPlan['plan']);
        $this->assertSame(["= '"], $groupedPlan['plan']['seeds']);
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
        $appFileId = $this->indexedFileId($store, 'src/App.php');
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
            $this->workspace . '/.greph-index',
            ['ab'],
        );
        $candidateIdsWithTrigrams = $this->invokeArrayMethod(
            $this->searcher,
            'candidateIds',
            $this->workspace . '/.greph-index',
            ['function'],
        );
        $candidateIdsFromWords = $this->invokeArrayMethod(
            $this->searcher,
            'candidateIdsFromWordPostings',
            $this->workspace . '/.greph-index',
            'function',
        );
        $wholeWordToken = $this->invokeMethod(
            $this->searcher,
            'indexedWholeWordToken',
            'Function',
            new TextSearchOptions(fixedString: true, wholeWord: true),
        );
        $invalidWholeWordToken = $this->invokeMethod(
            $this->searcher,
            'indexedWholeWordToken',
            'function()',
            new TextSearchOptions(fixedString: true, wholeWord: true),
        );
        $directSummaryAllowed = $this->invokeMethod(
            $this->searcher,
            'canUseDirectLiteralSummary',
            'needle',
            new TextSearchOptions(fixedString: true, filesWithMatches: true),
        );
        $directQuietAllowed = $this->invokeMethod(
            $this->searcher,
            'canUseDirectLiteralSummary',
            'needle',
            new TextSearchOptions(fixedString: true, quiet: true),
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
            $store->load($this->workspace . '/.greph-index'),
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
            $store->load($this->workspace . '/.greph-index'),
        );
        $selection = $this->invokeArrayMethod(
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
            $this->workspace . '/.greph-index',
        );
        $unlocatedIndexPath = $this->invokeMethod(
            $this->searcher,
            'resolveIndexPath',
            [$this->externalWorkspace],
            null,
        );

        $this->assertSame([], $querySeeds);
        $this->assertSame([], $shortFixedSeeds);
        $this->assertSame([], $candidateIdsWithoutTrigrams);
        $this->assertArrayHasKey($appFileId, $candidateIdsWithTrigrams);
        $this->assertArrayHasKey($appFileId, $candidateIdsFromWords);
        $this->assertSame('function', $wholeWordToken);
        $this->assertNull($invalidWholeWordToken);
        $this->assertTrue($directSummaryAllowed);
        $this->assertTrue($directQuietAllowed);
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
        $indexPath = $this->workspace . '/.greph-index';

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
            new LiteralSearcher('needle', caseInsensitive: true),
        );
        $lastLineCount = $this->invokeMethod(
            $this->searcher,
            'countLiteralMatchingLines',
            "one\nneedle",
            new LiteralSearcher('needle'),
            null,
        );
        $caseInsensitiveCount = $this->invokeMethod(
            $this->searcher,
            'countLiteralMatchingLines',
            "NEEDLE\nhay\n",
            new LiteralSearcher('needle', caseInsensitive: true),
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

    #[Test]
    public function itUsesCachedResultsForWarmRootQueries(): void
    {
        $pattern = 'function';
        $options = new TextSearchOptions(fixedString: true, countOnly: true);

        $rootResults = $this->searcher->search($pattern, $this->workspace, $options);
        $cachedRootResults = $this->searcher->search($pattern, $this->workspace, $options);

        $this->assertCount(3, $rootResults);
        $this->assertSame(
            array_map(static fn (TextFileResult $result): int => $result->matchCount(), $rootResults),
            array_map(static fn (TextFileResult $result): int => $result->matchCount(), $cachedRootResults),
        );
    }

    #[Test]
    public function itCachesWarmShortLiteralRootQueries(): void
    {
        $pattern = 'fu';
        $options = new TextSearchOptions(fixedString: true, countOnly: true);

        $rootResults = $this->searcher->search($pattern, $this->workspace, $options);
        $cachedRootResults = $this->searcher->search($pattern, $this->workspace, $options);
        $cacheFiles = glob($this->workspace . '/.greph-index/queries/*.phpbin') ?: [];

        $this->assertNotSame([], $cacheFiles);
        $this->assertSame(
            array_map(static fn (TextFileResult $result): int => $result->matchCount(), $rootResults),
            array_map(static fn (TextFileResult $result): int => $result->matchCount(), $cachedRootResults),
        );
    }

    #[Test]
    public function itBypassesTheQueryCacheWhileTheIndexIsStale(): void
    {
        Workspace::writeFile($this->workspace, 'src/Alpha.php', "<?php\nfunction alpha() { return new Foo(); }\n");
        Workspace::writeFile($this->workspace, 'src/Beta.php', "<?php\n\$x = new Bar();\n");
        Greph::refreshTextIndex($this->workspace);
        $options = new TextSearchOptions(fixedString: true);
        $lines = static function (array $results): array {
            $lines = [];

            foreach ($results as $result) {
                if (!$result instanceof TextFileResult) {
                    continue;
                }

                foreach ($result->matches as $match) {
                    $lines[] = basename($result->file) . ':' . $match->content;
                }
            }

            sort($lines);

            return $lines;
        };

        $warm = $this->searcher->search('new', $this->workspace, $options);
        $this->assertSame(
            ['Alpha.php:function alpha() { return new Foo(); }', 'Beta.php:$x = new Bar();'],
            $lines($warm),
        );
        $this->assertNotSame([], glob($this->workspace . '/.greph-index/queries/*.phpbin') ?: []);

        Workspace::writeFile($this->workspace, 'src/Alpha.php', "<?php\nfunction alpha() { return 1; }\n");
        touch($this->workspace . '/src/Alpha.php', time() + 5);

        $stale = $this->searcher->search('new', $this->workspace, $options);
        $staleList = $this->searcher->search('new', $this->workspace, new TextSearchOptions(fixedString: true, filesWithMatches: true));
        $plan = $this->searcher->plan('new', $this->workspace, $options);

        $this->assertSame(['Beta.php:$x = new Bar();'], $lines($stale));
        $this->assertSame(['Beta.php'], array_values(array_map(
            static fn (TextFileResult $result): string => basename($result->file),
            array_filter($staleList, static fn (TextFileResult $result): bool => $result->hasMatches()),
        )));
        $this->assertIsArray($plan['cache']);
        $this->assertFalse($plan['cache']['query_cache_eligible']);
        $this->assertFalse($plan['cache']['query_cache_populate']);

        Greph::refreshTextIndex($this->workspace);
        $refreshedPlan = $this->searcher->plan('new', $this->workspace, $options);

        $this->assertSame(['Beta.php:$x = new Bar();'], $lines($this->searcher->search('new', $this->workspace, $options)));
        $this->assertIsArray($refreshedPlan['cache']);
        $this->assertTrue($refreshedPlan['cache']['query_cache_eligible']);
    }

    #[Test]
    public function itFoldsUnicodeCaseForIndexedFixedStrings(): void
    {
        Workspace::writeFile($this->workspace, 'fold/upper.txt', "CAFÉ menu\n");
        Workspace::writeFile($this->workspace, 'fold/kelvin.txt', "\u{212A}elvin scale\n");
        Workspace::writeFile($this->workspace, 'fold/plain.txt', "kelvin cafe\n");
        Greph::refreshTextIndex($this->workspace);
        $matchingFiles = static function (array $results): array {
            $files = [];

            foreach ($results as $result) {
                if ($result instanceof TextFileResult && $result->hasMatches()) {
                    $files[] = basename($result->file);
                }
            }

            sort($files);

            return $files;
        };
        $cases = [
            'café' => ['upper.txt'],
            'CAFÉ' => ['upper.txt'],
            'kelvin' => ['kelvin.txt', 'plain.txt'],
            'KELVIN' => ['kelvin.txt', 'plain.txt'],
        ];

        foreach ($cases as $needle => $expected) {
            foreach ([false, true] as $wholeWord) {
                $variants = [
                    'lines' => new TextSearchOptions(fixedString: true, caseInsensitive: true, wholeWord: $wholeWord),
                    'files' => new TextSearchOptions(fixedString: true, caseInsensitive: true, wholeWord: $wholeWord, filesWithMatches: true),
                    'count' => new TextSearchOptions(fixedString: true, caseInsensitive: true, wholeWord: $wholeWord, countOnly: true),
                ];

                foreach ($variants as $variant => $options) {
                    $label = sprintf('%s %s %s', $needle, $wholeWord ? '-w' : '', $variant);

                    $this->assertSame($expected, $matchingFiles($this->searcher->search($needle, $this->workspace, $options)), $label);
                    $this->assertSame($expected, $matchingFiles($this->searcher->search($needle, $this->workspace, $options)), $label . ' cached');
                }
            }

            $quiet = $this->searcher->search($needle, $this->workspace . '/fold', new TextSearchOptions(fixedString: true, caseInsensitive: true, quiet: true));
            $this->assertCount(1, array_filter($quiet, static fn (TextFileResult $result): bool => $result->hasMatches()), $needle . ' -q');
        }

        $kelvinPlan = $this->searcher->plan('kelvin', $this->workspace, new TextSearchOptions(fixedString: true, caseInsensitive: true, wholeWord: true));
        $cafePlan = $this->searcher->plan('café', $this->workspace, new TextSearchOptions(fixedString: true, caseInsensitive: true));
        $sensitivePlan = $this->searcher->plan('kelvin', $this->workspace, new TextSearchOptions(fixedString: true, wholeWord: true));

        $this->assertIsArray($kelvinPlan['plan']);
        $this->assertSame('trigram-postings', $kelvinPlan['plan']['candidate_source']);
        $this->assertSame(['elvin'], $kelvinPlan['plan']['seeds']);
        $this->assertIsArray($cafePlan['plan']);
        $this->assertSame(['caf'], $cafePlan['plan']['seeds']);
        $this->assertIsArray($sensitivePlan['plan']);
        $this->assertSame('word-postings', $sensitivePlan['plan']['candidate_source']);
    }

    private function invokeMethod(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);

        return $reflection->invoke($object, ...$arguments);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function invokeArrayMethod(object $object, string $method, mixed ...$arguments): array
    {
        $result = $this->invokeMethod($object, $method, ...$arguments);
        self::assertIsArray($result);

        return $result;
    }

    /**
     * Index file ids are handed out in directory scan order, which the
     * filesystem decides. Asserting on a literal id passes on APFS and fails on
     * ext4, so look the id up instead.
     */
    private function indexedFileId(TextIndexStore $store, string $relativePath): int
    {
        foreach ($store->load($this->workspace . '/.greph-index')->files as $indexedFile) {
            if ($indexedFile['p'] === $relativePath) {
                return $indexedFile['id'];
            }
        }

        self::fail(sprintf('No indexed file found for %s.', $relativePath));
    }
}
