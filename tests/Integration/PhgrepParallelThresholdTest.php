<?php

declare(strict_types=1);

namespace Phgrep\Tests\Integration;

use Phgrep\Ast\AstSearchOptions;
use Phgrep\Phgrep;
use Phgrep\Tests\Support\Workspace;
use Phgrep\Text\TextSearchOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhgrepParallelThresholdTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('phgrep-parallel-threshold');

        for ($index = 0; $index < 1501; $index++) {
            Workspace::writeFile($this->workspace, sprintf('text/Search%04d.php', $index), "<?php\nfunction demo(): void {}\n");
            Workspace::writeFile($this->workspace, sprintf('ast/Ast%04d.php', $index), "<?php\n\$node = new Foo();\n\$items = array(1);\n");
        }
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itUsesParallelExecutionOnceTheWorkerThresholdIsExceeded(): void
    {
        $textPaths = [];
        $astPaths = [];

        for ($index = 0; $index < 1501; $index++) {
            $textPaths[] = $this->workspace . sprintf('/text/Search%04d.php', $index);
            $astPaths[] = $this->workspace . sprintf('/ast/Ast%04d.php', $index);
        }

        $textResults = Phgrep::searchText(
            'function',
            $textPaths,
            new TextSearchOptions(fixedString: true, jobs: 2),
        );
        $astResults = Phgrep::searchAst(
            'new $CLASS()',
            $astPaths,
            new AstSearchOptions(jobs: 2),
        );
        $rewriteResults = Phgrep::rewriteAst(
            'array($$$ITEMS)',
            '[$$$ITEMS]',
            $astPaths,
            new AstSearchOptions(dryRun: true, jobs: 2),
        );

        $this->assertCount(1501, $textResults);
        $this->assertSame(1501, array_sum(array_map(static fn ($result): int => $result->matchCount(), $textResults)));
        $this->assertCount(1501, $astResults);
        $this->assertCount(1501, $rewriteResults);
        $this->assertTrue($rewriteResults[0]->changed());
    }

    #[Test]
    public function itSupportsSparseParallelTextPayloadsForMatchedFilesOnly(): void
    {
        $paths = [];

        for ($index = 0; $index < 1501; $index++) {
            $relativePath = sprintf('mixed/File%04d.txt', $index);
            $contents = $index % 2 === 0
                ? "needle in file {$index}\n"
                : "plain text {$index}\n";
            $paths[] = Workspace::writeFile($this->workspace, $relativePath, $contents);
        }

        $filesWithMatches = Phgrep::searchText(
            'needle',
            $paths,
            new TextSearchOptions(fixedString: true, filesWithMatches: true, jobs: 2),
        );
        $normalResults = Phgrep::searchText(
            'needle',
            $paths,
            new TextSearchOptions(fixedString: true, jobs: 2),
        );

        $matchedFiles = array_map(static fn ($result): string => basename($result->file), $filesWithMatches);
        $normalMatchedFiles = array_map(static fn ($result): string => basename($result->file), $normalResults);

        $this->assertCount(751, $filesWithMatches);
        $this->assertCount(751, $normalResults);
        $this->assertContains('File0000.txt', $matchedFiles);
        $this->assertContains('File1500.txt', $matchedFiles);
        $this->assertSame($matchedFiles, $normalMatchedFiles);
    }
}
