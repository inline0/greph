<?php

declare(strict_types=1);

namespace Greph\Tests\Integration;

use Greph\Support\CommandRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutlineParityTest extends TestCase
{
    #[Test]
    public function outlineMatchesAstGrep051(): void
    {
        $directory = dirname(__DIR__) . '/fixtures/outline';
        $fixture = json_decode((string) file_get_contents($directory . '/expected.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['cases']);
        $runner = new CommandRunner();
        $index = 0;
        foreach ($fixture['cases'] as $case) {
            self::assertIsArray($case);
            self::assertIsArray($case['arguments']);
            self::assertIsString($case['output']);
            $arguments = [];
            foreach ($case['arguments'] as $argument) {
                self::assertIsString($argument);
                $arguments[] = $argument;
            }
            $input = $case['input'] ?? '';
            self::assertIsString($input);
            $result = $runner->run([PHP_BINARY, dirname(__DIR__, 2) . '/bin/sg', 'outline', ...$arguments], $directory, [], $input);
            self::assertSame(0, $result->exitCode, $result->stderr);
            if (in_array('--json=compact', $arguments, true) || in_array('--json=stream', $arguments, true)) {
                self::assertSame(json_decode($case['output'], true, 512, JSON_THROW_ON_ERROR), json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR), 'JSON case ' . $index);
            } else {
                self::assertSame($case['output'], $result->stdout, 'Text case ' . $index);
            }
            $index++;
        }
    }
}
