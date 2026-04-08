<?php

declare(strict_types=1);

namespace Phgrep\Support;

final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $durationMs,
    ) {
    }

    public function output(): string
    {
        return $this->stdout . $this->stderr;
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
