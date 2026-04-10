<?php

declare(strict_types=1);

namespace Greph\Tests\Integration;

use Greph\Ast\AstSearchOptions;
use Greph\Greph;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstSearchTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('ast-search');
        Workspace::writeFile($this->workspace, 'src/Calls.php', <<<'PHP'
<?php

$foo = new Foo();
$bar = new Bar();
foo($foo, $bar);
foo();
$same = $same;
PHP);
        Workspace::writeFile($this->workspace, 'src/Broken.php', "<?php\nfunction broken(\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itFindsConstructorCallsWithMetaVariables(): void
    {
        $matches = Greph::searchAst('new $Class()', $this->workspace, new AstSearchOptions());

        $this->assertCount(2, $matches);
        $this->assertSame('new Foo()', trim($matches[0]->code));
        $this->assertSame('new Bar()', trim($matches[1]->code));
    }

    #[Test]
    public function itSupportsVariadicAndRepeatedMetaVariables(): void
    {
        $variadicMatches = Greph::searchAst('foo($$$ARGS)', $this->workspace, new AstSearchOptions(jobs: 2));
        $repeatedMatches = Greph::searchAst('$x = $x', $this->workspace, new AstSearchOptions());

        $this->assertCount(2, $variadicMatches);
        $this->assertCount(1, $repeatedMatches);
        $this->assertSame('$same = $same', trim($repeatedMatches[0]->code));
    }
}
