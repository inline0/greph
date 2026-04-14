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
                'indexed-text-many',
                'indexed-set-text',
                'ast-indexed',
                'ast-cached',
                'ast-indexed-set',
                'ast-cached-set',
                'indexed-build',
                'indexed-refresh',
                'ast-indexed-build',
                'ast-indexed-refresh',
                'ast-cached-build',
                'ast-cached-refresh',
            ],
            $categories,
        );
    }

    #[Test]
    public function itSeparatesRuntimeBuildAndColdStorePaths(): void
    {
        $runtimeTextPath = $this->invokePrivate('textIndexPath', [['category' => 'indexed-text'], 'wordpress']);
        $coldTextPath = $this->invokePrivate('textIndexPath', [['category' => 'indexed-text-cold'], 'wordpress']);
        $manyTextPath = $this->invokePrivate('textIndexPath', [['category' => 'indexed-text-many'], 'wordpress']);
        $setTextPath = $this->invokePrivate('textIndexPath', [['category' => 'indexed-set-text'], 'wordpress']);
        $buildTextPath = $this->invokePrivate('textIndexPath', [['category' => 'indexed-build'], 'wordpress']);
        $refreshTextPath = $this->invokePrivate('textIndexPath', [['category' => 'indexed-refresh'], 'wordpress']);

        $this->assertStringContainsString('/build/benchmarks/indexes/runtime/wordpress', $runtimeTextPath);
        $this->assertStringContainsString('/build/benchmarks/indexes/cold/wordpress', $coldTextPath);
        $this->assertStringContainsString('/build/benchmarks/indexes/many/wordpress', $manyTextPath);
        $this->assertStringContainsString('/build/benchmarks/indexes/set/wordpress', $setTextPath);
        $this->assertStringContainsString('/build/benchmarks/indexes/build/wordpress', $buildTextPath);
        $this->assertStringContainsString('/build/benchmarks/indexes/refresh/wordpress', $refreshTextPath);
        $this->assertNotSame($runtimeTextPath, $coldTextPath);
        $this->assertNotSame($runtimeTextPath, $manyTextPath);
        $this->assertNotSame($runtimeTextPath, $setTextPath);
        $this->assertNotSame($runtimeTextPath, $buildTextPath);
        $this->assertNotSame($runtimeTextPath, $refreshTextPath);

        $runtimeAstIndexPath = $this->invokePrivate('astIndexPath', [['category' => 'ast-indexed'], 'wordpress']);
        $buildAstIndexPath = $this->invokePrivate('astIndexPath', [['category' => 'ast-indexed-build'], 'wordpress']);
        $refreshAstIndexPath = $this->invokePrivate('astIndexPath', [['category' => 'ast-indexed-refresh'], 'wordpress']);
        $runtimeAstCachePath = $this->invokePrivate('astCachePath', [['category' => 'ast-cached'], 'wordpress']);
        $buildAstCachePath = $this->invokePrivate('astCachePath', [['category' => 'ast-cached-build'], 'wordpress']);
        $refreshAstCachePath = $this->invokePrivate('astCachePath', [['category' => 'ast-cached-refresh'], 'wordpress']);

        $this->assertStringContainsString('/build/benchmarks/ast-indexes/runtime/wordpress', $runtimeAstIndexPath);
        $this->assertStringContainsString('/build/benchmarks/ast-indexes/build/wordpress', $buildAstIndexPath);
        $this->assertStringContainsString('/build/benchmarks/ast-indexes/refresh/wordpress', $refreshAstIndexPath);
        $this->assertStringContainsString('/build/benchmarks/ast-caches/runtime/wordpress', $runtimeAstCachePath);
        $this->assertStringContainsString('/build/benchmarks/ast-caches/build/wordpress', $buildAstCachePath);
        $this->assertStringContainsString('/build/benchmarks/ast-caches/refresh/wordpress', $refreshAstCachePath);
        $this->assertNotSame($runtimeAstIndexPath, $buildAstIndexPath);
        $this->assertNotSame($runtimeAstIndexPath, $refreshAstIndexPath);
        $this->assertNotSame($runtimeAstCachePath, $buildAstCachePath);
        $this->assertNotSame($runtimeAstCachePath, $refreshAstCachePath);
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
