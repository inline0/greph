<?php

declare(strict_types=1);

namespace Greph\Index;

enum IndexLifecycleProfile: string
{
    case Static = 'static';
    case ManualRefresh = 'manual-refresh';
    case OpportunisticRefresh = 'opportunistic-refresh';
    case StrictStaleCheck = 'strict-stale-check';
}
