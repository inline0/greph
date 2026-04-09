<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Parallel;

use Phgrep\Parallel\WorkerPool;
use Phgrep\Tests\Support\Workspace;
use Phgrep\Walker\FileList;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkerPoolTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('worker-pool');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itMapsTasksWithoutForkingForSingleChunks(): void
    {
        $file = Workspace::writeFile($this->workspace, 'one.txt', 'one');
        $results = (new WorkerPool())->map(
            [new FileList([$file])],
            static fn (FileList $chunk): int => count($chunk),
        );

        $this->assertSame([1], $results);
    }

    #[Test]
    public function itReturnsAnEmptyResultForNoChunks(): void
    {
        $this->assertSame([], (new WorkerPool())->map([], static fn (): never => throw new \RuntimeException('unreachable')));
    }

    #[Test]
    public function itCanMapAcrossMultipleChunks(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for this test.');
        }

        $one = Workspace::writeFile($this->workspace, 'one.txt', 'one');
        $two = Workspace::writeFile($this->workspace, 'two.txt', 'two');
        $results = (new WorkerPool())->map(
            [new FileList([$one]), new FileList([$two])],
            static fn (FileList $chunk): int => count($chunk),
        );

        $this->assertSame([1, 1], $results);
    }

    #[Test]
    public function itPreservesChunkOrderAcrossQueuedWorkers(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for this test.');
        }

        $paths = [];

        for ($index = 0; $index < 4; $index++) {
            $paths[] = Workspace::writeFile($this->workspace, sprintf('queued-%d.txt', $index), (string) $index);
        }

        $chunks = array_map(static fn (string $path): FileList => new FileList([$path]), $paths);
        $results = (new WorkerPool())->map(
            $chunks,
            static function (FileList $chunk): string {
                $path = $chunk->paths()[0];
                $index = (int) preg_replace('/\D+/', '', basename($path));

                usleep((4 - $index) * 20_000);

                return basename($path);
            },
            2,
        );

        $this->assertSame(
            array_map(static fn (string $path): string => basename($path), $paths),
            $results,
        );
    }

    #[Test]
    public function itThrowsWhenSocketCreationFails(): void
    {
        $pool = new WorkerPool(
            socketPairFactory: static fn (): false => false,
            fork: static fn (): int => 1,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create worker pipe.');

        $pool->map([new FileList(['/tmp/one.php']), new FileList(['/tmp/two.php'])], static fn (): int => 1);
    }

    #[Test]
    public function itThrowsWhenForkingFails(): void
    {
        $pool = new WorkerPool(
            socketPairFactory: static function (): array {
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

                if ($sockets === false) {
                    throw new \RuntimeException('Failed to create worker sockets for the test.');
                }

                return [$sockets[0], $sockets[1]];
            },
            fork: static fn (): int => -1,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fork worker process.');

        $pool->map([new FileList(['/tmp/one.php']), new FileList(['/tmp/two.php'])], static fn (): int => 1);
    }

    #[Test]
    public function itInvokesWorkersInTheChildBranch(): void
    {
        $pool = new WorkerPool(
            socketPairFactory: static function (): array {
                $reader = fopen('php://temp', 'w+');
                $writer = fopen('php://temp', 'w+');

                if (!is_resource($reader) || !is_resource($writer)) {
                    throw new \RuntimeException('Failed to create stream resources.');
                }

                return [$reader, $writer];
            },
            fork: static fn (): int => 0,
            workerFactory: static fn (int $index, FileList $chunk): \Phgrep\Parallel\Worker => new \Phgrep\Parallel\Worker(
                $index,
                $chunk,
                static function (int $exitCode): never {
                    throw new WorkerTermination($exitCode);
                },
            ),
        );

        $this->expectException(WorkerTermination::class);

        $pool->map([new FileList(['/tmp/one.php']), new FileList(['/tmp/two.php'])], static fn (): int => 1, 2);
    }
}
