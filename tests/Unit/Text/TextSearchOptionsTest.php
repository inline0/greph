<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Text;

use Phgrep\Text\TextSearchOptions;
use Phgrep\Walker\FileTypeFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextSearchOptionsTest extends TestCase
{
    #[Test]
    public function itBuildsWalkOptions(): void
    {
        $filter = new FileTypeFilter(['php']);
        $options = new TextSearchOptions(
            jobs: 2,
            includeHidden: true,
            fileTypeFilter: $filter,
            globPatterns: ['*.php'],
        );
        $walkOptions = $options->walkOptions();

        $this->assertTrue($walkOptions->includeHidden);
        $this->assertSame($filter, $walkOptions->fileTypeFilter);
        $this->assertSame(['*.php'], $walkOptions->globPatterns);
    }

    #[Test]
    public function itRejectsInvalidNumericOptions(): void
    {
        try {
            new TextSearchOptions(jobs: 0);
            $this->fail('Expected invalid job count.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Job count', $exception->getMessage());
        }

        try {
            new TextSearchOptions(beforeContext: -1);
            $this->fail('Expected invalid context.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Context values', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);

        new TextSearchOptions(maxCount: 0);
    }
}
