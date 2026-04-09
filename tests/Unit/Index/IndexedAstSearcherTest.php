<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Index;

use Phgrep\Ast\AstMatch;
use Phgrep\Ast\AstSearchOptions;
use Phgrep\Ast\PatternParser;
use Phgrep\Index\IndexedAstSearcher;
use Phgrep\Phgrep;
use Phgrep\Tests\Support\Workspace;
use Phgrep\Walker\FileTypeFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexedAstSearcherTest extends TestCase
{
    private string $workspace;

    private string $externalWorkspace;

    private IndexedAstSearcher $searcher;

    private PatternParser $parser;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('indexed-ast-searcher');
        Workspace::writeFile($this->workspace, '.gitignore', "ignored.php\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\n\$service = new Service();\nrender_widget();\n");
        Workspace::writeFile($this->workspace, '.hidden/Hidden.php', "<?php\n\$hidden = new HiddenThing();\n");
        Workspace::writeFile($this->workspace, 'ignored.php', "<?php\n\$ignored = new IgnoredThing();\n");

        $this->externalWorkspace = Workspace::createDirectory('indexed-ast-searcher-external');
        Workspace::writeFile($this->externalWorkspace, 'external.php', "<?php\n\$external = new ExternalThing();\n");

        Phgrep::buildAstIndex($this->workspace);

        $this->searcher = new IndexedAstSearcher();
        $this->parser = new PatternParser();
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
        Workspace::remove($this->externalWorkspace);
    }

    #[Test]
    public function itCoversPublicIndexedAstSearchEdgeCases(): void
    {
        Workspace::writeFile($this->workspace, 'src/Newer.php', "<?php\n\$fresh = new FreshThing();\n");

        $fallbackMatches = $this->searcher->search(
            'new $CLASS()',
            $this->workspace . '/src/Newer.php',
            new AstSearchOptions(),
        );
        $externalMatches = $this->searcher->search(
            'new $CLASS()',
            $this->externalWorkspace . '/external.php',
            new AstSearchOptions(),
            $this->workspace . '/.phgrep-ast-index',
        );
        $broadMatches = $this->searcher->search(
            'if ($ready) { render_widget(); }',
            $this->workspace,
            new AstSearchOptions(),
        );

        $this->assertCount(1, $fallbackMatches);
        $this->assertSame($this->workspace . '/src/Newer.php', $fallbackMatches[0]->file);
        $this->assertCount(1, $externalMatches);
        $this->assertSame($this->externalWorkspace . '/external.php', $externalMatches[0]->file);
        $this->assertSame([], $broadMatches);
    }

    #[Test]
    public function itRejectsMissingPathsAndMissingIndexes(): void
    {
        try {
            $this->searcher->search('new $CLASS()', $this->workspace . '/missing.php', new AstSearchOptions());
            self::fail('Expected missing path lookup to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Path does not exist', $exception->getMessage());
        }

        try {
            $this->searcher->search('new $CLASS()', $this->externalWorkspace, new AstSearchOptions());
            self::fail('Expected missing index lookup to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No AST index found', $exception->getMessage());
        }
    }

    #[Test]
    public function itCoversIndexedAstHelperBranches(): void
    {
        $pattern = $this->parser->parse('new $CLASS()');
        $unsupportedPattern = $this->parser->parse('if ($ready) { render_widget(); }');
        $index = (new \Phgrep\Index\AstIndexStore())->load($this->workspace . '/.phgrep-ast-index');

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
        $cachedMatches = $this->invokeMethod(
            $this->searcher,
            'filterCachedMatches',
            [
                $this->createMatch($this->workspace . '/src/App.php'),
                $this->createMatch($this->externalWorkspace . '/external.php'),
            ],
            $selection,
        );
        $candidateIds = $this->invokeMethod($this->searcher, 'candidateIds', $index, $pattern);
        $unsupportedCandidateIds = $this->invokeMethod($this->searcher, 'candidateIds', $index, $unsupportedPattern);
        $cacheSupported = $this->invokeMethod(
            $this->searcher,
            'supportsQueryCache',
            new AstSearchOptions(),
        );
        $cacheRejected = $this->invokeMethod(
            $this->searcher,
            'supportsQueryCache',
            new AstSearchOptions(
                language: 'js',
                respectIgnore: false,
                includeHidden: true,
                followSymlinks: true,
                skipBinaryFiles: false,
                includeGitDirectory: true,
                fileTypeFilter: new FileTypeFilter(['php']),
                maxFileSizeBytes: 1,
                globPatterns: ['*.php'],
                skipParseErrors: false,
                dryRun: true,
                interactive: true,
            ),
        );
        $canUseCache = $this->invokeMethod(
            $this->searcher,
            'canUseQueryCache',
            [$this->workspace],
            $this->workspace,
            new AstSearchOptions(),
            [],
            [],
        );
        $cannotUseCache = $this->invokeMethod(
            $this->searcher,
            'canUseQueryCache',
            [$this->externalWorkspace],
            $this->workspace,
            new AstSearchOptions(),
            [],
            [],
        );
        $canPopulateCache = $this->invokeMethod(
            $this->searcher,
            'canPopulateQueryCache',
            [$this->workspace],
            $this->workspace,
            new AstSearchOptions(),
            [],
            [],
        );
        $cannotPopulateCache = $this->invokeMethod(
            $this->searcher,
            'canPopulateQueryCache',
            [$this->workspace . '/src/App.php'],
            $this->workspace,
            new AstSearchOptions(),
            [$this->workspace . '/src/App.php' => true],
            [],
        );
        $matchesSelection = $this->invokeMethod(
            $this->searcher,
            'matchesSelection',
            $this->workspace . '/src/App.php',
            $selection,
        );
        $matchesGlob = $this->invokeMethod(
            $this->searcher,
            'matchesGlobPatterns',
            $this->workspace,
            $this->workspace,
            ['indexed-ast-searcher*'],
        );
        $missesGlob = $this->invokeMethod(
            $this->searcher,
            'matchesGlobPatterns',
            $this->workspace . '/src/App.php',
            $this->workspace,
            ['*.txt'],
        );
        $filterMiss = $this->invokeMethod(
            $this->searcher,
            'matchesQueryFilters',
            ['id' => 1, 'p' => 'src/App.php', 's' => 100, 'm' => 1, 'h' => false, 'g' => false, 'o' => 0],
            $this->workspace . '/src/App.php',
            $this->workspace,
            new AstSearchOptions(maxFileSizeBytes: 10),
        );
        $resolvedIndexPath = $this->invokeMethod(
            $this->searcher,
            'resolveIndexPath',
            [$this->workspace],
            $this->workspace . '/.phgrep-ast-index',
        );
        $missingIndexPath = $this->invokeMethod(
            $this->searcher,
            'resolveIndexPath',
            [$this->externalWorkspace],
            null,
        );

        $this->assertCount(1, $cachedMatches);
        $this->assertArrayHasKey(1, $candidateIds);
        $this->assertNull($unsupportedCandidateIds);
        $this->assertTrue($cacheSupported);
        $this->assertFalse($cacheRejected);
        $this->assertTrue($canUseCache);
        $this->assertFalse($cannotUseCache);
        $this->assertTrue($canPopulateCache);
        $this->assertFalse($cannotPopulateCache);
        $this->assertTrue($matchesSelection);
        $this->assertTrue($matchesGlob);
        $this->assertFalse($missesGlob);
        $this->assertFalse($filterMiss);
        $this->assertSame($this->workspace . '/.phgrep-ast-index', $resolvedIndexPath);
        $this->assertNull($missingIndexPath);
    }

    private function createMatch(string $file): AstMatch
    {
        $pattern = $this->parser->parse('new $CLASS()');

        return new AstMatch($file, $pattern->root, [], 1, 1, 0, 10, 'new Foo()');
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
