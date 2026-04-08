<?php

declare(strict_types=1);

namespace Phgrep\Parallel;

use Phgrep\Walker\FileList;

final class WorkerPool
{
    private ResultCollector $resultCollector;

    public function __construct(?ResultCollector $resultCollector = null)
    {
        $this->resultCollector = $resultCollector ?? new ResultCollector();
    }

    /**
     * @param list<FileList> $chunks
     * @param callable(FileList): mixed $task
     * @return list<mixed>
     */
    public function map(array $chunks, callable $task): array
    {
        if ($chunks === []) {
            return [];
        }

        if (!function_exists('pcntl_fork') || count($chunks) === 1) {
            return array_map($task, $chunks);
        }

        $workers = [];

        foreach ($chunks as $index => $chunk) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($sockets === false) {
                throw new \RuntimeException('Failed to create worker pipe.');
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new \RuntimeException('Failed to fork worker process.');
            }

            if ($pid === 0) {
                fclose($sockets[0]);
                (new Worker($index, $chunk))->run($task, $sockets[1]);
            }

            fclose($sockets[1]);
            $workers[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }

        return $this->resultCollector->collect($workers);
    }
}
