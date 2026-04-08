<?php

declare(strict_types=1);

namespace Phgrep\Parallel;

use Phgrep\Walker\FileList;

final class Worker
{
    public function __construct(
        private readonly int $index,
        private readonly FileList $files,
    ) {
    }

    /**
     * @param callable(FileList): mixed $task
     */
    public function run(callable $task, mixed $socket): never
    {
        $payload = [];
        $exitCode = 0;

        try {
            $payload = ['result' => $task($this->files)];
        } catch (\Throwable $throwable) {
            $payload = [
                'error' => $throwable::class,
                'message' => $throwable->getMessage(),
                'worker' => $this->index,
            ];
            $exitCode = 1;
        }

        fwrite($socket, serialize($payload));
        fclose($socket);
        exit($exitCode);
    }
}
