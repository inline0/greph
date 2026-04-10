<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Text;

use Greph\Text\TextFileResult;
use Greph\Text\TextMatch;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextValueObjectsTest extends TestCase
{
    #[Test]
    public function itReportsMatchCounts(): void
    {
        $match = new TextMatch('/tmp/file.txt', 2, 3, 'needle');
        $result = new TextFileResult('/tmp/file.txt', [$match]);
        $empty = new TextFileResult('/tmp/empty.txt', []);

        $this->assertSame(1, $result->matchCount());
        $this->assertTrue($result->hasMatches());
        $this->assertFalse($empty->hasMatches());
    }
}
