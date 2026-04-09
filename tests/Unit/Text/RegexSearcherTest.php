<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Text;

use Phgrep\Exceptions\PatternException;
use Phgrep\Text\RegexSearcher;
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
}
