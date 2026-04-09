<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Parallel;

final class WorkerTermination extends \RuntimeException
{
    public function __construct(public readonly int $exitCode)
    {
        parent::__construct(sprintf('Worker terminated with exit code %d.', $exitCode));
    }
}
