<?php

declare(strict_types=1);

namespace Greph\Tests\Unit;

use Greph\Ast\AstSearchOptions;
use Greph\Greph;
use Greph\Text\TextFileResult;
use Greph\Text\TextResultCodec;
use Greph\Text\TextSearchOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GrephTest extends TestCase
{
    #[Test]
    public function itUsesTheExpectedParallelThresholdHeuristics(): void
    {
        $textDisabled = $this->invokeStaticMethod(
            Greph::class,
            'shouldUseTextWorkers',
            'function',
            new TextSearchOptions(fixedString: true, jobs: 1),
            10_000,
        );
        $textDefaultDisabled = $this->invokeStaticMethod(
            Greph::class,
            'shouldUseTextWorkers',
            'new [A-Za-z]+',
            new TextSearchOptions(jobs: 2),
            4_000,
        );
        $textDefaultEnabled = $this->invokeStaticMethod(
            Greph::class,
            'shouldUseTextWorkers',
            'new [A-Za-z]+',
            new TextSearchOptions(jobs: 2),
            4_001,
        );
        $textSummaryEnabled = $this->invokeStaticMethod(
            Greph::class,
            'shouldUseTextWorkers',
            'function!',
            new TextSearchOptions(fixedString: true, jobs: 2, countOnly: true),
            1_501,
        );
        $textSummaryDisabled = $this->invokeStaticMethod(
            Greph::class,
            'shouldUseTextWorkers',
            'function!',
            new TextSearchOptions(fixedString: true, jobs: 2, countOnly: true),
            1_500,
        );
        $astDisabled = $this->invokeStaticMethod(Greph::class, 'shouldUseAstWorkers', 1, 10_000);
        $astEnabled = $this->invokeStaticMethod(Greph::class, 'shouldUseAstWorkers', 2, 1_501);
        $rewriteDisabled = $this->invokeStaticMethod(Greph::class, 'shouldUseRewriteWorkers', 2, 1_500);
        $rewriteEnabled = $this->invokeStaticMethod(Greph::class, 'shouldUseRewriteWorkers', 2, 1_501);

        $this->assertFalse($textDisabled);
        $this->assertFalse($textDefaultDisabled);
        $this->assertTrue($textDefaultEnabled);
        $this->assertTrue($textSummaryEnabled);
        $this->assertFalse($textSummaryDisabled);
        $this->assertFalse($astDisabled);
        $this->assertTrue($astEnabled);
        $this->assertFalse($rewriteDisabled);
        $this->assertTrue($rewriteEnabled);
    }

    #[Test]
    public function itAppliesTheFixedLiteralThresholdBump(): void
    {
        $enabled = $this->invokeStaticMethod(
            Greph::class,
            'shouldUseTextWorkers',
            'function',
            new TextSearchOptions(fixedString: true, jobs: 2),
            8_001,
        );
        $disabledByPattern = $this->invokeStaticMethod(
            Greph::class,
            'shouldUseTextWorkers',
            'func-tion',
            new TextSearchOptions(fixedString: true, jobs: 2),
            8_001,
        );
        $disabledByFlags = $this->invokeStaticMethod(
            Greph::class,
            'shouldUseTextWorkers',
            'function',
            new TextSearchOptions(fixedString: true, jobs: 2, caseInsensitive: true),
            8_001,
        );
        $astStillIndependent = $this->invokeStaticMethod(
            Greph::class,
            'shouldUseAstWorkers',
            2,
            1_600,
        );

        $this->assertTrue($enabled);
        $this->assertTrue($disabledByPattern);
        $this->assertTrue($disabledByFlags);
        $this->assertTrue($astStillIndependent);
    }

    #[Test]
    public function itEncodesValidatedTextWorkerResults(): void
    {
        $codec = new TextResultCodec();
        $encoded = $this->invokeStaticMethod(
            Greph::class,
            'encodeTextWorkerResults',
            [new TextFileResult('file.php', [], 1)],
            $codec,
        );

        $this->assertSame([['f' => 'file.php', 'c' => 1]], $encoded);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Worker returned invalid text result set.');
        $this->invokeStaticMethod(Greph::class, 'encodeTextWorkerResults', 'bad', $codec);
    }

    /**
     * @return mixed
     */
    private function invokeStaticMethod(string $class, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$arguments);
    }
}
