<?php

declare(strict_types=1);

namespace Greph\Support;

final class CommandRunner
{
    /** @var \Closure(list<string>, array<int, array{0: string, 1: string}>, array<int, mixed>, ?string, array<string, string>): (resource|false) */
    private \Closure $processStarter;

    /**
     * @param \Closure(list<string>, array<int, array{0: string, 1: string}>, array<int, mixed>, ?string, array<string, string>): (resource|false)|null $processStarter
     */
    public function __construct(?\Closure $processStarter = null)
    {
        $this->processStarter = $processStarter ?? self::defaultProcessStarter();
    }

    /**
     * @return \Closure(list<string>, array<int, array{0: string, 1: string}>, array<int, mixed>, ?string, array<string, string>): (resource|false)
     */
    private static function defaultProcessStarter(): \Closure
    {
        return self::runProcess(...);
    }

    /**
     * @param list<string> $command
     * @param array<int, array{0: string, 1: string}> $descriptors
     * @param array<int, mixed> $pipes
     * @param array<string, string> $processEnvironment
     * @return resource|false
     */
    private static function runProcess(
        array $command,
        array $descriptors,
        array &$pipes,
        ?string $workingDirectory,
        array $processEnvironment,
    ) {
        return proc_open($command, $descriptors, $pipes, $workingDirectory, $processEnvironment);
    }

    public static function processDisabled(): self
    {
        return new self(
            static function (
                array $command,
                array $descriptors,
                array &$pipes,
                ?string $workingDirectory,
                array $processEnvironment,
            ): false {
                throw new \RuntimeException(
                    sprintf('Process execution is disabled; cannot run command: %s', implode(' ', $command)),
                );
            },
        );
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    public function run(array $command, ?string $workingDirectory = null, array $environment = [], string $input = ''): ProcessResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /** @var array<string, string> $inheritedEnvironment */
        $inheritedEnvironment = getenv();
        $processEnvironment = array_merge($inheritedEnvironment, $environment);
        /** @var array{0: resource|null, 1: resource|null, 2: resource|null} $pipes */
        $pipes = [null, null, null];

        $start = hrtime(true);
        $process = ($this->processStarter)($command, $descriptors, $pipes, $workingDirectory, $processEnvironment);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start process.');
        }

        /** @var resource $stdinPipe */
        $stdinPipe = $pipes[0];
        /** @var resource $stdoutPipe */
        $stdoutPipe = $pipes[1];
        /** @var resource $stderrPipe */
        $stderrPipe = $pipes[2];

        fwrite($stdinPipe, $input);
        fclose($stdinPipe);
        $stdout = stream_get_contents($stdoutPipe);
        fclose($stdoutPipe);
        $stderr = stream_get_contents($stderrPipe);
        fclose($stderrPipe);
        $exitCode = proc_close($process);
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        return new ProcessResult(
            exitCode: $exitCode,
            stdout: $stdout === false ? '' : $stdout,
            stderr: $stderr === false ? '' : $stderr,
            durationMs: $durationMs,
        );
    }
}
