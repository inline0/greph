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

    #[Test]
    public function itCanDetectZeroArgumentNewExpressionsBeforeParsing(): void
    {
        $parser = new PatternParser();
        $prefilter = new AstPatternPrefilter();
        $pattern = $parser->parse('new $CLASS()')->root;

        $this->assertTrue($prefilter->mayMatchPattern($pattern, "<?php\nnew Foo();\n"));
        $this->assertTrue($prefilter->mayMatchPattern($pattern, "<?php\nnew Foo;\n"));
        $this->assertTrue($prefilter->mayMatchPattern($pattern, "<?php\nnew Foo /* comment */ ( );\n"));
        $this->assertTrue($prefilter->mayMatchPattern($pattern, "<?php\nnew Bar(1);\nnew Baz();\n"));
        $this->assertFalse($prefilter->mayMatchPattern($pattern, "<?php\nnew Foo(1);\n"));
        $this->assertFalse($prefilter->mayMatchPattern($pattern, '<?php' . "\n" . 'new Foo($arg);' . "\n" . 'new Bar($other);' . "\n"));
    }

    #[Test]
    public function itCanDetectLongArraySyntaxBeforeParsing(): void
    {
        $parser = new PatternParser();
        $prefilter = new AstPatternPrefilter();
        $pattern = $parser->parse('array($$$ITEMS)')->root;

        $this->assertTrue($prefilter->mayMatchPattern($pattern, "<?php\n\$values = array(1, 2);\n"));
        $this->assertTrue($prefilter->mayMatchPattern($pattern, "<?php\n\$values = array /* comment */ (1, 2);\n"));
        $this->assertFalse($prefilter->mayMatchPattern($pattern, "<?php\n\$values = [1, 2];\n"));
        $this->assertFalse($prefilter->mayMatchPattern($pattern, "<?php\n\$label = 'array(1, 2)';\n"));
    }
}
