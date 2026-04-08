<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Walker;

use Phgrep\Tests\Support\Workspace;
use Phgrep\Walker\BinaryDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BinaryDetectorTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('binary-detector');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itDetectsBinaryFilesByNullBytes(): void
    {
        $binaryPath = Workspace::writeFile($this->workspace, 'fixtures/binary.dat', "hello\0world");
        $textPath = Workspace::writeFile($this->workspace, 'fixtures/text.txt', "hello\nworld\n");

        $detector = new BinaryDetector();

        $this->assertTrue($detector->isBinaryFile($binaryPath));
        $this->assertFalse($detector->isBinaryFile($textPath));
    }
}
