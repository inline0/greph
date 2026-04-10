<?php

declare(strict_types=1);

namespace Greph\Ast;

use PhpParser\Node;

final class AstMatchCodec
{
    private const NODE_MARKER = '__node';

    private const ARRAY_MARKER = '__array';

    /**
     * @param list<AstMatch> $matches
     * @return list<array{
     *   f: string,
     *   t: string,
     *   sl: int,
     *   el: int,
     *   sp: int,
     *   ep: int,
     *   c: string,
     *   p: array<string, mixed>
     * }>
     */
    public function encode(array $matches): array
    {
        $encoded = [];

        foreach ($matches as $match) {
            $encoded[] = [
                'f' => $match->file,
                't' => $match->node->getType(),
                'sl' => $match->startLine,
                'el' => $match->endLine,
                'sp' => $match->startFilePos,
                'ep' => $match->endFilePos,
                'c' => $match->code,
                'p' => $this->encodeCaptureArray($match->captures),
            ];
        }

        return $encoded;
    }

    /**
     * @param mixed $payload
     * @return list<AstMatch>
     */
    public function decode(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new \RuntimeException('AST query cache is corrupt.');
        }

        $matches = [];

        foreach ($payload as $entry) {
            if (
                !is_array($entry)
                || !is_string($entry['f'] ?? null)
                || !is_string($entry['t'] ?? null)
                || !is_int($entry['sl'] ?? null)
                || !is_int($entry['el'] ?? null)
                || !is_int($entry['sp'] ?? null)
                || !is_int($entry['ep'] ?? null)
                || !is_string($entry['c'] ?? null)
                || !is_array($entry['p'] ?? null)
            ) {
                throw new \RuntimeException('AST query cache is corrupt.');
            }

            $matches[] = new AstMatch(
                file: $entry['f'],
                node: new StoredNode($this->nonEmptyString($entry['t']), $entry['sl'], $entry['el'], $entry['sp'], $entry['ep']),
                captures: $this->decodeCaptureArray($entry['p']),
                startLine: $entry['sl'],
                endLine: $entry['el'],
                startFilePos: $entry['sp'],
                endFilePos: $entry['ep'],
                code: $entry['c'],
            );
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $captures
     * @return array<string, mixed>
     */
    private function encodeCaptureArray(array $captures): array
    {
        $encoded = [];

        foreach ($captures as $name => $value) {
            $encoded[$name] = $this->encodeValue($value);
        }

        return $encoded;
    }

    /**
     * @param array<string, mixed> $captures
     * @return array<string, mixed>
     */
    private function decodeCaptureArray(array $captures): array
    {
        $decoded = [];

        foreach ($captures as $name => $value) {
            if (!is_string($name)) {
                throw new \RuntimeException('AST query cache is corrupt.');
            }

            $decoded[$name] = $this->decodeValue($value);
        }

        return $decoded;
    }

    private function encodeValue(mixed $value): mixed
    {
        if ($value instanceof Node) {
            return [
                self::NODE_MARKER => true,
                't' => $value->getType(),
                'sl' => $value->getStartLine(),
                'el' => $value->getEndLine(),
                'sp' => $value->getStartFilePos(),
                'ep' => $value->getEndFilePos(),
            ];
        }

        if (is_array($value)) {
            $encoded = [];

            foreach ($value as $key => $child) {
                $encoded[$key] = $this->encodeValue($child);
            }

            return [
                self::ARRAY_MARKER => true,
                'v' => $encoded,
            ];
        }

        return $value;
    }

    private function decodeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (($value[self::NODE_MARKER] ?? false) === true) {
            if (
                !is_string($value['t'] ?? null)
                || !is_int($value['sl'] ?? null)
                || !is_int($value['el'] ?? null)
                || !is_int($value['sp'] ?? null)
                || !is_int($value['ep'] ?? null)
            ) {
                throw new \RuntimeException('AST query cache is corrupt.');
            }

            return new StoredNode(
                $this->nonEmptyString($value['t']),
                $value['sl'],
                $value['el'],
                $value['sp'],
                $value['ep'],
            );
        }

        if (($value[self::ARRAY_MARKER] ?? false) === true) {
            if (!is_array($value['v'] ?? null)) {
                throw new \RuntimeException('AST query cache is corrupt.');
            }

            $decoded = [];

            foreach ($value['v'] as $key => $child) {
                $decoded[$key] = $this->decodeValue($child);
            }

            return $decoded;
        }

        return $value;
    }

    /**
     * @return non-empty-string
     */
    private function nonEmptyString(string $value): string
    {
        if ($value === '') {
            throw new \RuntimeException('AST query cache is corrupt.');
        }

        return $value;
    }
}
