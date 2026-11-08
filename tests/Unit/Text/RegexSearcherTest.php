<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Text;

use Greph\Exceptions\PatternException;
use Greph\Text\RegexSearcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegexSearcherTest extends TestCase
{
    #[Test]
    public function itMatchesRegexesWithPrefiltersAndWholeWords(): void
    {
        $searcher = new RegexSearcher('save', caseInsensitive: true, wholeWord: true, literalPrefilter: 'save');
        $match = $searcher->match('Before SAVE after');

        $this->assertNotNull($match);
        $this->assertSame(8, $match->column);
        $this->assertSame('SAVE', $match->matchedText);
        $this->assertSame('SAVE', $match->captures[0]);
        $this->assertNull($searcher->match('unsaved value'));
    }

    #[Test]
    public function itRejectsInvalidPatterns(): void
    {
        $this->expectException(PatternException::class);

        new RegexSearcher('(');
    }

    #[Test]
    public function itFallsBackToNonUnicodeRegexesForInvalidUtf8Input(): void
    {
        $searcher = new RegexSearcher('a');
        $match = $searcher->match("\xFFa");

        $this->assertNotNull($match);
        $this->assertSame(2, $match->column);
        $this->assertSame('a', $match->matchedText);
    }

    #[Test]
    public function itCanPrefilterWholeFileContentsFromExtractedLiterals(): void
    {
        $searcher = new RegexSearcher('save', caseInsensitive: true, literalPrefilter: 'save');
        $noPrefilter = new RegexSearcher('^save$');

        $this->assertTrue($searcher->mayMatchContents("Before\nSAVE\nAfter"));
        $this->assertFalse($searcher->mayMatchContents("Before\nstore\nAfter"));
        $this->assertTrue($noPrefilter->mayMatchContents("anything"));
    }

    #[Test]
    public function itExposesOccurrenceScanningHelpersForLiteralPrefilters(): void
    {
        $searcher = new RegexSearcher('\$[A-Za-z_][A-Za-z0-9_]* = new [A-Za-z_][A-Za-z0-9_]*\(\)', literalPrefilter: ' = new ');

        $this->assertTrue($searcher->supportsOccurrenceScan());
        $this->assertSame(3, $searcher->findPrefilterInContents("abc = new Foo()\n"));
        $this->assertFalse($searcher->findPrefilterInContents("abc = old Foo()\n"));
        $this->assertNotNull($searcher->matchPrefilteredLine('$foo = new Bar()'));
        $this->assertNull($searcher->matchPrefilteredLine('$foo = old Bar()'));
        $noPrefilter = new RegexSearcher('^save$');

        $this->assertNull($searcher->match('old content'));
        $this->assertFalse($noPrefilter->supportsOccurrenceScan());
        $this->assertFalse($noPrefilter->findPrefilterInContents("save\n"));
    }

    #[Test]
    public function itAcceptsPatternsThatContainTheDefaultDelimiter(): void
    {
        $cases = [
            'r\s\#f+' => 'color #fff',
            '(?#comment)foo' => 'a foo b',
            'f(?#x)oo' => 'a foo b',
            'a#b' => 'a#b',
            '\\\\#' => 'path\\#',
            '[#]x' => '#x',
            '(?x) a \# b # trailing comment' => 'a#b',
            "#~/%!@;,`=x" => "#~/%!@;,`=x",
            "a#~/%!@;,`=\x01\x02\x03\x04\x05\x06\x07\x08\x0E\x0F\x7F\\#" => "a#~/%!@;,`=\x01\x02\x03\x04\x05\x06\x07\x08\x0E\x0F\x7F#",
        ];

        foreach ($cases as $pattern => $subject) {
            $searcher = new RegexSearcher($pattern);

            $this->assertNotNull($searcher->match($subject), $pattern);
            $this->assertNull($searcher->match('unrelated'), $pattern);
        }

        $this->assertNotNull((new RegexSearcher('R\s\#F+', caseInsensitive: true))->match('color #fff'));
        $this->assertNotNull((new RegexSearcher('\#fff', wholeWord: true))->match('a #fff b'));
    }

    #[Test]
    public function itCanSkipCaptureMaterializationForNonJsonPaths(): void
    {
        $searcher = new RegexSearcher('(save)', literalPrefilter: 'save');
        $match = $searcher->matchWithCaptures('save value', false);

        $this->assertNotNull($match);
        $this->assertSame(1, $match->column);
        $this->assertSame('save', $match->matchedText);
        $this->assertSame([], $match->captures);
        $this->assertTrue($searcher->matchesPrefilteredLine('save value'));
        $this->assertFalse($searcher->matchesPrefilteredLine('other value'));
    }
}
