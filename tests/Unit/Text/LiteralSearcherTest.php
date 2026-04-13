<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Text;

use Greph\Text\LiteralSearcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LiteralSearcherTest extends TestCase
{
    #[Test]
    public function itMatchesEmptyCaseInsensitiveWholeWordAndPlainNeedles(): void
    {
        $empty = (new LiteralSearcher(''))->match('anything');
        $caseInsensitive = (new LiteralSearcher('needle', caseInsensitive: true))->match('A NEEDLE appears');
        $wholeWord = (new LiteralSearcher('word', wholeWord: true))->match('a word boundary');
        $plain = (new LiteralSearcher('needle'))->match('prefix needle suffix');

        $this->assertNotNull($empty);
        $this->assertSame(1, $empty->column);
        $this->assertSame('', $empty->matchedText);
        $this->assertNotNull($caseInsensitive);
        $this->assertSame(3, $caseInsensitive->column);
        $this->assertSame('NEEDLE', $caseInsensitive->matchedText);
        $this->assertNull((new LiteralSearcher('needle', caseInsensitive: true))->match('plain text'));
        $this->assertNotNull($wholeWord);
        $this->assertSame(3, $wholeWord->column);
        $this->assertSame('word', $wholeWord->matchedText);
        $this->assertNull((new LiteralSearcher('word', wholeWord: true))->match('swordfish'));
        $this->assertNotNull($plain);
        $this->assertSame(8, $plain->column);
        $this->assertSame('needle', $plain->matchedText);
        $this->assertNull((new LiteralSearcher('missing'))->match('prefix needle suffix'));
    }

    #[Test]
    public function itCanPrefilterWholeFileContents(): void
    {
        $plain = new LiteralSearcher('needle');
        $caseInsensitive = new LiteralSearcher('needle', caseInsensitive: true);
        $wholeWord = new LiteralSearcher('word', wholeWord: true);
        $empty = new LiteralSearcher('');

        $this->assertTrue($plain->mayMatchContents("alpha\nneedle\nomega"));
        $this->assertFalse($plain->mayMatchContents("alpha\nomega"));
        $this->assertTrue($caseInsensitive->mayMatchContents("alpha\nNEEDLE\nomega"));
        $this->assertTrue($wholeWord->mayMatchContents("swordfish and word boundaries"));
        $this->assertTrue($empty->mayMatchContents('anything'));
    }

    #[Test]
    public function itCanScanWholeContentsForLiteralOffsets(): void
    {
        $plain = new LiteralSearcher('needle');
        $caseInsensitive = new LiteralSearcher('needle', caseInsensitive: true);
        $wholeWord = new LiteralSearcher('needle', wholeWord: true);

        $this->assertTrue($plain->supportsOccurrenceScan());
        $this->assertSame(7, $plain->findInContents("prefix needle suffix"));
        $this->assertSame('needle', $plain->matchedTextAt("prefix needle suffix", 7));
        $this->assertSame(7, $caseInsensitive->findInContents("prefix NEEDLE suffix"));
        $this->assertSame('NEEDLE', $caseInsensitive->matchedTextAt("prefix NEEDLE suffix", 7));
        $this->assertFalse($wholeWord->supportsOccurrenceScan());
    }

    #[Test]
    public function itCanScanAsciiWholeWordContentsWithoutRegex(): void
    {
        $wholeWord = new LiteralSearcher('needle', wholeWord: true);
        $caseInsensitive = new LiteralSearcher('needle', caseInsensitive: true, wholeWord: true);

        $contents = "prefix needle suffix\nneedle_two\nNEEDLE end";

        $this->assertTrue($wholeWord->supportsWholeWordOccurrenceScanForContents($contents));
        $this->assertSame(7, $wholeWord->findWholeWordInContents($contents));
        $this->assertSame(32, $caseInsensitive->findWholeWordInContents($contents, 8));
        $this->assertSame('NEEDLE', $caseInsensitive->matchedTextAt($contents, 32));
    }

    #[Test]
    public function itFallsBackToUnicodeRegexForNonAsciiWholeWordMatching(): void
    {
        $wholeWord = new LiteralSearcher('word', wholeWord: true);

        $this->assertFalse($wholeWord->supportsWholeWordOccurrenceScanForContents("ä word"));
        $this->assertNull($wholeWord->match("äword"));
        $this->assertNotNull($wholeWord->match("ä word"));
    }
}
