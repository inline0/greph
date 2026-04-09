<?php

declare(strict_types=1);

namespace Phgrep\Parallel;

use Phgrep\Walker\FileList;

final class WorkerPool
{
    private ResultCollector $resultCollector;

    /** @var \Closure(): (array{0: resource, 1: resource}|false) */
    private \Closure $socketPairFactory;

    /** @var \Closure(): int */
    private \Closure $fork;

    /** @var \Closure(int, FileList): Worker */
    private \Closure $workerFactory;

    /**
     * @param \Closure(): (array{0: resource, 1: resource}|false)|null $socketPairFactory
     * @param \Closure(): int|null $fork
     * @param \Closure(int, FileList): Worker|null $workerFactory
     */
    public function __construct(
        ?ResultCollector $resultCollector = null,
        ?\Closure $socketPairFactory = null,
        ?\Closure $fork = null,
        ?\Closure $workerFactory = null,
    ) {
        $this->resultCollector = $resultCollector ?? new ResultCollector();
        /** @var \Closure(): (array{0: resource, 1: resource}|false) $resolvedSocketPairFactory */
        $resolvedSocketPairFactory = $socketPairFactory ?? static fn (): array|false => stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->socketPairFactory = $resolvedSocketPairFactory;
        /** @var \Closure(): int $resolvedFork */
        $resolvedFork = $fork ?? static fn (): int => pcntl_fork();
        $this->fork = $resolvedFork;
        /** @var \Closure(int, FileList): Worker $resolvedWorkerFactory */
        $resolvedWorkerFactory = $workerFactory ?? static fn (int $index, FileList $chunk): Worker => new Worker($index, $chunk);
        $this->workerFactory = $resolvedWorkerFactory;
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
            $sockets = ($this->socketPairFactory)();

            if ($sockets === false) {
                throw new \RuntimeException('Failed to create worker pipe.');
            }

            $pid = ($this->fork)();

            if ($pid === -1) {
                throw new \RuntimeException('Failed to fork worker process.');
            }

            if ($pid === 0) {
                fclose($sockets[0]);
                ($this->workerFactory)($index, $chunk)->run($task, $sockets[1]);
            }

            fclose($sockets[1]);
            $workers[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }

        return $this->resultCollector->collect($workers);
    }
}
