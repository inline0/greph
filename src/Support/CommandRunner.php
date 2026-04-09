<?php

declare(strict_types=1);

namespace Phgrep\Support;

final class CommandRunner
{
    /** @var \Closure(list<string>, array<int, array{0: string, 1: string}>, array<int, mixed>, ?string, array<string, string>): (resource|false) */
    private \Closure $processStarter;

    /**
     * @param \Closure(list<string>, array<int, array{0: string, 1: string}>, array<int, mixed>, ?string, array<string, string>): (resource|false)|null $processStarter
     */
    public function __construct(?\Closure $processStarter = null)
    {
        /** @var \Closure(list<string>, array<int, array{0: string, 1: string}>, array<int, mixed>, ?string, array<string, string>): (resource|false) $resolvedProcessStarter */
        $resolvedProcessStarter = $processStarter ?? static function (
            array $command,
            array $descriptors,
            array &$pipes,
            ?string $workingDirectory,
            array $processEnvironment,
        ) {
            return proc_open(array_values($command), $descriptors, $pipes, $workingDirectory, $processEnvironment);
        };
        $this->processStarter = $resolvedProcessStarter;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    public function run(array $command, ?string $workingDirectory = null, array $environment = []): ProcessResult
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

        /** @var resource $stdin */
        $stdin = $pipes[0];
        /** @var resource $stdoutPipe */
        $stdoutPipe = $pipes[1];
        /** @var resource $stderrPipe */
        $stderrPipe = $pipes[2];

        fclose($stdin);
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
