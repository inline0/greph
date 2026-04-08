<?php

declare(strict_types=1);

namespace Phgrep\Benchmarks;

final readonly class BenchmarkResult
{
    public function __construct(
        public string $category,
        public string $suite,
        public string $operation,
        public string $corpus,
        public string $tool,
        public float $durationMs,
        public int $memoryBytes,
        public int $fileCount,
        public int $matchCount,
        public bool $skipped = false,
        public ?string $skipReason = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'suite' => $this->suite,
            'operation' => $this->operation,
            'corpus' => $this->corpus,
            'tool' => $this->tool,
            'duration_ms' => $this->durationMs,
            'memory_bytes' => $this->memoryBytes,
            'file_count' => $this->fileCount,
            'match_count' => $this->matchCount,
            'skipped' => $this->skipped,
            'skip_reason' => $this->skipReason,
        ];
    }
}
