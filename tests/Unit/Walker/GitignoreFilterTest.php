<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Walker;

use Phgrep\Tests\Support\Workspace;
use Phgrep\Walker\GitignoreFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GitignoreFilterTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('gitignore-filter');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itLoadsRootIgnoreFilesAndNegationRules(): void
    {
        Workspace::writeFile($this->workspace, '.gitignore', "*.log\n!important.log\nvendor/\n");
        Workspace::writeFile($this->workspace, '.phgrepignore', "cache/\n");
        Workspace::writeFile($this->workspace, '.git/info/exclude', "local.php\n");

        $filter = new GitignoreFilter($this->workspace);

        $this->assertTrue($filter->shouldIgnore($this->workspace . '/debug.log', false));
        $this->assertFalse($filter->shouldIgnore($this->workspace . '/important.log', false));
        $this->assertTrue($filter->shouldIgnore($this->workspace . '/vendor', true));
        $this->assertTrue($filter->shouldIgnore($this->workspace . '/vendor/lib.php', false));
        $this->assertTrue($filter->shouldIgnore($this->workspace . '/cache', true));
        $this->assertTrue($filter->shouldIgnore($this->workspace . '/local.php', false));
    }

    #[Test]
    public function itAppliesNestedIgnoreFilesRelativeToTheirDirectory(): void
    {
        Workspace::writeFile($this->workspace, 'src/.gitignore', "*.cache\n!keep.cache\n");

        $filter = new GitignoreFilter($this->workspace);

        $this->assertTrue($filter->shouldIgnore($this->workspace . '/src/data.cache', false));
        $this->assertFalse($filter->shouldIgnore($this->workspace . '/src/keep.cache', false));
        $this->assertFalse($filter->shouldIgnore($this->workspace . '/other/data.cache', false));
    }
}
