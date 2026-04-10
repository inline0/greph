<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use PhpParser\NodeAbstract;

final class StoredNode extends NodeAbstract
{
    /**
     * @param non-empty-string $type
     */
    public function __construct(
        private readonly string $type,
        int $startLine,
        int $endLine,
        int $startFilePos,
        int $endFilePos,
    ) {
        parent::__construct([
            'startLine' => $startLine,
            'endLine' => $endLine,
            'startFilePos' => $startFilePos,
            'endFilePos' => $endFilePos,
        ]);
    }

    public function getType(): string
    {
        /** @var non-empty-string $type */
        $type = $this->type;

        return $type;
    }

    public function getSubNodeNames(): array
    {
        return [];
    }
}
