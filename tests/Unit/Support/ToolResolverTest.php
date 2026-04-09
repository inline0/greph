<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Support;

use Phgrep\Support\ToolResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolResolverTest extends TestCase
{
    #[Test]
    public function itResolvesConfiguredToolCommands(): void
    {
        $resolver = new ToolResolver(
            static fn (string $candidate): ?string => match ($candidate) {
                'grep' => '/mock/grep',
                'rg' => '/mock/rg',
                'gh' => '/mock/gh',
                'sg' => '/mock/sg',
                default => null,
            },
        );

        $this->assertSame(['/mock/grep'], $resolver->grep());
        $this->assertSame(['/mock/rg'], $resolver->ripgrep());
        $this->assertSame(['/mock/gh'], $resolver->githubCli());
        $this->assertSame([PHP_BINARY], $resolver->phpBinary());
        $this->assertSame([PHP_BINARY, '/tmp/project/bin/phgrep'], $resolver->phgrep('/tmp/project'));
        $this->assertSame(['/mock/sg'], $resolver->astGrep());
        $this->assertTrue($resolver->hasAstGrep());
    }

    #[Test]
    public function itSupportsMockedResolutionFailuresAndFallbacks(): void
    {
        $resolver = new ToolResolver(
            static fn (string $candidate): ?string => match ($candidate) {
                'sg' => '/mock/sg',
                default => null,
            },
        );
        $npmFallback = new ToolResolver(
            static fn (string $candidate): ?string => match ($candidate) {
                'npm' => '/mock/npm',
                default => null,
            },
        );
        $missing = new ToolResolver(static fn (string $candidate): ?string => null);

        $this->assertSame(['/mock/sg'], $resolver->astGrep());
        $this->assertSame(['/mock/gh'], (new ToolResolver(
            static fn (string $candidate): ?string => match ($candidate) {
                'gh' => '/mock/gh',
                default => null,
            },
        ))->githubCli());
        $this->assertSame(['/mock/npm', 'exec', '--yes', '--package=@ast-grep/cli', 'sg', '--'], $npmFallback->astGrep());
        $this->assertFalse($missing->hasAstGrep());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to find ast-grep. Install sg or npm.');
        $missing->astGrep();
    }

    #[Test]
    public function itThrowsWhenRequiredBinariesAreMissing(): void
    {
        $resolver = new ToolResolver(static fn (string $candidate): ?string => null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve required binary: grep');
        $resolver->grep();
    }

    #[Test]
    public function itCanUseTheDefaultBinaryLocatorForSystemGrep(): void
    {
        $resolver = new ToolResolver();
        $command = $resolver->grep();

        $this->assertCount(1, $command);
        $this->assertNotSame('', $command[0]);
    }

    #[Test]
    public function itNormalizesShellLookupResults(): void
    {
        $reflection = new \ReflectionMethod(ToolResolver::class, 'normalizeLocatedPath');
        $reflection->setAccessible(true);

        $this->assertNull($reflection->invoke(null, null));
        $this->assertNull($reflection->invoke(null, " \n"));
        $this->assertSame('/mock/bin', $reflection->invoke(null, " /mock/bin \n"));
    }
}
