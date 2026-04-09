<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Ast;

use Phgrep\Ast\AstRootMatcher;
use Phgrep\Ast\PatternParser;
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

        $this->assertTrue($this->matcher->mayMatch($metaPattern->root, $literalCandidate->root));
        $this->assertTrue($this->matcher->mayMatch($literalPattern->root, $literalCandidate->root));
        $this->assertFalse($this->matcher->mayMatch($newPattern->root, $newMismatch->root));
    }
}
