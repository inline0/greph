<?php

declare(strict_types=1);

namespace Greph\Tests\Integration;

use Greph\Tests\Support\Workspace;
use Greph\Walker\FileWalker;
use Greph\Walker\GitExcludeConfig;
use Greph\Walker\WalkOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpstreamIgnoreTest extends TestCase
{
    private string $workspace;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('ignore-152');
        foreach (['GIT_CONFIG_GLOBAL', 'GIT_CONFIG_SYSTEM', 'XDG_CONFIG_HOME'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        putenv('XDG_CONFIG_HOME=' . $this->workspace . '/xdg');
        Workspace::writeFile($this->workspace, 'repo/.git/info/exclude', "local.txt\n");
        Workspace::writeFile($this->workspace, 'repo/.gitignore', "*.cache\n!keep.cache\n!local.txt\n");
        foreach (['a/drop.cache', 'a/keep.cache', 'a/global.txt', 'a/local.txt', 'b/drop.cache', 'b/keep.cache', 'b/global.txt'] as $file) {
            Workspace::writeFile($this->workspace, 'repo/' . $file, "needle\n");
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itUsesGlobalConfigAndParentIgnoresForMultipleRoots(): void
    {
        Workspace::writeFile($this->workspace, 'global.ignore', "global.txt\n");
        Workspace::writeFile($this->workspace, 'global.config', "[core]\n excludesFile = \"{$this->workspace}/global.ignore\"\n");
        putenv('GIT_CONFIG_GLOBAL=' . $this->workspace . '/global.config');
        $paths = [$this->workspace . '/repo/a', $this->workspace . '/repo/b'];
        $files = (new FileWalker())->walk($paths)->paths();
        $actual = array_map(fn (string $file): string => substr($file, strlen($this->workspace . '/repo/')), $files);
        sort($actual);
        self::assertSame(['a/keep.cache', 'a/local.txt', 'b/keep.cache'], $actual);
        self::assertCount(7, (new FileWalker())->walk($paths, new WalkOptions(respectIgnore: false))->paths());
    }

    #[Test]
    public function explicitlyNamedIgnoredDirectoriesStillApplyFileRules(): void
    {
        Workspace::writeFile($this->workspace, 'repo/.gitignore', "a/\n*.cache\n!keep.cache\n");
        $files = (new FileWalker())->walk($this->workspace . '/repo/a')->paths();
        self::assertContains($this->workspace . '/repo/a/keep.cache', $files);
        self::assertContains($this->workspace . '/repo/a/global.txt', $files);
        self::assertNotContains($this->workspace . '/repo/a/drop.cache', $files);
    }

    #[Test]
    public function itResolvesSystemFallbackAfterUnconfiguredFiles(): void
    {
        Workspace::writeFile($this->workspace, 'system.ignore', "global.txt\n");
        Workspace::writeFile($this->workspace, 'system.config', "[core]\n excludesFile = {$this->workspace}/system.ignore\n");
        putenv('GIT_CONFIG_GLOBAL=/dev/null');
        putenv('GIT_CONFIG_SYSTEM=' . $this->workspace . '/system.config');
        // Explicitly exercise fallback without depending on a user's HOME config.
        self::assertSame($this->workspace . '/system.ignore', GitExcludeConfig::fromFiles(
            ['/dev/null', $this->workspace . '/xdg/git/config', $this->workspace . '/system.config'],
            null,
            null,
        ));
    }
}
