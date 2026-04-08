<?php

declare(strict_types=1);

namespace Phgrep\Support;

final class CommandRunner
{
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

        $start = hrtime(true);
        $process = proc_open($command, $descriptors, $pipes, $workingDirectory, $processEnvironment);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
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
