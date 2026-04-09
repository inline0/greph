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
        public ?float $durationMinMs = null,
        public ?float $durationMaxMs = null,
        public ?int $sampleCount = null,
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
            'duration_min_ms' => $this->durationMinMs,
            'duration_max_ms' => $this->durationMaxMs,
            'sample_count' => $this->sampleCount,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            category: (string) $data['category'],
            suite: (string) $data['suite'],
            operation: (string) $data['operation'],
            corpus: (string) $data['corpus'],
            tool: (string) $data['tool'],
            durationMs: (float) $data['duration_ms'],
            memoryBytes: (int) $data['memory_bytes'],
            fileCount: (int) $data['file_count'],
            matchCount: (int) $data['match_count'],
            skipped: (bool) $data['skipped'],
            skipReason: isset($data['skip_reason']) ? (string) $data['skip_reason'] : null,
            durationMinMs: isset($data['duration_min_ms']) ? (float) $data['duration_min_ms'] : null,
            durationMaxMs: isset($data['duration_max_ms']) ? (float) $data['duration_max_ms'] : null,
            sampleCount: isset($data['sample_count']) ? (int) $data['sample_count'] : null,
        );
    }
}
