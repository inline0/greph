<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Ast;

use Greph\Ast\AstRootMatcher;
use Greph\Ast\PatternParser;
use PhpParser\Node\Expr\Array_;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstRootMatcherTest extends TestCase
{
    private PatternParser $parser;

    private AstRootMatcher $matcher;

    protected function setUp(): void
    {
        $this->parser = new PatternParser();
        $this->matcher = new AstRootMatcher();
    }

    #[Test]
    public function itRejectsDifferentRootClasses(): void
    {
        $pattern = $this->parser->parse('dispatch($EVENT)');
        $candidate = $this->parser->parse('if ($ready) { run(); }');

        $this->assertFalse($this->matcher->mayMatch($pattern->root, $candidate->root));
    }

    #[Test]
    public function itRejectsLiteralFunctionAndMethodNameMismatches(): void
    {
        $functionPattern = $this->parser->parse('dispatch($EVENT)');
        $functionCandidate = $this->parser->parse('render($event)');
        $methodPattern = $this->parser->parse('$CLIENT->send($MESSAGE)');
        $methodCandidate = $this->parser->parse('$client->queue($message)');

        $this->assertFalse($this->matcher->mayMatch($functionPattern->root, $functionCandidate->root));
        $this->assertFalse($this->matcher->mayMatch($methodPattern->root, $methodCandidate->root));
    }

    #[Test]
    public function itAllowsMetaVariableAndMatchingLiteralRoots(): void
    {
        $metaPattern = $this->parser->parse('$NODE');
        $literalPattern = $this->parser->parse('dispatch($EVENT)');
        $literalCandidate = $this->parser->parse('dispatch($event)');
        $newPattern = $this->parser->parse('new Foo()');
        $newMismatch = $this->parser->parse('new Bar()');
        $newWithArgs = $this->parser->parse('new Foo($value)');

        $this->assertTrue($this->matcher->mayMatch($metaPattern->root, $literalCandidate->root));
        $this->assertTrue($this->matcher->mayMatch($literalPattern->root, $literalCandidate->root));
        $this->assertFalse($this->matcher->mayMatch($newPattern->root, $newMismatch->root));
        $this->assertFalse($this->matcher->mayMatch($newPattern->root, $newWithArgs->root));
    }

    #[Test]
    public function itRejectsArraySyntaxMismatches(): void
    {
        $longPattern = $this->parser->parse('array($$$ITEMS)');
        $longCandidate = $this->parser->parse('array($value)');
        $shortCandidate = $this->parser->parse('[$value]');

        $this->assertTrue($this->matcher->mayMatch($longPattern->root, $longCandidate->root));
        $this->assertFalse($this->matcher->mayMatch($longPattern->root, $shortCandidate->root));
    }

    #[Test]
    public function itCoversPermissivePrivateRootChecks(): void
    {
        $dynamicFunctionPattern = $this->parser->parse('$CALLABLE()');
        $literalFunctionCandidate = $this->parser->parse('dispatch($event)');
        $dynamicMethodPattern = $this->parser->parse('$client->$METHOD($message)');
        $literalMethodCandidate = $this->parser->parse('$client->send($message)');
        $newWithArgsPattern = $this->parser->parse('new Foo($value)');
        $newWithArgsCandidate = $this->parser->parse('new Foo()');

        $this->assertTrue($this->matcher->mayMatch($dynamicFunctionPattern->root, $literalFunctionCandidate->root));
        $this->assertTrue($this->matcher->mayMatch($dynamicMethodPattern->root, $literalMethodCandidate->root));
        $this->assertTrue($this->matcher->mayMatch($newWithArgsPattern->root, $newWithArgsCandidate->root));
    }

    #[Test]
    public function itCoversPermissiveFallbacksForNonLiteralNamesAndUnknownArrayKinds(): void
    {
        $dynamicFunctionPattern = $this->parser->parse('($handlers[0])()');
        $dynamicFunctionCandidate = $this->parser->parse('($other[1])()');
        $dynamicMethodPattern = $this->parser->parse('$client->{$parts[0]}()');
        $dynamicMethodCandidate = $this->parser->parse('$client->{$other[1]}()');
        $patternArray = new class ([]) extends Array_ {
            public mixed $kind = null;
        };
        $candidateArray = new class ([]) extends Array_ {
            public mixed $kind = null;
        };

        $patternArray->setAttribute('kind', null);
        $candidateArray->setAttribute('kind', null);

        $this->assertTrue($this->matcher->mayMatch($dynamicFunctionPattern->root, $dynamicFunctionCandidate->root));
        $this->assertTrue($this->matcher->mayMatch($dynamicMethodPattern->root, $dynamicMethodCandidate->root));
        $this->assertTrue($this->matcher->mayMatch($patternArray, $candidateArray));
        $this->assertNull($this->invokeMethod($this->matcher, 'arrayKind', $patternArray));
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
