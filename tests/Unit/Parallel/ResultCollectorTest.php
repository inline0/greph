<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Parallel;

use Phgrep\Parallel\ResultCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResultCollectorTest extends TestCase
{
    #[Test]
    public function itCollectsWorkerResults(): void
    {
        $this->requirePcntl();

        $collector = new ResultCollector();
        $workers = [
            $this->spawnWorker(serialize(['result' => 'first'])),
            $this->spawnWorker(serialize(['result' => 'second'])),
        ];

        $this->assertSame(['first', 'second'], $collector->collect($workers));
    }

    #[Test]
    public function itThrowsForErrorPayloads(): void
    {
        $this->requirePcntl();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Worker 7 failed with RuntimeException: boom');

        (new ResultCollector())->collect([
            $this->spawnWorker(serialize(['error' => 'RuntimeException', 'message' => 'boom', 'worker' => 7])),
        ]);
    }

    #[Test]
    public function itThrowsForInvalidOrMissingWorkerOutput(): void
    {
        $this->requirePcntl();

        try {
            (new ResultCollector())->collect([$this->spawnWorker(serialize('not-an-array'))]);
            $this->fail('Expected invalid output exception.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('returned invalid output', $exception->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('produced no output');

        (new ResultCollector())->collect([$this->spawnWorker('')]);
    }

    private function requirePcntl(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for this test.');
        }
    }

    /**
     * @return array{pid: int, socket: mixed}
     */
    private function spawnWorker(string $payload): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new \RuntimeException('Failed to create test sockets.');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork test worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);

            if ($payload !== '') {
                fwrite($sockets[1], $payload);
            }

            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);

        return ['pid' => $pid, 'socket' => $sockets[0]];
    }
}
