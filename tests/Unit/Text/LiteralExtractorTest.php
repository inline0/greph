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
        $this->assertSame(['->save(', '$'], $extractor->extractAll('\$\w+->save\('));
        $this->assertSame([], $extractor->extractAll('foo|bar'));
    }
}
