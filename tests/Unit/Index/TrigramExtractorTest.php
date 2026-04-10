<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Index;

use Greph\Index\TrigramExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrigramExtractorTest extends TestCase
{
    #[Test]
    public function itExtractsUniqueLowercasedTrigrams(): void
    {
        $extractor = new TrigramExtractor();

        $this->assertSame([], $extractor->extract('ab'));
        $this->assertSame(['abc', 'bcd'], $extractor->extract('ABCD'));
        $this->assertSame(['ana', 'ban', 'nan'], $extractor->extract('banana'));
    }
}
