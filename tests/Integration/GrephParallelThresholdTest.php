<?php

declare(strict_types=1);

namespace Greph\Tests\Integration;

use Greph\Ast\AstSearchOptions;
use Greph\Greph;
use Greph\Tests\Support\Workspace;
use Greph\Text\TextSearchOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GrephParallelThresholdTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('greph-parallel-threshold');

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

        $textResults = Greph::searchText(
            'function',
            $textPaths,
            new TextSearchOptions(fixedString: true, jobs: 2),
        );
        $astResults = Greph::searchAst(
            'new $CLASS()',
            $astPaths,
            new AstSearchOptions(jobs: 2),
        );
        $rewriteResults = Greph::rewriteAst(
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
    public function itUsesTheParallelTextPathForLargeRegexFileLists(): void
    {
        $paths = [];

        for ($index = 0; $index < 4001; $index++) {
            $path = $this->workspace . sprintf('/regex/Regex%04d.php', $index);
            Workspace::writeFile($this->workspace, sprintf('regex/Regex%04d.php', $index), "<?php\nfunction demo(): void {}\n");
            $paths[] = $path;
        }

        $results = Greph::searchText(
            'function demo',
            $paths,
            new TextSearchOptions(jobs: 2),
        );

        $this->assertCount(4001, $results);
        $this->assertSame(4001, array_sum(array_map(static fn ($result): int => $result->matchCount(), $results)));
    }
}
