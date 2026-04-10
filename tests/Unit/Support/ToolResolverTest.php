<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Support;

use Greph\Support\ToolResolver;
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
        $this->assertSame([PHP_BINARY, '/tmp/project/bin/greph'], $resolver->greph('/tmp/project'));
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

    #[Test]
    public function itPrefersTheGrephEntryPointWhenItExists(): void
    {
        $workspace = sys_get_temp_dir() . '/greph-tool-resolver-' . bin2hex(random_bytes(4));

        mkdir($workspace . '/bin', 0777, true);
        file_put_contents($workspace . '/bin/greph', "#!/usr/bin/env php\n");

        try {
            $resolver = new ToolResolver(static fn (string $candidate): ?string => null);
            $this->assertSame([PHP_BINARY, $workspace . '/bin/greph'], $resolver->greph($workspace));
        } finally {
            @unlink($workspace . '/bin/greph');
            @rmdir($workspace . '/bin');
            @rmdir($workspace);
        }
    }
}
