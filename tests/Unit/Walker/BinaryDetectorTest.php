<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Walker;

use Greph\Tests\Support\Workspace;
use Greph\Walker\BinaryDetector;
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

    #[Test]
    public function itHandlesMissingFilesAndInvalidSampleSizes(): void
    {
        $emptyPath = Workspace::writeFile($this->workspace, 'fixtures/empty.dat', '');
        $suspiciousPath = Workspace::writeFile($this->workspace, 'fixtures/control.dat', "\x7F\x01ok");

        $this->assertFalse((new BinaryDetector())->isBinaryFile($this->workspace . '/missing.dat'));
        $this->assertFalse((new BinaryDetector())->isBinaryFile($emptyPath));
        $this->assertTrue((new BinaryDetector(4, 0.25))->isBinaryFile($suspiciousPath));

        $this->expectException(\InvalidArgumentException::class);

        new BinaryDetector(0);
    }
}
