<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Text;

use Phgrep\Text\LiteralExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LiteralExtractorTest extends TestCase
{
    #[Test]
    public function itExtractsTheLongestLiteralSegmentFromRegexPatterns(): void
    {
        $extractor = new LiteralExtractor();

        $this->assertSame('->save(', $extractor->extract('\$\w+->save\('));
        $this->assertSame('function', $extractor->extract('^function\s+[a-z_]+'));
        $this->assertNull($extractor->extract('^.*$'));
    }

    #[Test]
    public function itExtractsOrderedLiteralSegmentsFromRegexPatterns(): void
    {
        $extractor = new LiteralExtractor();

        $this->assertSame(
            ['$', ' = new ', '()'],
            $extractor->extractSegments('\$[A-Za-z_][A-Za-z0-9_]* = new [A-Za-z_][A-Za-z0-9_]*\(\)'),
        );
        $this->assertSame(
            ['array(', ')'],
            $extractor->extractSegments('array\([^)]+\)'),
        );
    }
}
