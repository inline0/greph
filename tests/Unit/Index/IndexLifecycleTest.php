<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Index;

use Greph\Index\IndexLifecycle;
use Greph\Index\IndexLifecycleProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexLifecycleTest extends TestCase
{
    #[Test]
    public function itNormalizesAndSerializesLifecycleProfiles(): void
    {
        $default = IndexLifecycle::normalize(null);
        $profile = IndexLifecycle::normalize(IndexLifecycleProfile::Static);
        $string = IndexLifecycle::normalize('opportunistic-refresh');
        $metadata = IndexLifecycle::fromMetadata([
            'lifecycle' => 'strict-stale-check',
            'maxChangedFiles' => 9,
            'maxChangedBytes' => 2048,
        ]);
        $fallback = IndexLifecycle::fromMetadata(['lifecycle' => 'bogus']);

        $this->assertSame(IndexLifecycleProfile::ManualRefresh, $default->profile);
        $this->assertSame(IndexLifecycleProfile::Static, $profile->profile);
        $this->assertFalse($profile->shouldInspectFreshness());
        $this->assertSame(IndexLifecycleProfile::OpportunisticRefresh, $string->profile);
        $this->assertTrue($string->shouldAutoRefresh());
        $this->assertSame(IndexLifecycleProfile::StrictStaleCheck, $metadata->profile);
        $this->assertTrue($metadata->shouldRejectStale());
        $this->assertSame(9, $metadata->maxChangedFiles);
        $this->assertSame(2048, $metadata->maxChangedBytes);
        $this->assertSame([
            'lifecycle' => 'strict-stale-check',
            'maxChangedFiles' => 9,
            'maxChangedBytes' => 2048,
        ], $metadata->toMetadata());
        $this->assertSame(IndexLifecycleProfile::ManualRefresh, $fallback->profile);
    }
}
