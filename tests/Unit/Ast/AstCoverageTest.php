<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Ast;

use Phgrep\Ast\AstRewriter;
use Phgrep\Ast\AstSearchOptions;
use Phgrep\Ast\AstSearcher;
use Phgrep\Ast\MetaVariable;
use Phgrep\Ast\Pattern;
use Phgrep\Ast\PatternMatcher;
use Phgrep\Ast\Parsers\PhpParser;
use Phgrep\Exceptions\ParseException;
use Phgrep\Tests\Support\Workspace;
use Phgrep\Walker\FileList;
use PhpParser\ErrorHandler;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstCoverageTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('ast-coverage');
        Workspace::writeFile($this->workspace, 'nested.php', "<?php\n\$value = array(array(1));\n");
        Workspace::writeFile($this->workspace, 'function.php', "<?php\nfunction demo() {}\n");
        Workspace::writeFile($this->workspace, 'invalid.php', "<?php\nfunction broken( {\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itExercisesPatternMatcherScalarArrayAndCaptureBranches(): void
    {
        $matcher = new PatternMatcher();
        $captures = [];

        $this->assertTrue($this->invokePrivateWithArgs($matcher, 'matchValue', [1, 1, &$captures]));
        $this->assertFalse($this->invokePrivateWithArgs($matcher, 'matchValue', [1, 2, &$captures]));
        $this->assertTrue($this->invokePrivateWithArgs($matcher, 'bindCapture', ['_', new Expr\Variable('ignored'), &$captures]));
        $this->assertFalse($this->invokePrivateWithArgs($matcher, 'matchArray', [[1], [], &$captures, 0, 0]));
        $this->assertFalse($this->invokePrivateWithArgs($matcher, 'matchArray', [[1], [2], &$captures, 0, 0]));

        $variadicCaptures = [
            'ARGS' => [new Arg(new Expr\Variable('original'))],
        ];
        $variadicPattern = [new Arg(new Expr\Variable(MetaVariable::VARIADIC_PREFIX . 'ARGS'), unpack: true)];
        $variadicCandidate = [new Arg(new Expr\Variable('different'))];

        $this->assertFalse($this->invokePrivateWithArgs($matcher, 'matchArray', [$variadicPattern, $variadicCandidate, &$variadicCaptures, 0, 0]));
        $this->assertSame(serialize([serialize(1), serialize('two')]), $this->invokePrivate($matcher, 'fingerprint', [1, 'two']));
    }

    #[Test]
    public function itExercisesAstSearcherUnreadableParseErrorAndRenderFallbackPaths(): void
    {
        $searcher = new AstSearcher();
        $options = new AstSearchOptions(skipParseErrors: false);

        $this->assertSame([], $searcher->searchFiles(new FileList([$this->workspace . '/missing.php']), 'new $CLASS()', $options));

        $this->expectException(ParseException::class);
        $searcher->searchFiles(new FileList([$this->workspace . '/invalid.php']), 'function $NAME() {}', $options);
    }

    #[Test]
    public function itRendersExpressionAndStatementNodesWithoutFileOffsets(): void
    {
        $searcher = new AstSearcher();
        $expr = new Expr\ConstFetch(new Name('true'), ['startLine' => 1, 'endLine' => 1, 'startFilePos' => 5, 'endFilePos' => 1]);
        $statement = new Node\Stmt\Echo_([new Expr\Variable('value')]);
        $matches = [];

        $this->invokePrivateWithArgs(
            $searcher,
            'visitNode',
            [
                $expr,
                new Pattern($expr, true),
                '',
                $this->workspace . '/expr.php',
                &$matches,
            ],
        );

        /** @var list<\Phgrep\Ast\AstMatch> $matches */
        $this->assertCount(1, $matches);
        $firstMatch = $matches[0];

        $this->assertSame('true', $firstMatch->code);
        $this->assertSame('true', $this->invokePrivate($searcher, 'renderNode', $expr));
        $this->assertStringContainsString('echo', $this->invokePrivate($searcher, 'renderNode', $statement));
    }

    #[Test]
    public function itExercisesAstRewriterEdgeBranches(): void
    {
        $rewriter = new AstRewriter();

        $this->assertSame(
            [],
            $rewriter->rewriteFiles(
                new FileList([$this->workspace . '/invalid.php']),
                'array($$$ITEMS)',
                '[$$$ITEMS]',
                new AstSearchOptions(dryRun: true),
            ),
        );

        $this->assertSame(
            [],
            $rewriter->rewriteFiles(
                new FileList([$this->workspace . '/missing.php']),
                'array($$$ITEMS)',
                '[$$$ITEMS]',
                new AstSearchOptions(dryRun: true, skipParseErrors: false),
            ),
        );

        $this->expectException(ParseException::class);
        $rewriter->rewriteFiles(
            new FileList([$this->workspace . '/invalid.php']),
            'array($$$ITEMS)',
            '[$$$ITEMS]',
            new AstSearchOptions(dryRun: true, skipParseErrors: false),
        );
    }

    #[Test]
    public function itSkipsOverlappingRewritesAndSupportsStatementTemplates(): void
    {
        $rewriter = new AstRewriter();
        $overlappingResults = $rewriter->rewriteFiles(
            new FileList([$this->workspace . '/nested.php']),
            'array($$$ITEMS)',
            '[$$$ITEMS]',
            new AstSearchOptions(dryRun: true),
        );
        $statementResults = $rewriter->rewriteFiles(
            new FileList([$this->workspace . '/function.php']),
            'function $NAME() {}',
            'class $NAME {}',
            new AstSearchOptions(dryRun: true),
        );

        $this->assertSame(1, $overlappingResults[0]->replacementCount);
        $this->assertStringContainsString('array([1])', $overlappingResults[0]->rewrittenContents);
        $this->assertStringContainsString('class demo', $statementResults[0]->rewrittenContents);
    }

    #[Test]
    public function itRejectsNonExpressionRewriteMaterializationAndHandlesScalarValues(): void
    {
        $rewriter = new AstRewriter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expression rewrite produced a non-expression node.');
        $rewriter->rewriteFiles(
            new FileList([$this->workspace . '/function.php']),
            'function $NAME() {}',
            '$NAME',
            new AstSearchOptions(dryRun: true),
        );
    }

    #[Test]
    public function itMaterializesScalarArrayValuesAndHandlesParsersReturningNull(): void
    {
        $rewriter = new AstRewriter();
        $materialized = $this->invokePrivate(
            $rewriter,
            'materializeValue',
            ['literal', new Expr\Variable('value')],
            [],
        );

        $this->assertSame('literal', $materialized[0]);
        $this->assertInstanceOf(Expr\Variable::class, $materialized[1]);

        $parser = new PhpParser();
        $reflection = new \ReflectionProperty($parser, 'parser');
        $reflection->setAccessible(true);
        $reflection->setValue($parser, new class implements \PhpParser\Parser {
            public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
            {
                return null;
            }

            public function getTokens(): array
            {
                return [];
            }
        });

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Parser returned no statements.');
        $parser->parseStatements('<?php echo 1;');
    }

    private function invokePrivate(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invokePrivateWithArgs(object $object, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
