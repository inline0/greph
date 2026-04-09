<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Ast;

use Phgrep\Ast\AstPatternPrefilter;
use Phgrep\Ast\PatternParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstPatternPrefilterTest extends TestCase
{
    #[Test]
    public function itExtractsStableTokensFromStructuralPatterns(): void
    {
        $parser = new PatternParser();
        $prefilter = new AstPatternPrefilter();

        $this->assertSame(['new'], $prefilter->extract($parser->parse('new $CLASS()')->root));
        $this->assertSame(['array'], $prefilter->extract($parser->parse('array($$$ITEMS)')->root));
        $this->assertSame(['first', 'where'], $prefilter->extract($parser->parse('$QUERY->where($FIELD)->first()')->root));
        $this->assertSame(['run', 'if'], $prefilter->extract($parser->parse('if ($COND) { run(); }')->root));
    }

    #[Test]
    public function itAppliesTokensAsCaseInsensitiveRawContentPrefilters(): void
    {
        $prefilter = new AstPatternPrefilter();

        $this->assertTrue($prefilter->mayMatch(['new'], "<?php\nNEW Foo();\n"));
        $this->assertFalse($prefilter->mayMatch(['new', 'where'], "<?php\nwhere();\n"));
    }
}
