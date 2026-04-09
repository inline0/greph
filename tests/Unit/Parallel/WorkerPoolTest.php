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

    #[Test]
    public function itThrowsWhenWaitingForAQueuedWorkerFails(): void
    {
        $paths = [
            Workspace::writeFile($this->workspace, 'queued-a.txt', 'a'),
            Workspace::writeFile($this->workspace, 'queued-b.txt', 'b'),
            Workspace::writeFile($this->workspace, 'queued-c.txt', 'c'),
        ];
        $tempPath = $this->workspace . '/queued-buffer.tmp';
        $forkCalls = 0;
        $payload = serialize(['result' => 1]);

        $pool = new WorkerPool(
            fork: static function () use (&$forkCalls): int {
                $forkCalls++;

                return 100 + $forkCalls;
            },
            wait: static fn (int &$status): int => -1,
            tempFileFactory: static fn (): string => $tempPath,
            fileOpener: static function (string $path, string $mode) use ($payload) {
                if ($mode === 'rb') {
                    file_put_contents($path, $payload);
                }

                return fopen($path, $mode);
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to wait for worker process.');

        $pool->map(
            [new FileList([$paths[0]]), new FileList([$paths[1]]), new FileList([$paths[2]])],
            static fn (FileList $chunk): int => count($chunk),
            2,
        );
    }

    #[Test]
    public function itIgnoresUnknownWorkerPidsBeforeCollectingQueuedResults(): void
    {
        $paths = [
            Workspace::writeFile($this->workspace, 'queued-1.txt', '1'),
            Workspace::writeFile($this->workspace, 'queued-2.txt', '2'),
            Workspace::writeFile($this->workspace, 'queued-3.txt', '3'),
        ];
        $tempPaths = [
            $this->workspace . '/queued-1.tmp',
            $this->workspace . '/queued-2.tmp',
            $this->workspace . '/queued-3.tmp',
        ];
        $tempIndex = 0;
        $forkPids = [101, 102, 103];
        $forkIndex = 0;
        $waitPids = [999, 101, 102, 103];
        $waitIndex = 0;

        $pool = new WorkerPool(
            fork: static function () use (&$forkIndex, $forkPids): int {
                return $forkPids[$forkIndex++];
            },
            wait: static function (int &$status) use (&$waitIndex, $waitPids): int {
                return $waitPids[$waitIndex++];
            },
            tempFileFactory: static function () use (&$tempIndex, $tempPaths): string {
                return $tempPaths[$tempIndex++];
            },
            fileOpener: static function (string $path, string $mode) {
                $payloads = [
                    'queued-1.tmp' => serialize(['result' => 11]),
                    'queued-2.tmp' => serialize(['result' => 22]),
                    'queued-3.tmp' => serialize(['result' => 33]),
                ];

                if ($mode === 'rb') {
                    file_put_contents($path, $payloads[basename($path)]);
                }

                return fopen($path, $mode);
            },
        );

        $results = $pool->map(
            [new FileList([$paths[0]]), new FileList([$paths[1]]), new FileList([$paths[2]])],
            static fn (FileList $chunk): int => count($chunk),
            2,
        );

        $this->assertSame([11, 22, 33], $results);
    }

    #[Test]
    public function itThrowsWhenQueuedWorkerBufferCreationFails(): void
    {
        $pool = new WorkerPool(
            tempFileFactory: static fn (): false => false,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create worker buffer.');

        $pool->map([new FileList(['/tmp/one.php']), new FileList(['/tmp/two.php']), new FileList(['/tmp/three.php'])], static fn (): int => 1, 2);
    }

    #[Test]
    public function itThrowsWhenQueuedWorkerBuffersCannotBeOpened(): void
    {
        $tempPath = $this->workspace . '/queued-open.tmp';

        $pool = new WorkerPool(
            tempFileFactory: static fn (): string => $tempPath,
            fileOpener: static fn (string $path, string $mode): false => false,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to open worker buffer.');

        $pool->map([new FileList(['/tmp/one.php']), new FileList(['/tmp/two.php']), new FileList(['/tmp/three.php'])], static fn (): int => 1, 2);
    }

    #[Test]
    public function itThrowsWhenQueuedWorkerForkingFails(): void
    {
        $tempPath = $this->workspace . '/queued-fork.tmp';

        $pool = new WorkerPool(
            tempFileFactory: static fn (): string => $tempPath,
            fileOpener: static fn (string $path, string $mode) => fopen($path, $mode),
            fork: static fn (): int => -1,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fork worker process.');

        $pool->map([new FileList(['/tmp/one.php']), new FileList(['/tmp/two.php']), new FileList(['/tmp/three.php'])], static fn (): int => 1, 2);
    }

    #[Test]
    public function itInvokesQueuedWorkersInTheChildBranch(): void
    {
        $pool = new WorkerPool(
            fork: static fn (): int => 0,
            tempFileFactory: static fn (): string => '/tmp/phgrep-worker-child',
            fileOpener: static fn (string $path, string $mode) => fopen('php://temp', 'w+'),
            workerFactory: static fn (int $index, FileList $chunk): \Phgrep\Parallel\Worker => new \Phgrep\Parallel\Worker(
                $index,
                $chunk,
                static function (int $exitCode): never {
                    throw new WorkerTermination($exitCode);
                },
            ),
        );

        $this->expectException(WorkerTermination::class);

        $pool->map([new FileList(['/tmp/one.php']), new FileList(['/tmp/two.php']), new FileList(['/tmp/three.php'])], static fn (): int => 1, 2);
    }
}
