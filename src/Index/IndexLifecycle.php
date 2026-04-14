<?php

declare(strict_types=1);

namespace Greph\Index;

final readonly class IndexLifecycle
{
    public const DEFAULT_MAX_CHANGED_FILES = 32;

    public const DEFAULT_MAX_CHANGED_BYTES = 1048576;

    public function __construct(
        public IndexLifecycleProfile $profile = IndexLifecycleProfile::ManualRefresh,
        public int $maxChangedFiles = self::DEFAULT_MAX_CHANGED_FILES,
        public int $maxChangedBytes = self::DEFAULT_MAX_CHANGED_BYTES,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        $profile = IndexLifecycleProfile::tryFrom((string) ($metadata['lifecycle'] ?? ''))
            ?? IndexLifecycleProfile::ManualRefresh;
        $maxChangedFiles = is_int($metadata['maxChangedFiles'] ?? null)
            ? $metadata['maxChangedFiles']
            : self::DEFAULT_MAX_CHANGED_FILES;
        $maxChangedBytes = is_int($metadata['maxChangedBytes'] ?? null)
            ? $metadata['maxChangedBytes']
            : self::DEFAULT_MAX_CHANGED_BYTES;

        return new self(
            profile: $profile,
            maxChangedFiles: max(0, $maxChangedFiles),
            maxChangedBytes: max(0, $maxChangedBytes),
        );
    }

    public static function normalize(self|IndexLifecycleProfile|string|null $lifecycle): self
    {
        if ($lifecycle instanceof self) {
            return $lifecycle;
        }

        if ($lifecycle instanceof IndexLifecycleProfile) {
            return new self($lifecycle);
        }

        if (is_string($lifecycle) && $lifecycle !== '') {
            $profile = IndexLifecycleProfile::tryFrom($lifecycle);

            if ($profile !== null) {
                return new self($profile);
            }
        }

        return new self();
    }

    /**
     * @return array{lifecycle: string, maxChangedFiles: int, maxChangedBytes: int}
     */
    public function toMetadata(): array
    {
        return [
            'lifecycle' => $this->profile->value,
            'maxChangedFiles' => $this->maxChangedFiles,
            'maxChangedBytes' => $this->maxChangedBytes,
        ];
    }

    public function shouldInspectFreshness(): bool
    {
        return $this->profile !== IndexLifecycleProfile::Static;
    }

    public function shouldAutoRefresh(): bool
    {
        return $this->profile === IndexLifecycleProfile::OpportunisticRefresh;
    }

    public function shouldRejectStale(): bool
    {
        return $this->profile === IndexLifecycleProfile::StrictStaleCheck;
    }

    public function label(): string
    {
        return $this->profile->value;
    }
}
