<?php

declare(strict_types=1);

namespace Greph\Ast;

use PhpParser\ParserFactory;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * PHP outline extraction matching ast-grep 0.51's bundled PHP rules.
 *
 * @phpstan-type Position array{line: int, column: int}
 * @phpstan-type Range array{byteOffset: array{start: int, end: int}, start: Position, end: Position}
 * @phpstan-type Member array{role: string, symbolType: string, name: string, range: Range, signature: string, astKind: string, isPublic: bool}
 * @phpstan-type Item array{role: string, symbolType: string, name: string, range: Range, signature: string, astKind: string, isImport: bool, isExported: bool, members?: list<Member>}
 */
final class OutlineExtractor
{
    /** @var non-empty-list<int> */
    private array $lineStarts = [0];

    private int $sourceOffset = 0;

    /** @return list<Item> */
    public function extract(string $code, bool $includeMemberSignatures = false): array
    {
        $this->lineStarts = [0];
        $offset = 0;
        while (($newline = strpos($code, "\n", $offset)) !== false) {
            $offset = $newline + 1;
            $this->lineStarts[] = $offset;
        }
        $prefix = str_contains($code, '<?') ? '' : '<?php ';
        $this->sourceOffset = strlen($prefix);
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($prefix . $code, new Collecting()) ?? [];
        $nodes = (new NodeFinder())->find($statements, static fn (Node $node): bool =>
            ($node instanceof Stmt\ClassLike && $node->name !== null)
            || $node instanceof Stmt\Function_
            || $node instanceof Stmt\Use_);
        $items = [];
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Use_) {
                $use = $node->uses[0] ?? null;
                if ($use === null || count($use->name->getParts()) < 2) {
                    continue;
                }
                $name = substr($code, $use->name->getStartFilePos() - $this->sourceOffset, $use->name->getEndFilePos() - $use->name->getStartFilePos() + 1);
                $type = 'module';
                $kind = 'namespace_use_declaration';
                $import = true;
            } elseif ($node instanceof Stmt\Function_) {
                $name = $node->name->toString();
                $type = 'function';
                $kind = 'function_definition';
                $import = false;
            } elseif ($node instanceof Stmt\ClassLike && $node->name !== null) {
                $name = $node->name->toString();
                [$type, $kind] = match (true) {
                    $node instanceof Stmt\Interface_ => ['interface', 'interface_declaration'],
                    $node instanceof Stmt\Trait_ => ['interface', 'trait_declaration'],
                    $node instanceof Stmt\Enum_ => ['enum', 'enum_declaration'],
                    default => ['class', 'class_declaration'],
                };
                $import = false;
            } else {
                continue;
            }
            $signature = $this->signature($node, $code);
            $item = [
                'role' => 'item', 'symbolType' => $type, 'name' => $name,
                'range' => $this->range($node, $code), 'signature' => $signature,
                'astKind' => $kind, 'isImport' => $import, 'isExported' => !$import,
            ];
            if ($node instanceof Stmt\ClassLike) {
                $members = $this->members($node, $code, $includeMemberSignatures);
                if ($members !== []) {
                    $item['members'] = $members;
                }
            }
            $items[] = $item;
        }
        usort($items, static fn (array $a, array $b): int => $a['range']['byteOffset']['start'] <=> $b['range']['byteOffset']['start']);

        return $items;
    }

    /** @return list<Member> */
    private function members(Stmt\ClassLike $parent, string $code, bool $includeSignatures): array
    {
        $members = [];
        foreach ($parent->stmts as $node) {
            if ($node instanceof Stmt\EnumCase && $parent instanceof Stmt\Enum_) {
                $name = $node->name->toString();
                $type = 'enumMember';
                $kind = 'enum_case';
                $public = true;
            } elseif ($node instanceof Stmt\ClassMethod && !$parent instanceof Stmt\Enum_) {
                $name = $node->name->toString();
                $type = 'method';
                $kind = 'method_declaration';
                $public = $parent instanceof Stmt\Interface_ || ($node->flags & Stmt\Class_::MODIFIER_PUBLIC) !== 0;
            } elseif ($node instanceof Stmt\Property && !$parent instanceof Stmt\Enum_ && isset($node->props[0])) {
                $name = '$' . $node->props[0]->name->toString();
                $type = 'property';
                $kind = 'property_declaration';
                $public = ($node->flags & Stmt\Class_::MODIFIER_PUBLIC) !== 0;
            } else {
                continue;
            }
            $members[] = [
                'role' => 'member', 'symbolType' => $type, 'name' => $name,
                'range' => $this->range($node, $code), 'signature' => $includeSignatures ? $this->signature($node, $code) : '',
                'astKind' => $kind, 'isPublic' => $public,
            ];
        }

        return $members;
    }

    private function signature(Node $node, string $code): string
    {
        $start = $node->getStartFilePos() - $this->sourceOffset;
        if (
            $node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Property || $node instanceof Stmt\EnumCase
        ) {
            foreach ($node->attrGroups as $group) {
                $start = max($start, $group->getEndFilePos() + 1 - $this->sourceOffset);
            }
        }
        $source = ltrim(substr($code, $start, $node->getEndFilePos() - $this->sourceOffset - $start + 1));

        return trim(explode("\n", $source, 2)[0]);
    }

    /** @return Range */
    private function range(Node $node, string $code): array
    {
        $start = $node->getStartFilePos() - $this->sourceOffset;
        $end = $node->getEndFilePos() + 1 - $this->sourceOffset;

        return ['byteOffset' => ['start' => $start, 'end' => $end],
            'start' => $this->position($code, $start), 'end' => $this->position($code, $end)];
    }

    /** @return Position */
    private function position(string $code, int $offset): array
    {
        $low = 0;
        $high = count($this->lineStarts);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->lineStarts[$middle] <= $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }
        $line = max(0, $low - 1);
        $text = substr($code, $this->lineStarts[$line], $offset - $this->lineStarts[$line]);
        $column = preg_match_all('/./us', $text);

        return ['line' => $line, 'column' => $column === false ? strlen($text) : $column];
    }
}
