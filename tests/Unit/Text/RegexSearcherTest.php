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
}
