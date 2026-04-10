<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Text;

use Greph\Tests\Support\Workspace;
use Greph\Text\BufferedReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BufferedReaderTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('buffered-reader');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itReadsLinesAcrossSmallBuffers(): void
    {
        $path = Workspace::writeFile($this->workspace, 'sample.txt', "alpha\r\nbeta\nlast");
        $lines = iterator_to_array((new BufferedReader(4))->readLines($path), false);

        $this->assertCount(3, $lines);
        $this->assertSame(1, $lines[0]->number);
        $this->assertSame('alpha', $lines[0]->content);
        $this->assertSame(2, $lines[1]->number);
        $this->assertSame('beta', $lines[1]->content);
        $this->assertSame(3, $lines[2]->number);
        $this->assertSame('last', $lines[2]->content);
    }

    #[Test]
    public function itReturnsNoLinesForUnreadableFiles(): void
    {
        $lines = iterator_to_array((new BufferedReader())->readLines($this->workspace . '/missing.txt'), false);

        $this->assertSame([], $lines);
    }

    #[Test]
    public function itRejectsInvalidBufferSizes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BufferedReader(0);
    }
}
