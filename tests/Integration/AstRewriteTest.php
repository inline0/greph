<?php

declare(strict_types=1);

namespace Greph\Tests\Integration;

use Greph\Ast\AstSearchOptions;
use Greph\Greph;
use Greph\Tests\Support\Workspace;
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
        $results = Greph::rewriteAst(
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
        $results = Greph::rewriteAst(
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
        $results = Greph::rewriteAst(
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
        $results = Greph::rewriteAst(
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
        $results = Greph::rewriteAst(
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

    #[Test]
    public function itPreservesCommentsLayoutAndLiteralSpellingInsideCaptures(): void
    {
        $original = <<<'PHP'
<?php
$d = array(
    "a" => 1,  // trailing on last item
);
$e = array(1, /* inline */ 2);
$f = array(
    // only comment
);
$n = array(0x1F, 1_000, "x\x41");
PHP;
        $expected = <<<'PHP'
<?php
$d = [
    "a" => 1,  // trailing on last item
];
$e = [1, /* inline */ 2];
$f = [
    // only comment
];
$n = [0x1F, 1_000, "x\x41"];
PHP;
        $file = Workspace::writeFile($this->workspace, 'src/Preserved.php', $original);
        $result = Greph::rewriteAst('array($$$ITEMS)', '[$$$ITEMS]', $file, new AstSearchOptions(dryRun: true));
        self::assertSame($expected, $result[0]->rewrittenContents);
        self::assertSame(4, $result[0]->replacementCount);
    }

    #[Test]
    public function itChoosesTheOutermostNestedMatch(): void
    {
        $file = Workspace::writeFile($this->workspace, 'src/Nested.php', '<?php $b = array(1, array(2));');
        $result = Greph::rewriteAst('array($$$ITEMS)', '[$$$ITEMS]', $file, new AstSearchOptions(dryRun: true));
        self::assertSame('<?php $b = [1, array(2)];', $result[0]->rewrittenContents);
        self::assertSame(1, $result[0]->replacementCount);
    }

    #[Test]
    public function itRejectsUnboundTemplateVariablesBeforeReturningAnyChanges(): void
    {
        $file = $this->workspace . '/src/Legacy.php';
        $original = file_get_contents($file);
        try {
            Greph::rewriteAst('array($$$ITEMS)', '[$$$ITEMS, $EXTRA]', $file, new AstSearchOptions());
            self::fail('An unbound template variable must be rejected.');
        } catch (\Greph\Exceptions\ParseException $error) {
            self::assertStringContainsString('EXTRA', $error->getMessage());
        }
        self::assertSame($original, file_get_contents($file));
    }

    #[Test]
    public function itPreservesExpressionPrecedenceWhenSplicingCaptures(): void
    {
        $file = Workspace::writeFile($this->workspace, 'src/Precedence.php', '<?php $n = (1 + 2) * 3;');
        $result = Greph::rewriteAst('$A * $B', '$B - $A', $file, new AstSearchOptions(dryRun: true));
        self::assertSame('<?php $n = 3 - (1 + 2);', $result[0]->rewrittenContents);
    }

    #[Test]
    public function itPreservesPrecedenceOfTheReplacementWithinItsSurroundingSource(): void
    {
        $file = Workspace::writeFile($this->workspace, 'src/Context.php', '<?php $n = 10 * foo(2);');
        $result = Greph::rewriteAst('foo($X)', '$X + 1', $file, new AstSearchOptions(dryRun: true));
        self::assertSame('<?php $n = 10 * (2 + 1);', $result[0]->rewrittenContents);
    }

    #[Test]
    public function itHandlesEmptyAndTrailingVariadicCaptures(): void
    {
        $file = Workspace::writeFile($this->workspace, 'src/Variadic.php', '<?php foo(1); foo(1, 2,);');
        $results = Greph::rewriteAst('foo($X, $$$TAIL)', 'bar($$$TAIL, $X)', $file, new AstSearchOptions(dryRun: true));
        self::assertStringContainsString('bar( 1);', $results[0]->rewrittenContents);
        self::assertStringContainsString('bar( 2, 1);', $results[0]->rewrittenContents);
    }

    #[Test]
    public function itPreservesHeredocIndentationAndInterpolation(): void
    {
        $original = <<<'PHP'
<?php
$values = array(
    <<<TEXT
    Hello $name
    TEXT,
);
PHP;
        $expected = str_replace(['array(', "\n);"], ['[', "\n];"], $original);
        $file = Workspace::writeFile($this->workspace, 'src/Heredoc.php', $original);
        $result = Greph::rewriteAst('array($$$ITEMS)', '[$$$ITEMS]', $file, new AstSearchOptions(dryRun: true));
        self::assertSame($expected, $result[0]->rewrittenContents);
    }

    #[Test]
    public function itRetainsCommentsOutsideCapturedNodesAndSupportsSourcesWithoutAnOpenTag(): void
    {
        $file = Workspace::writeFile($this->workspace, 'src/NoTag.php', 'foo(/* keep */ 0x1F);');
        $result = Greph::rewriteAst('foo($X)', 'bar($X)', $file, new AstSearchOptions(dryRun: true));
        self::assertSame('/* keep */ bar(0x1F);', $result[0]->rewrittenContents);
    }

    #[Test]
    public function itPreservesAttributesInPartialParameterCaptures(): void
    {
        $original = '<?php function demo($first, #[Flag(1, 2)] $second) {}';
        $file = Workspace::writeFile($this->workspace, 'src/Parameters.php', $original);
        $result = Greph::rewriteAst(
            'function $NAME($FIRST, $$$PARAMS) {}',
            'function $NAME($FIRST,$$$PARAMS) { return 1; }',
            $file,
            new AstSearchOptions(dryRun: true),
        );
        self::assertSame(
            '<?php function demo($first, #[Flag(1, 2)] $second) { return 1; }',
            $result[0]->rewrittenContents,
        );
    }

    #[Test]
    public function itCapturesEmptyConstructorArgumentsWithoutSourceParentheses(): void
    {
        $file = Workspace::writeFile($this->workspace, 'src/Constructor.php', '<?php $value = new Foo;');
        $result = Greph::rewriteAst('new $CLASS($$$ARGS)', 'wrap(new $CLASS($$$ARGS))', $file);
        self::assertSame('<?php $value = wrap(new Foo());', $result[0]->rewrittenContents);

        $file = Workspace::writeFile($this->workspace, 'src/Anonymous.php', '<?php $value = new class {};');
        $result = Greph::rewriteAst('new class($$$ARGS) {}', 'new class($$$ARGS) {}', $file);
        self::assertSame('<?php $value = new class() {};', $result[0]->rewrittenContents);
    }

    #[Test]
    public function itPlacesArgumentsBeforeCapturedAnonymousClassBodies(): void
    {
        $original = '<?php $value = new class(1, /* keep */ 2) { public function value() { return 0x1F; } };';
        $file = Workspace::writeFile($this->workspace, 'src/AnonymousCapture.php', $original);
        $result = Greph::rewriteAst('new $CLASS($$$ARGS)', 'wrap(new $CLASS($$$ARGS))', $file);
        self::assertSame(
            '<?php $value = wrap(new class(1, /* keep */ 2) { public function value() { return 0x1F; } });',
            $result[0]->rewrittenContents,
        );
        $empty = '<?php $value = new class { public function value() { return 0x1F; } };';
        file_put_contents($file, $empty);
        $result = Greph::rewriteAst('new $CLASS($$$ARGS)', 'wrap(new $CLASS($$$ARGS))', $file);
        self::assertSame(
            '<?php $value = wrap(new class() { public function value() { return 0x1F; } });',
            $result[0]->rewrittenContents,
        );
    }

    #[Test]
    public function itIsIdempotentAcrossRepeatedRewriteRuns(): void
    {
        $firstPass = Greph::rewriteAst(
            'array($$$ITEMS)',
            '[$$$ITEMS]',
            $this->workspace . '/src/Legacy.php',
            new AstSearchOptions(dryRun: true),
        );

        file_put_contents($this->workspace . '/src/Legacy.php', $firstPass[0]->rewrittenContents);

        $secondPass = Greph::rewriteAst(
            'array($$$ITEMS)',
            '[$$$ITEMS]',
            $this->workspace . '/src/Legacy.php',
            new AstSearchOptions(dryRun: true),
        );

        $this->assertCount(1, $secondPass);
        $this->assertFalse($secondPass[0]->changed());
        $this->assertSame($secondPass[0]->originalContents, $secondPass[0]->rewrittenContents);
    }
}
