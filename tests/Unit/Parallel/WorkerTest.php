<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Parallel;

use Phgrep\Parallel\Worker;
use Phgrep\Walker\FileList;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    #[Test]
    public function itSerializesSuccessfulTaskResults(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            $this->fail('Failed to create worker test sockets.');
        }

        [$reader, $writer] = $sockets;

        try {
            $worker = new Worker(
                3,
                new FileList(['/tmp/one.php']),
                static function (int $exitCode): never {
                    throw new WorkerTermination($exitCode);
                },
            );

            try {
                $worker->run(static fn (FileList $files): int => count($files), $writer);
            } catch (WorkerTermination $termination) {
                $this->assertSame(0, $termination->exitCode);
            }

            $payload = stream_get_contents($reader);

            $this->assertIsString($payload);
            $this->assertSame(['result' => 1], unserialize($payload));
        } finally {
            fclose($reader);
        }
    }

    #[Test]
    public function itSerializesThrownErrors(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            $this->fail('Failed to create worker test sockets.');
        }

        [$reader, $writer] = $sockets;

        try {
            $worker = new Worker(
                7,
                new FileList(['/tmp/two.php']),
                static function (int $exitCode): never {
                    throw new WorkerTermination($exitCode);
                },
            );

            try {
                $worker->run(
                    static function (): never {
                        throw new \RuntimeException('boom');
                    },
                    $writer,
                );
            } catch (WorkerTermination $termination) {
                $this->assertSame(1, $termination->exitCode);
            }

            $payload = stream_get_contents($reader);

            $this->assertIsString($payload);
            $this->assertSame(
                [
                    'error' => \RuntimeException::class,
                    'message' => 'boom',
                    'worker' => 7,
                ],
                unserialize($payload),
            );
        } finally {
            fclose($reader);
        }
    }
}
