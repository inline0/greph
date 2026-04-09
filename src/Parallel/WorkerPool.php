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

    /** @var \Closure(int): int */
    private \Closure $wait;

    /** @var \Closure(): (string|false) */
    private \Closure $tempFileFactory;

    /** @var \Closure(string, string): mixed */
    private \Closure $fileOpener;

    /**
     * @param \Closure(): (array{0: resource, 1: resource}|false)|null $socketPairFactory
     * @param \Closure(): int|null $fork
     * @param \Closure(int, FileList): Worker|null $workerFactory
     * @param \Closure(int): int|null $wait
     * @param (\Closure(): (string|false))|null $tempFileFactory
     * @param \Closure(string, string): mixed|null $fileOpener
     */
    public function __construct(
        ?ResultCollector $resultCollector = null,
        ?\Closure $socketPairFactory = null,
        ?\Closure $fork = null,
        ?\Closure $workerFactory = null,
        ?\Closure $wait = null,
        ?\Closure $tempFileFactory = null,
        ?\Closure $fileOpener = null,
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
        /** @var \Closure(int): int $resolvedWait */
        $resolvedWait = $wait ?? static function (int &$status): int {
            return pcntl_wait($status);
        };
        $this->wait = $resolvedWait;
        /** @var \Closure(): (string|false) $resolvedTempFileFactory */
        $resolvedTempFileFactory = $tempFileFactory ?? static fn (): string|false => tempnam(sys_get_temp_dir(), 'phgrep-worker-');
        $this->tempFileFactory = $resolvedTempFileFactory;
        /** @var \Closure(string, string): mixed $resolvedFileOpener */
        $resolvedFileOpener = $fileOpener ?? static fn (string $path, string $mode): mixed => fopen($path, $mode);
        $this->fileOpener = $resolvedFileOpener;
    }

    /**
     * @param list<FileList> $chunks
     * @param callable(FileList): mixed $task
     * @param (callable(mixed): mixed)|null $resultEncoder
     * @param (callable(mixed): mixed)|null $resultDecoder
     * @return list<mixed>
     */
    public function map(
        array $chunks,
        callable $task,
        ?int $maxWorkers = null,
        ?callable $resultEncoder = null,
        ?callable $resultDecoder = null,
    ): array {
        if ($chunks === []) {
            return [];
        }

        $workerLimit = min($maxWorkers ?? count($chunks), count($chunks));

        if (!function_exists('pcntl_fork') || $workerLimit === 1) {
            return array_map($task, $chunks);
        }

        if (count($chunks) <= $workerLimit) {
            $workers = [];

            foreach ($chunks as $index => $chunk) {
                $workers[] = $this->startSocketWorker($index, $chunk, $task, $resultEncoder);
            }

            return $this->resultCollector->collect($workers, $resultDecoder);
        }

        $pending = [];

        foreach ($chunks as $index => $chunk) {
            $pending[] = ['index' => $index, 'chunk' => $chunk];
        }

        $pendingIndex = 0;
        $activeWorkers = [];
        $results = array_fill(0, count($chunks), null);

        while ($pendingIndex < count($pending) || $activeWorkers !== []) {
            while (count($activeWorkers) < $workerLimit && $pendingIndex < count($pending)) {
                $entry = $pending[$pendingIndex];
                $pendingIndex++;

                $worker = $this->startFileWorker($entry['index'], $entry['chunk'], $task, $resultEncoder);
                $activeWorkers[$worker['pid']] = $worker;
            }

            $status = 0;
            $pid = ($this->wait)($status);

            if ($pid === -1) {
                throw new \RuntimeException('Failed to wait for worker process.');
            }

            if (!isset($activeWorkers[$pid])) {
                continue;
            }

            $worker = $activeWorkers[$pid];
            unset($activeWorkers[$pid]);
            $results[$worker['index']] = $this->resultCollector->collectWorker(
                ['pid' => $worker['pid'], 'socket' => $worker['socket']],
                false,
                $resultDecoder,
            );
        }

        /** @var list<mixed> $orderedResults */
        $orderedResults = array_values($results);

        return $orderedResults;
    }

    /**
     * @param callable(FileList): mixed $task
     * @param (callable(mixed): mixed)|null $resultEncoder
     * @return array{pid: int, socket: mixed}
     */
    private function startSocketWorker(int $index, FileList $chunk, callable $task, ?callable $resultEncoder = null): array
    {
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
            ($this->workerFactory)($index, $chunk)->run($task, $sockets[1], $resultEncoder);
        }

        fclose($sockets[1]);

        return ['pid' => $pid, 'socket' => $sockets[0]];
    }

    /**
     * @param callable(FileList): mixed $task
     * @param (callable(mixed): mixed)|null $resultEncoder
     * @return array{pid: int, socket: mixed, index: int, tempPath: string}
     */
    private function startFileWorker(int $index, FileList $chunk, callable $task, ?callable $resultEncoder = null): array
    {
        $tempPath = ($this->tempFileFactory)();

        if ($tempPath === false) {
            throw new \RuntimeException('Failed to create worker buffer.');
        }

        $writer = ($this->fileOpener)($tempPath, 'wb');
        $reader = ($this->fileOpener)($tempPath, 'rb');

        if ($writer === false || $reader === false) {
            @unlink($tempPath);

            throw new \RuntimeException('Failed to open worker buffer.');
        }

        $pid = ($this->fork)();

        if ($pid === -1) {
            fclose($writer);
            fclose($reader);
            @unlink($tempPath);

            throw new \RuntimeException('Failed to fork worker process.');
        }

        if ($pid === 0) {
            fclose($reader);
            ($this->workerFactory)($index, $chunk)->run($task, $writer, $resultEncoder);
        }

        fclose($writer);

        return ['pid' => $pid, 'socket' => $reader, 'index' => $index, 'tempPath' => $tempPath];
    }
}
