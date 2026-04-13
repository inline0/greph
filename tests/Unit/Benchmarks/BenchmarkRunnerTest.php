<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Benchmarks;

use Greph\Benchmarks\BenchmarkRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkRunnerTest extends TestCase
{
    private BenchmarkRunner $runner;

    protected function setUp(): void
    {
        $rootPath = dirname(__DIR__, 3);
        $this->runner = new BenchmarkRunner($rootPath);
    }

    #[Test]
    public function itUsesCanonicalSuiteCategoryOrdering(): void
    {
        /** @var list<array{category:string}> $suites */
        $suites = $this->invokePrivate('suites');
        $categories = array_values(
            array_unique(
                array_map(static fn (array $suite): string => $suite['category'], $suites),
            ),
        );

        $this->assertSame(
            [
                'text',
                'walker',
                'parallel',
                'ast',
                'ast-internal',
                'ast-parse',
                'indexed-load',
                'indexed-summary',
                'indexed-text',
                'indexed-text-cold',
                'ast-indexed',
                'ast-cached',
                'indexed-build',
                'ast-indexed-build',
                'ast-cached-build',
            ],
            $categories,
        );
    }

    #[Test]
    public function itSeparatesRuntimeBuildAndColdStorePaths(): void
    {
        $runtimeTextPath = $this->invokePrivate('textIndexPath', [['category' => 'indexed-text'], 'wordpress']);
        $coldTextPath = $this->invokePrivate('textIndexPath', [['category' => 'indexed-text-cold'], 'wordpress']);
        $buildTextPath = $this->invokePrivate('textIndexPath', [['category' => 'indexed-build'], 'wordpress']);

        $this->assertStringContainsString('/build/benchmarks/indexes/runtime/wordpress', $runtimeTextPath);
        $this->assertStringContainsString('/build/benchmarks/indexes/cold/wordpress', $coldTextPath);
        $this->assertStringContainsString('/build/benchmarks/indexes/build/wordpress', $buildTextPath);
        $this->assertNotSame($runtimeTextPath, $coldTextPath);
        $this->assertNotSame($runtimeTextPath, $buildTextPath);

        $runtimeAstIndexPath = $this->invokePrivate('astIndexPath', [['category' => 'ast-indexed'], 'wordpress']);
        $buildAstIndexPath = $this->invokePrivate('astIndexPath', [['category' => 'ast-indexed-build'], 'wordpress']);
        $runtimeAstCachePath = $this->invokePrivate('astCachePath', [['category' => 'ast-cached'], 'wordpress']);
        $buildAstCachePath = $this->invokePrivate('astCachePath', [['category' => 'ast-cached-build'], 'wordpress']);

        $this->assertStringContainsString('/build/benchmarks/ast-indexes/runtime/wordpress', $runtimeAstIndexPath);
        $this->assertStringContainsString('/build/benchmarks/ast-indexes/build/wordpress', $buildAstIndexPath);
        $this->assertStringContainsString('/build/benchmarks/ast-caches/runtime/wordpress', $runtimeAstCachePath);
        $this->assertStringContainsString('/build/benchmarks/ast-caches/build/wordpress', $buildAstCachePath);
        $this->assertNotSame($runtimeAstIndexPath, $buildAstIndexPath);
        $this->assertNotSame($runtimeAstCachePath, $buildAstCachePath);
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invokePrivate(string $method, array $arguments = []): mixed
    {
        $reflectionMethod = new \ReflectionMethod($this->runner, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($this->runner, $arguments);
    }
}
