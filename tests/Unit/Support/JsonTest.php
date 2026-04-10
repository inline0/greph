<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Support;

use Greph\Support\Json;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('json');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itEncodesAndDecodesJsonFiles(): void
    {
        $path = $this->workspace . '/nested/data.json';
        $payload = ['name' => 'greph', 'items' => [1, 2, 3]];

        Json::encodeFile($path, $payload);

        $this->assertFileExists($path);
        $this->assertSame($payload, Json::decodeFile($path));
        $this->assertSame($payload, Json::decode(json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function itThrowsForUnreadableFiles(): void
    {
        $this->expectException(\RuntimeException::class);

        Json::decodeFile($this->workspace . '/missing.json');
    }

    #[Test]
    public function itThrowsForUnwritableDestinations(): void
    {
        mkdir($this->workspace . '/directory-target');

        $this->expectException(\RuntimeException::class);

        Json::encodeFile($this->workspace . '/directory-target', ['name' => 'greph']);
    }
}
