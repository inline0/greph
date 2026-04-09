<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/**
 * @property-read string $code
 */
final class AstMatch
{
    private static ?Standard $printer = null;

    private ?string $materializedCode;

    /**
     * @param array<string, mixed> $captures
     */
    public function __construct(
        public string $file,
        public Node $node,
        public array $captures,
        public int $startLine,
        public int $endLine,
        public int $startFilePos,
        public int $endFilePos,
        ?string $code = null,
        private ?AstSourceBuffer $sourceBuffer = null,
    ) {
        $this->materializedCode = $code;
    }

    public function code(): string
    {
        if ($this->materializedCode !== null) {
            return $this->materializedCode;
        }

        $this->materializedCode = $this->sourceBuffer !== null && $this->endFilePos >= $this->startFilePos
            ? $this->sourceBuffer->slice($this->startFilePos, $this->endFilePos)
            : $this->renderNode();

        return $this->materializedCode;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'code') {
            return $this->code();
        }

        throw new \LogicException(sprintf('Undefined property: %s::$%s', self::class, $name));
    }

    public function __isset(string $name): bool
    {
        return $name === 'code';
    }

    /**
     * @return array{
     *   file: string,
     *   node: Node,
     *   captures: array<string, mixed>,
     *   startLine: int,
     *   endLine: int,
     *   startFilePos: int,
     *   endFilePos: int,
     *   code: string
     * }
     */
    public function __serialize(): array
    {
        return [
            'file' => $this->file,
            'node' => $this->node,
            'captures' => $this->captures,
            'startLine' => $this->startLine,
            'endLine' => $this->endLine,
            'startFilePos' => $this->startFilePos,
            'endFilePos' => $this->endFilePos,
            'code' => $this->code(),
        ];
    }

    /**
     * @param array{
     *   file: string,
     *   node: Node,
     *   captures: array<string, mixed>,
     *   startLine: int,
     *   endLine: int,
     *   startFilePos: int,
     *   endFilePos: int,
     *   code: string
     * } $data
     */
    public function __unserialize(array $data): void
    {
        $this->file = $data['file'];
        $this->node = $data['node'];
        $this->captures = $data['captures'];
        $this->startLine = $data['startLine'];
        $this->endLine = $data['endLine'];
        $this->startFilePos = $data['startFilePos'];
        $this->endFilePos = $data['endFilePos'];
        $this->materializedCode = $data['code'];
        $this->sourceBuffer = null;
    }

    private function renderNode(): string
    {
        $printer = self::$printer ??= new Standard();

        return $this->node instanceof Node\Expr
            ? $printer->prettyPrintExpr($this->node)
            : $printer->prettyPrint([$this->node]);
    }
}
