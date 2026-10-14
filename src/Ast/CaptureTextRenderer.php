<?php

declare(strict_types=1);

namespace Greph\Ast;

use PhpParser\Node;

/** Splices captured source bytes into a parsed rewrite template. */
final class CaptureTextRenderer
{
    /** @return list<string> */
    public static function captureNames(Node $root): array
    {
        $names = [];
        self::visit($root, static function (Node $node) use (&$names): bool {
            $name = MetaVariable::variadicName($node) ?? MetaVariable::singleName($node);
            if ($name === null) {
                return false;
            }
            $names[$name] = true;
            return true;
        });

        return array_keys($names);
    }

    /**
     * @param array<string, mixed> $captures
     * @param array<string, array{parent: Node, field: string, offset: int, length: int}> $lists
     */
    public function render(
        Pattern $template,
        string $templateCode,
        array $captures,
        array $lists,
        string $source,
        Node $candidate,
        bool $parenthesize = false,
    ): string {
        $sourceOffset = self::prefixLength($source);
        $templateSource = (self::prefixLength($templateCode) === 0 ? '' : '<?php ') . $templateCode;
        $start = $template->root->getStartFilePos();
        $end = $template->root->getEndFilePos() + 1;
        $changes = [];
        $covered = [];
        $commasToRemove = [];
        $constructors = [];

        self::visit($template->root, function (
            Node $node,
            ?Node $parent,
            ?string $field,
            ?int $index,
        ) use (
            $captures,
            $lists,
            $source,
            $candidate,
            $sourceOffset,
            $templateSource,
            $parenthesize,
            &$changes,
            &$covered,
            &$commasToRemove,
            &$constructors
        ): bool {
            $variadic = MetaVariable::variadicName($node);
            $name = $variadic ?? MetaVariable::singleName($node);
            if ($name === null) {
                return false;
            }
            $capture = $captures[$name] ?? null;
            if ($variadic !== null) {
                if (!is_array($capture) || !isset($lists[$name])) {
                    throw new \RuntimeException('Missing source range for metavariable: ' . $name);
                }
                $origin = $lists[$name];
                [$open, $close, $commas] = self::listBounds($origin['parent'], $origin['field'], $source, $sourceOffset);
                /** @var array<array-key, mixed> $items */
                $items = $origin['parent']->{$origin['field']};
                if ($capture === [] && $items !== []) {
                    $from = $to = $open + 1;
                } else {
                    $from = $origin['offset'] === 0 ? $open + 1
                        : ($commas[$origin['offset'] - 1]
                            ?? throw new \RuntimeException('Cannot locate the captured list start.')) + 1;
                    $last = $origin['offset'] + $origin['length'];
                    $to = $last >= count($items) ? $close
                        : ($commas[max(0, $last - 1)]
                            ?? throw new \RuntimeException('Cannot locate the captured list end.'));
                }
                $text = substr($source, $from, max(0, $to - $from));
                $covered[] = [$from, $to];

                if ($parent !== null && $field !== null && $index !== null && $index >= 0) {
                    /** @var array<array-key, mixed> $siblings */
                    $siblings = $parent->$field;
                    [, , $templateCommas] = self::listBounds($parent, $field, $templateSource, 0);
                    if ($capture === []) {
                        $comma = $templateCommas[$index] ?? ($index > 0 ? $templateCommas[$index - 1] ?? null : null);
                        if ($comma !== null) {
                            $commasToRemove[$comma] = true;
                        }
                    } elseif ($index < count($siblings) - 1) {
                        $trailing = $commas[count($items) - 1] ?? null;
                        if ($trailing !== null && $trailing >= $from && $trailing < $to) {
                            $text = substr_replace($text, '', $trailing - $from, 1);
                        }
                    }
                }
            } elseif ($capture instanceof Node) {
                $from = $capture->getStartFilePos() - $sourceOffset;
                $to = $capture->getEndFilePos() - $sourceOffset + 1;
                $text = substr($source, $from, $to - $from);
                if ($capture instanceof Node\Stmt\Class_ && $parent instanceof Node\Expr\New_ && $field === 'class') {
                    $original = self::constructorForClass($candidate, $capture);
                    if ($original === null) {
                        throw new \RuntimeException('Cannot locate the captured anonymous class constructor.');
                    }
                    [$open, $close] = self::listBounds($original, 'args', $source, $sourceOffset);
                    if ($close > $open && ($source[$open] ?? '') === '(') {
                        $covered[] = [$from, $open];
                        $covered[] = [$close + 1, $to];
                        $argumentOffset = $open - $from;
                        $argumentLength = $close - $open + 1;
                    } else {
                        $covered[] = [$from, $to];
                        $argumentOffset = self::classKeywordEnd($text);
                        $argumentLength = 0;
                    }
                    $constructors[] = [
                        'start' => $parent->getStartFilePos(),
                        'end' => $parent->getEndFilePos() + 1,
                        'class_start' => $node->getStartFilePos(),
                        'args_start' => $node->getEndFilePos() + 1,
                        'text' => $text,
                        'argument_offset' => $argumentOffset,
                        'argument_length' => $argumentLength,
                    ];
                } else {
                    $covered[] = [$from, $to];
                }
                if (
                    $parenthesize && $capture instanceof Node\Expr
                    && !$capture instanceof Node\Expr\Variable && !$capture instanceof Node\Scalar
                    && !$capture instanceof Node\Expr\ConstFetch
                ) {
                    $text = '(' . $text . ')';
                }
            } else {
                throw new \RuntimeException('Metavariable is not bound: ' . $name);
            }
            $changes[] = [$node->getStartFilePos(), $node->getEndFilePos() + 1, $text];
            return true;
        });

        foreach ($commasToRemove as $comma => $_) {
            $changes[] = [$comma, $comma + 1, ''];
        }
        usort($constructors, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        foreach ($constructors as $constructor) {
            $arguments = self::splice($templateSource, $constructor['args_start'], $constructor['end'], $changes);
            $prefix = substr($templateSource, $constructor['start'], $constructor['class_start'] - $constructor['start']);
            $replacement = $prefix . substr_replace(
                $constructor['text'],
                $arguments,
                $constructor['argument_offset'],
                $constructor['argument_length'],
            );
            $changes = array_values(array_filter(
                $changes,
                static fn (array $change): bool => $change[0] < $constructor['start'] || $change[1] > $constructor['end'],
            ));
            $changes[] = [$constructor['start'], $constructor['end'], $replacement];
        }
        $rendered = self::splice($templateSource, $start, $end, $changes);

        $comments = '';
        $candidateStart = $candidate->getStartFilePos() - $sourceOffset;
        $candidateEnd = $candidate->getEndFilePos() - $sourceOffset + 1;
        foreach (self::tokens(substr($source, $candidateStart, $candidateEnd - $candidateStart), $candidateStart) as $token) {
            if (!in_array($token['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $captured = false;
            foreach ($covered as [$from, $to]) {
                if ($token['offset'] >= $from && $token['offset'] + strlen($token['text']) <= $to) {
                    $captured = true;
                    break;
                }
            }
            if (!$captured) {
                $comments .= $token['text'] . (str_starts_with($token['text'], '//')
                    || str_starts_with($token['text'], '#') ? "\n" : ' ');
            }
        }

        return $comments . $rendered;
    }

    /** @param list<array{int, int, string}> $changes */
    private static function splice(string $source, int $start, int $end, array $changes): string
    {
        usort($changes, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $rendered = substr($source, $start, $end - $start);
        foreach ($changes as [$from, $to, $text]) {
            if ($from >= $start && $to <= $end) {
                $rendered = substr_replace($rendered, $text, $from - $start, $to - $from);
            }
        }

        return $rendered;
    }

    private static function constructorForClass(Node $root, Node\Stmt\Class_ $class): ?Node\Expr\New_
    {
        if ($root instanceof Node\Expr\New_ && $root->class === $class) {
            return $root;
        }
        foreach ($root->getSubNodeNames() as $field) {
            $value = $root->$field;
            $children = $value instanceof Node ? [$value] : (is_array($value) ? $value : []);
            foreach ($children as $child) {
                if ($child instanceof Node && ($found = self::constructorForClass($child, $class)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private static function classKeywordEnd(string $source): int
    {
        $previous = null;
        foreach (self::tokens($source, 0) as $token) {
            if (in_array($token['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($token['id'] === T_CLASS && $previous !== T_DOUBLE_COLON) {
                return $token['offset'] + strlen($token['text']);
            }
            $previous = $token['id'];
        }

        throw new \RuntimeException('Cannot locate the captured class keyword.');
    }

    public static function prefixLength(string $source): int
    {
        return preg_match('/^[ \t\r\n\f\v]*<\?(?:php|=)/', $source) === 1 ? 0 : 6;
    }

    /**
     * @param callable(Node, ?Node, ?string, ?int): bool $visitor
     */
    private static function visit(Node $node, callable $visitor, ?Node $parent = null, ?string $field = null, ?int $index = null): void
    {
        if ($visitor($node, $parent, $field, $index)) {
            return;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            if ($value instanceof Node) {
                self::visit($value, $visitor, $node, $name);
            } elseif (is_array($value)) {
                foreach ($value as $offset => $child) {
                    if ($child instanceof Node) {
                        self::visit($child, $visitor, $node, $name, is_int($offset) ? $offset : null);
                    }
                }
            }
        }
    }

    /** @return list<array{id: int|null, text: string, offset: int}> */
    private static function tokens(string $source, int $offset): array
    {
        $tokens = [];
        $position = $offset - 6;
        foreach (token_get_all('<?php ' . $source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if ($position >= $offset) {
                $tokens[] = ['id' => is_array($token) ? $token[0] : null, 'text' => $text, 'offset' => $position];
            }
            $position += strlen($text);
        }
        return $tokens;
    }

    /** @return array{int, int, list<int>} */
    private static function listBounds(Node $parent, string $field, string $source, int $sourceOffset): array
    {
        $start = $parent->getStartFilePos() - $sourceOffset;
        $end = $parent->getEndFilePos() - $sourceOffset;
        $tokens = self::tokens(substr($source, $start, $end - $start + 1), $start);
        /** @var list<array{string, int}> $stack */
        $stack = [];
        /** @var list<array{string, int, int}> $pairs */
        $pairs = [];
        $functionPosition = null;
        foreach ($tokens as $token) {
            if (in_array($token['id'], [T_FUNCTION, T_FN, T_CLASS], true) && $functionPosition === null) {
                $functionPosition = $token['offset'];
            }
            if (in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $stack[] = ['{', $token['offset']];
            } elseif ($token['id'] === T_ATTRIBUTE) {
                $stack[] = ['[', $token['offset'] + 1];
            }
            if ($token['id'] !== null) {
                continue;
            }
            if (in_array($token['text'], ['(', '[', '{'], true)) {
                $stack[] = [$token['text'], $token['offset']];
            } elseif (in_array($token['text'], [')', ']', '}'], true) && $stack !== []) {
                $opening = match ($token['text']) {
                    ')' => '(', ']' => '[', default => '{'
                };
                if ($stack[count($stack) - 1][0] === $opening) {
                    [$kind, $open] = array_pop($stack);
                    $pairs[] = [$kind, $open, $token['offset']];
                }
            }
        }
        /** @var array<array-key, mixed> $items */
        $items = $parent->$field;
        $nodes = array_values(array_filter($items, static fn (mixed $item): bool => $item instanceof Node));
        if ($field === 'args' && $items === [] && $parent instanceof Node\Expr\New_) {
            $hasArguments = false;
            if ($parent->class instanceof Node\Stmt\Class_) {
                $afterClass = false;
                $previousId = null;
                foreach ($tokens as $token) {
                    if (in_array($token['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if ($afterClass) {
                        $hasArguments = $token['text'] === '(';
                        break;
                    }
                    if ($token['id'] === T_CLASS && $previousId !== T_DOUBLE_COLON) {
                        $afterClass = true;
                        $functionPosition = $token['offset'];
                    }
                    $previousId = $token['id'];
                }
            } else {
                foreach ($pairs as [$kind, $open, $close]) {
                    if ($kind === '(' && $open > $parent->class->getEndFilePos() - $sourceOffset && $close === $end) {
                        $hasArguments = true;
                    }
                }
            }
            if (!$hasArguments) {
                return [$end, $end, []];
            }
        }
        /** @var list<array{int, int}> $eligible */
        $eligible = [];
        foreach ($pairs as [$kind, $open, $close]) {
            if (($field === 'items' && !in_array($kind, ['(', '['], true)) || ($field !== 'items' && $kind !== '(')) {
                continue;
            }
            if ($nodes !== []) {
                if (
                    $open < $nodes[0]->getStartFilePos() - $sourceOffset
                    && $close > $nodes[count($nodes) - 1]->getEndFilePos() - $sourceOffset
                ) {
                    $eligible[] = [$open, $close];
                }
            } elseif ($field === 'params' && $functionPosition !== null && $open > $functionPosition) {
                $eligible[] = [$open, $close];
            } elseif ($close === $end) {
                $eligible[] = [$open, $close];
            } elseif (
                $field === 'args' && $parent instanceof Node\Expr\New_
                && $parent->class instanceof Node\Stmt\Class_ && $functionPosition !== null && $open > $functionPosition
            ) {
                $eligible[] = [$open, $close];
            }
        }
        if ($eligible === []) {
            throw new \RuntimeException('Cannot locate captured list delimiters.');
        }
        if ($nodes !== []) {
            usort($eligible, static fn (array $a, array $b): int => ($a[1] - $a[0]) <=> ($b[1] - $b[0]));
        } else {
            usort($eligible, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        }
        [$open, $close] = $eligible[0];
        $depth = 0;
        $commas = [];
        foreach ($tokens as $token) {
            if ($token['offset'] <= $open || $token['offset'] >= $close) {
                continue;
            }
            if (in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif ($token['id'] === T_ATTRIBUTE) {
                $depth++;
            }
            if ($token['id'] !== null) {
                continue;
            }
            if (in_array($token['text'], ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token['text'], [')', ']', '}'], true)) {
                $depth--;
            } elseif ($token['text'] === ',' && $depth === 0) {
                $commas[] = $token['offset'];
            }
        }
        return [$open, $close, $commas];
    }
}
