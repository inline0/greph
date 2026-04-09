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
    public function itExtractsSortedUniqueLiteralSegments(): void
    {
        $extractor = new LiteralExtractor();
        $segments = array_values(array_filter(
            $extractor->extractSegments('\$[A-Za-z_][A-Za-z0-9_]* = new [A-Za-z_][A-Za-z0-9_]*\(\)'),
            static fn (string $segment): bool => strlen($segment) >= 2,
        ));

        $this->assertSame(
            [' = new ', '()'],
            $segments,
        );
        $this->assertSame(
            ['array('],
            array_values(array_filter(
                $extractor->extractSegments('array\([^)]+\)'),
                static fn (string $segment): bool => strlen($segment) >= 3,
            )),
        );
    }
}
