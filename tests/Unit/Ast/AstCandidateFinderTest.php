<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Ast;

use Phgrep\Ast\AstCandidateFinder;
use Phgrep\Ast\AstRootMatcher;
use Phgrep\Ast\Parsers\ParserFactory;
use Phgrep\Ast\PatternParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstCandidateFinderTest extends TestCase
{
    #[Test]
    public function itRestrictsCandidatesToThePatternRootClass(): void
    {
        $pattern = (new PatternParser())->parse('new $CLASS()');
        $statements = (new ParserFactory())->forLanguage('php')->parseStatements('<?php $first = new Foo(); if (true) { $second = array(1); }');
        $finder = new AstCandidateFinder();
        $candidates = $finder->find($statements, $pattern);

        $this->assertCount(1, $candidates);
        $this->assertSame(\PhpParser\Node\Expr\New_::class, $candidates[0]::class);
    }

    #[Test]
    public function itFallsBackToAllNodesForSingleMetavariablePatterns(): void
    {
        $pattern = (new PatternParser())->parse('$X');
        $source = (new ParserFactory())->forLanguage('php')->parseStatements('<?php $value = foo($bar);');
        $finder = new AstCandidateFinder();
        $candidates = $finder->find($source, $pattern);

        $this->assertGreaterThan(1, count($candidates));
        $this->assertSame($source[0]::class, $candidates[0]::class);
    }

    #[Test]
    public function itCanStreamCandidatesThroughTheRootMatcher(): void
    {
        $pattern = (new PatternParser())->parse('new Foo()');
        $statements = (new ParserFactory())->forLanguage('php')->parseStatements('<?php $a = new Foo(); $b = new Bar();');
        $finder = new AstCandidateFinder();
        $candidates = iterator_to_array($finder->iterate($statements, $pattern, new AstRootMatcher()), false);

        $this->assertCount(1, $candidates);
        $this->assertSame(\PhpParser\Node\Expr\New_::class, $candidates[0]::class);
    }
}
