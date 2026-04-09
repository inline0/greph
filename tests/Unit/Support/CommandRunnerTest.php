<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Support;

use Phgrep\Support\CommandRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommandRunnerTest extends TestCase
{
    #[Test]
    public function itCapturesStdoutStderrExitCodeAndEnvironment(): void
    {
        $runner = new CommandRunner();
        $result = $runner->run(
            [
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, getenv("PHGREP_TEST")); fwrite(STDERR, "err"); exit(3);',
            ],
            null,
            ['PHGREP_TEST' => 'ok'],
        );

        $this->assertSame(3, $result->exitCode);
        $this->assertSame('ok', $result->stdout);
        $this->assertSame('err', $result->stderr);
        $this->assertSame('okerr', $result->output());
        $this->assertFalse($result->successful());
        $this->assertGreaterThanOrEqual(0.0, $result->durationMs);
    }

    #[Test]
    public function itThrowsWhenProcessesCannotStart(): void
    {
        $runner = new CommandRunner(
            static fn (array $command, array $descriptors, array &$pipes, ?string $workingDirectory, array $environment): false => false,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to start process.');

        $runner->run([PHP_BINARY, '-v']);
    }
}
