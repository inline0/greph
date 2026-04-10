<?php

declare(strict_types=1);

namespace Greph\Text;

final readonly class BufferedLine
{
    public function __construct(
        public int $number,
        public string $content,
    ) {
    }
}
