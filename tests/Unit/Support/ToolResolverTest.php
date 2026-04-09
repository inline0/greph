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
        $resolver = new ToolResolver();

        $this->assertNotSame([], $resolver->grep());
        $this->assertNotSame([], $resolver->ripgrep());
        $this->assertSame([PHP_BINARY], $resolver->phpBinary());
        $this->assertSame([PHP_BINARY, '/tmp/project/bin/phgrep'], $resolver->phgrep('/tmp/project'));
        $this->assertNotSame([], $resolver->astGrep());
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
}
