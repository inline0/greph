<?php

declare(strict_types=1);

namespace Phgrep\Tests\Integration;

use Phgrep\Ast\AstSearchOptions;
use Phgrep\Phgrep;
use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstRewriteTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('ast-rewrite');
        Workspace::writeFile($this->workspace, 'src/Legacy.php', <<<'PHP'
<?php

$items = array(1, 2, 3);
$name = isset($value) ? $value : 'fallback';
PHP);
        Workspace::writeFile($this->workspace, 'src/Multiple.php', <<<'PHP'
<?php

$first = array(1, 2);
$second = array(3, 4);
PHP);
        Workspace::writeFile($this->workspace, 'src/Unchanged.php', "<?php\n\n\$value = 42;\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itRewritesMatchedCodeUsingCapturedMetaVariables(): void
    {
        $results = Phgrep::rewriteAst(
            'array($$$ITEMS)',
            '[$$$ITEMS]',
            $this->workspace . '/src/Legacy.php',
            new AstSearchOptions(dryRun: true),
        );

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->changed());
        $this->assertStringContainsString('$items = [1, 2, 3];', $results[0]->rewrittenContents);
    }

    #[Test]
    public function itRewritesTernaryIssetPatterns(): void
    {
        $results = Phgrep::rewriteAst(
            'isset($x) ? $x : $y',
            '$x ?? $y',
            $this->workspace . '/src/Legacy.php',
            new AstSearchOptions(dryRun: true),
        );

        $this->assertStringContainsString("\$name = \$value ?? 'fallback';", $results[0]->rewrittenContents);
    }

    #[Test]
    public function itRewritesEveryMatchInAFile(): void
    {
        $results = Phgrep::rewriteAst(
            'array($$$ITEMS)',
            '[$$$ITEMS]',
            $this->workspace . '/src/Multiple.php',
            new AstSearchOptions(dryRun: true),
        );

        $this->assertCount(1, $results);
        $this->assertSame(2, $results[0]->replacementCount);
        $this->assertStringContainsString("\$first = [1, 2];", $results[0]->rewrittenContents);
        $this->assertStringContainsString("\$second = [3, 4];", $results[0]->rewrittenContents);
    }

    #[Test]
    public function itReturnsUnchangedResultsWhenNothingMatches(): void
    {
        $results = Phgrep::rewriteAst(
            'array($$$ITEMS)',
            '[$$$ITEMS]',
            $this->workspace . '/src/Unchanged.php',
            new AstSearchOptions(dryRun: true),
        );

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->changed());
        $this->assertSame(0, $results[0]->replacementCount);
        $this->assertSame($results[0]->originalContents, $results[0]->rewrittenContents);
    }

    #[Test]
    public function itSupportsParallelRewriteExecution(): void
    {
        $results = Phgrep::rewriteAst(
            'array($$$ITEMS)',
            '[$$$ITEMS]',
            [$this->workspace . '/src/Legacy.php', $this->workspace . '/src/Multiple.php'],
            new AstSearchOptions(dryRun: true, jobs: 2),
        );

        $this->assertCount(2, $results);
        $this->assertSame(
            [$this->workspace . '/src/Legacy.php', $this->workspace . '/src/Multiple.php'],
            array_map(static fn ($result): string => $result->file, $results),
        );
        $this->assertSame([1, 2], array_map(static fn ($result): int => $result->replacementCount, $results));
    }
}
