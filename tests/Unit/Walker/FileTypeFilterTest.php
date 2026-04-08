<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Walker;

use Phgrep\Walker\FileTypeFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileTypeFilterTest extends TestCase
{
    #[Test]
    public function itMatchesIncludedTypesAndExcludesOverrides(): void
    {
        $filter = new FileTypeFilter(['php', 'json'], ['json']);

        $this->assertTrue($filter->matches('/tmp/src/App.php'));
        $this->assertFalse($filter->matches('/tmp/data/schema.json'));
        $this->assertFalse($filter->matches('/tmp/README.md'));
    }

    #[Test]
    public function itTreatsUnknownTypesAsExtensions(): void
    {
        $filter = new FileTypeFilter(['blade.php', 'stub']);

        $this->assertTrue($filter->matches('/tmp/views/index.stub'));
        $this->assertFalse($filter->matches('/tmp/views/index.php'));
    }
}
