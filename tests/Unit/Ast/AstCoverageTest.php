<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Ast;

use Greph\Ast\AstRewriter;
use Greph\Ast\AstSearchOptions;
use Greph\Ast\AstSearcher;
use Greph\Ast\MetaVariable;
use Greph\Ast\PatternMatcher;
use Greph\Ast\Parsers\PhpParser;
use Greph\Exceptions\ParseException;
use Greph\Tests\Support\Workspace;
use Greph\Walker\FileList;
use PhpParser\ErrorHandler;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
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
        $this->assertFalse($this->invokePrivateWithArgs($matcher, 'matchValue', [new Expr\ConstFetch(new Name('true')), new Expr\Variable('left'), &$captures]));
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
        $this->assertIsString($this->invokePrivate($matcher, 'fingerprint', new Name(['App', 'Service'])));
        $this->assertCount(2, $this->invokePrivate($matcher, 'serializeSubNodeArray', [new Expr\Variable('value'), 'literal']));
        $this->assertIsString($this->invokePrivate($matcher, 'fingerprint', new Expr\Array_([
            new ArrayItem(new Expr\Variable('value')),
        ])));
        $this->assertIsString($this->invokePrivate($matcher, 'fingerprintNode', new Name(['App', 'Service'])));

        $variable = new Expr\Variable('cached');
        $fingerprint = $this->invokePrivate($matcher, 'fingerprint', $variable);

        $this->assertSame($fingerprint, $this->invokePrivate($matcher, 'fingerprint', $variable));
    }

    #[Test]
    public function itExercisesAstSearcherUnreadableParseErrorAndRenderFallbackPaths(): void
    {
        $searcher = new AstSearcher();
        $options = new AstSearchOptions(skipParseErrors: false);

        $this->assertSame([], $searcher->searchFiles(new FileList([$this->workspace . '/missing.php']), 'new $CLASS()', $options));
        $this->assertSame(
            2,
            $searcher->countFiles(new FileList([$this->workspace . '/nested.php']), 'array($$$ITEMS)', new AstSearchOptions()),
        );
        $this->assertSame(
            1,
            $searcher->countParsedFiles(new FileList([$this->workspace . '/nested.php']), 'array($$$ITEMS)', new AstSearchOptions()),
        );
        $this->assertSame(
            0,
            $searcher->countParsedFiles(new FileList([$this->workspace . '/missing.php']), 'array($$$ITEMS)', new AstSearchOptions()),
        );
        $this->assertSame(
            0,
            $searcher->countParsedFiles(new FileList([$this->workspace . '/function.php']), 'array($$$ITEMS)', new AstSearchOptions()),
        );
        $this->assertSame(
            0,
            $searcher->countParsedFiles(new FileList([$this->workspace . '/invalid.php']), 'function $NAME() {}', new AstSearchOptions(skipParseErrors: true)),
        );

        try {
            $searcher->countParsedFiles(new FileList([$this->workspace . '/invalid.php']), 'function $NAME() {}', new AstSearchOptions(skipParseErrors: false));
            self::fail('Expected countParsedFiles parse exception.');
        } catch (ParseException $exception) {
            $this->assertStringContainsString('Syntax error', $exception->getMessage());
        }

        $this->expectException(ParseException::class);
        $searcher->searchFiles(new FileList([$this->workspace . '/invalid.php']), 'function $NAME() {}', $options);
    }

    #[Test]
    public function itSearchesParsedStatementsWithLazySourceLoading(): void
    {
        $searcher = new AstSearcher();
        $pattern = $searcher->compilePattern('array($$$ITEMS)', new AstSearchOptions());
        $statements = (new PhpParser())->parseStatements("<?php\n\$value = array(1, 2, 3);\n");
        $sourceLoads = 0;
        $matches = $searcher->searchParsedStatements(
            $this->workspace . '/parsed.php',
            $statements,
            $pattern,
            static function () use (&$sourceLoads): string {
                $sourceLoads++;

                return "<?php\n\$value = array(1, 2, 3);\n";
            },
        );
        $noMatches = $searcher->searchParsedStatements(
            $this->workspace . '/parsed.php',
            $statements,
            $searcher->compilePattern('dispatch($EVENT)', new AstSearchOptions()),
            static function () use (&$sourceLoads): string {
                $sourceLoads++;

                return "<?php\ndispatch(\$event);\n";
            },
        );
        $stringSourceMatches = $searcher->searchParsedStatements(
            $this->workspace . '/parsed.php',
            $statements,
            $pattern,
            "<?php\n\$value = array(1, 2, 3);\n",
        );
        $stringLoadedMatches = $searcher->searchParsedStatements(
            $this->workspace . '/parsed.php',
            $statements,
            $searcher->compilePattern('array($LEFT, $LEFT)', new AstSearchOptions()),
            "<?php\n\$value = array(1, 2, 3);\n",
        );

        $this->assertCount(1, $matches);
        $this->assertSame(1, $sourceLoads);
        $this->assertSame([], $noMatches);
        $this->assertCount(1, $stringSourceMatches);
        $this->assertSame([], $stringLoadedMatches);
    }

    #[Test]
    public function itRendersExpressionAndStatementNodesWithoutFileOffsets(): void
    {
        $searcher = new AstSearcher();
        $expr = new Expr\ConstFetch(new Name('true'), ['startLine' => 1, 'endLine' => 1, 'startFilePos' => 5, 'endFilePos' => 1]);
        $statement = new Node\Stmt\Echo_([new Expr\Variable('value')]);
        /** @var \Greph\Ast\AstMatch $match */
        $match = $this->invokePrivate($searcher, 'createMatch', $expr, [], '', $this->workspace . '/expr.php');

        $this->assertSame('true', $match->code);
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
        $mismatchResults = $rewriter->rewriteFiles(
            new FileList([$this->workspace . '/nested.php']),
            'array($LEFT, $LEFT)',
            '[$LEFT, $LEFT]',
            new AstSearchOptions(dryRun: true),
        );

        $this->assertSame(1, $overlappingResults[0]->replacementCount);
        $this->assertStringContainsString('array([1])', $overlappingResults[0]->rewrittenContents);
        $this->assertStringContainsString('class demo', $statementResults[0]->rewrittenContents);
        $this->assertSame(0, $mismatchResults[0]->replacementCount);
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
        $this->assertCount(2, $parser->parseStatements("  \n<?php echo 1;"));
        $this->assertNotSame([], $parser->parseStatements('echo 1;'));
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
