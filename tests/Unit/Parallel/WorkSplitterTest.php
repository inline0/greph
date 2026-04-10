<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Parallel;

use Greph\Parallel\WorkSplitter;
use Greph\Tests\Support\Workspace;
use Greph\Walker\FileList;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkSplitterTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('work-splitter');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itSplitsFilesAcrossWorkers(): void
    {
        $large = Workspace::writeFile($this->workspace, 'large.txt', str_repeat('a', 100));
        $medium = Workspace::writeFile($this->workspace, 'medium.txt', str_repeat('b', 50));
        $small = Workspace::writeFile($this->workspace, 'small.txt', 'c');
        $chunks = (new WorkSplitter())->split(new FileList([$large, $medium, $small]), 2);
        $allPaths = [];

        $this->assertCount(2, $chunks);

        foreach ($chunks as $chunk) {
            foreach ($chunk->paths() as $path) {
                $allPaths[] = $path;
            }
        }

        sort($allPaths);

        $this->assertSame([$large, $medium, $small], $allPaths);
    }

    #[Test]
    public function itHandlesEdgeCases(): void
    {
        $splitter = new WorkSplitter();

        $this->assertSame([], $splitter->split(new FileList([]), 2));

        $this->expectException(\InvalidArgumentException::class);

        $splitter->split(new FileList([]), 0);
    }
}
