<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Walker;

use Greph\Walker\FileList;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileListTest extends TestCase
{
    #[Test]
    public function itNormalizesDeduplicatesAndChunksPaths(): void
    {
        $list = new FileList(['C:\\tmp\\one.php', 'C:\\tmp\\one.php', '/tmp/two.php']);
        $chunks = $list->chunk(1);

        $this->assertSame(['C:/tmp/one.php', '/tmp/two.php'], $list->paths());
        $this->assertCount(2, $list);
        $this->assertSame(['C:/tmp/one.php', '/tmp/two.php'], iterator_to_array($list, false));
        $this->assertCount(2, $chunks);
        $this->assertSame(['C:/tmp/one.php'], $chunks[0]->paths());
        $this->assertSame(['/tmp/two.php'], $chunks[1]->paths());
        $this->assertSame(['C:/tmp/one.php', '/tmp/two.php'], $list->jsonSerialize());
    }

    #[Test]
    public function itRejectsInvalidChunkSizes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FileList(['/tmp/one.php']))->chunk(0);
    }
}
