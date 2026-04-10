<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Text;

use Phgrep\Text\AnchoredLiteralSearcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnchoredLiteralSearcherTest extends TestCase
{
    #[Test]
    public function itMatchesPrefixSuffixAndFullLineLiterals(): void
    {
        $prefix = new AnchoredLiteralSearcher('function ', AnchoredLiteralSearcher::MODE_PREFIX);
        $suffix = new AnchoredLiteralSearcher(');', AnchoredLiteralSearcher::MODE_SUFFIX);
        $fullLine = new AnchoredLiteralSearcher('}', AnchoredLiteralSearcher::MODE_FULL_LINE);

        $prefixMatch = $prefix->match('function demo()');
        $suffixMatch = $suffix->match('$call = foo();');
        $fullLineMatch = $fullLine->match('}');

        $this->assertNotNull($prefixMatch);
        $this->assertNotNull($suffixMatch);
        $this->assertNotNull($fullLineMatch);
        $this->assertSame(1, $prefixMatch->column);
        $this->assertNull($prefix->match(' demo function'));
        $this->assertSame(13, $suffixMatch->column);
        $this->assertNull($suffix->match('$call = foo()'));
        $this->assertSame('}', $fullLineMatch->matchedText);
        $this->assertNull($fullLine->match(' }'));
    }

    #[Test]
    public function itSupportsCaseInsensitiveChecks(): void
    {
        $prefix = new AnchoredLiteralSearcher('function ', AnchoredLiteralSearcher::MODE_PREFIX, true);
        $suffix = new AnchoredLiteralSearcher(');', AnchoredLiteralSearcher::MODE_SUFFIX, true);
        $fullLine = new AnchoredLiteralSearcher('EXIT;', AnchoredLiteralSearcher::MODE_FULL_LINE, true);

        $prefixMatch = $prefix->match('FUNCTION Demo()');
        $suffixMatch = $suffix->match('$CALL = FOO();');
        $fullLineMatch = $fullLine->match('exit;');

        $this->assertNotNull($prefixMatch);
        $this->assertNotNull($suffixMatch);
        $this->assertNotNull($fullLineMatch);
        $this->assertSame('FUNCTION ', $prefixMatch->matchedText);
        $this->assertSame(');', $suffixMatch->matchedText);
        $this->assertSame('exit;', $fullLineMatch->matchedText);
    }

    #[Test]
    public function itCanPrefilterFileContentsByLiteralPresence(): void
    {
        $searcher = new AnchoredLiteralSearcher('function ', AnchoredLiteralSearcher::MODE_PREFIX);

        $this->assertTrue($searcher->mayMatchContents("x\nfunction demo()\n"));
        $this->assertFalse($searcher->mayMatchContents("x\ndemo()\n"));
    }
}
