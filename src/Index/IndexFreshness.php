<?php

declare(strict_types=1);

namespace Greph\Index;

final readonly class IndexFreshness
{
    public function __construct(
        public bool $stale,
        public int $addedFiles,
        public int $updatedFiles,
        public int $deletedFiles,
        public int $unchangedFiles,
        public int $changedBytes,
    ) {
    }

    public function changedFileCount(): int
    {
        return $this->addedFiles + $this->updatedFiles + $this->deletedFiles;
    }

    public function isCheapEnough(IndexLifecycle $lifecycle): bool
    {
        if (!$this->stale) {
            return true;
        }

        return $this->changedFileCount() <= $lifecycle->maxChangedFiles
            && $this->changedBytes <= $lifecycle->maxChangedBytes;
    }

    public function summary(): string
    {
        return sprintf(
            '+%d ~%d -%d =%d (%s)',
            $this->addedFiles,
            $this->updatedFiles,
            $this->deletedFiles,
            $this->unchangedFiles,
            $this->stale ? 'stale' : 'fresh',
        );
    }
}
