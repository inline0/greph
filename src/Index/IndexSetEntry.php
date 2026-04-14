<?php

declare(strict_types=1);

namespace Greph\Index;

final readonly class IndexSetEntry
{
    public function __construct(
        public string $name,
        public string $rootPath,
        public string $indexPath,
        public IndexMode $mode,
        public IndexLifecycle $lifecycle,
        public int $priority = 0,
        public bool $enabled = true,
    ) {
    }
}
