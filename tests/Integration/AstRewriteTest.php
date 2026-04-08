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
            $this->workspace,
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
            $this->workspace,
            new AstSearchOptions(dryRun: true),
        );

        $this->assertStringContainsString("\$name = \$value ?? 'fallback';", $results[0]->rewrittenContents);
    }
}
