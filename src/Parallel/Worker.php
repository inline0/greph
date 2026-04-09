<?php

declare(strict_types=1);

namespace Phgrep\Parallel;

use Phgrep\Walker\FileList;

final class Worker
{
    /** @var callable(int): never */
    private $terminator;

    public function __construct(
        private readonly int $index,
        private readonly FileList $files,
        ?callable $terminator = null,
    ) {
        /** @var callable(int): never $resolvedTerminator */
        $resolvedTerminator = $terminator ?? self::terminateProcess(...);
        $this->terminator = $resolvedTerminator;
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
        ($this->terminator)($exitCode);
    }

    /** @codeCoverageIgnore */
    private static function terminateProcess(int $exitCode): never
    {
        exit($exitCode);
    }
}
