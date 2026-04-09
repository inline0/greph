<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Ast;

use Phgrep\Ast\AstSearchOptions;
use Phgrep\Walker\FileTypeFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstSearchOptionsTest extends TestCase
{
    #[Test]
    public function itBuildsWalkOptions(): void
    {
        $filter = new FileTypeFilter(['php']);
        $options = new AstSearchOptions(
            jobs: 2,
            includeHidden: true,
            fileTypeFilter: $filter,
            globPatterns: ['src/*.php'],
        );
        $walkOptions = $options->walkOptions();

        $this->assertTrue($walkOptions->includeHidden);
        $this->assertSame($filter, $walkOptions->fileTypeFilter);
        $this->assertSame(['src/*.php'], $walkOptions->globPatterns);
    }

    #[Test]
    public function itRejectsInvalidJobCounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AstSearchOptions(jobs: 0);
    }
}
