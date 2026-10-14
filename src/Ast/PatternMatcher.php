<?php

declare(strict_types=1);

namespace Greph\Ast;

use PhpParser\Node;

final class PatternMatcher
{
    /** @var array<string, string> */
    private array $fingerprints = [];

    /**
     * @param array<string, array{parent: Node, field: string, offset: int, length: int}>|null $lists
     * @return array<string, mixed>|null
     */
    public function match(Node $pattern, Node $candidate, ?array &$lists = null): ?array
    {
        $this->fingerprints = [];
        $captures = [];
        if ($lists !== null) {
            $lists = [];
        }

        if (!$this->matchValue($pattern, $candidate, $captures, $lists)) {
            return null;
        }

        return $captures;
    }

    /**
     * @param array<string, mixed> $captures
     * @param array<string, array{parent: Node, field: string, offset: int, length: int}>|null $lists
     */
    private function matchValue(
        mixed $pattern,
        mixed $candidate,
        array &$captures,
        ?array &$lists,
        ?Node $parent = null,
        ?string $field = null,
    ): bool {
        if ($pattern instanceof Node && $candidate instanceof Node) {
            $metaName = MetaVariable::singleName($pattern);

            if ($metaName !== null) {
                return $this->bindCapture($metaName, $candidate, $captures);
            }

            if ($pattern::class !== $candidate::class) {
                return false;
            }

            foreach ($pattern->getSubNodeNames() as $subNodeName) {
                /** @var mixed $patternSubNode */
                $patternSubNode = $pattern->$subNodeName;
                /** @var mixed $candidateSubNode */
                $candidateSubNode = $candidate->$subNodeName;

                if (!$this->matchValue($patternSubNode, $candidateSubNode, $captures, $lists, $candidate, $subNodeName)) {
                    return false;
                }
            }

            return true;
        }

        if (is_array($pattern) && is_array($candidate)) {
            return $this->matchArray(array_values($pattern), array_values($candidate), $captures, $lists, $parent, $field);
        }

        return $pattern === $candidate;
    }

    /**
     * @param list<mixed> $pattern
     * @param list<mixed> $candidate
     * @param array<string, mixed> $captures
     * @param array<string, array{parent: Node, field: string, offset: int, length: int}>|null $lists
     */
    private function matchArray(
        array $pattern,
        array $candidate,
        array &$captures,
        ?array &$lists,
        ?Node $parent,
        ?string $field,
        int $patternOffset = 0,
        int $candidateOffset = 0,
    ): bool {
        while ($patternOffset < count($pattern)) {
            $variadicName = MetaVariable::variadicName($pattern[$patternOffset]);

            if ($variadicName !== null) {
                for ($take = $candidateOffset; $take <= count($candidate); $take++) {
                    $trialCaptures = $captures;
                    $trialLists = $lists;
                    $slice = array_slice($candidate, $candidateOffset, $take - $candidateOffset);

                    if (!$this->bindCapture($variadicName, $slice, $trialCaptures)) {
                        continue;
                    }

                    if (
                        $trialLists !== null && $parent !== null && $field !== null
                        && !MetaVariable::isNonCapturing($variadicName) && !isset($trialLists[$variadicName])
                    ) {
                        $trialLists[$variadicName] = [
                            'parent' => $parent,
                            'field' => $field,
                            'offset' => $candidateOffset,
                            'length' => $take - $candidateOffset,
                        ];
                    }

                    if ($this->matchArray($pattern, $candidate, $trialCaptures, $trialLists, $parent, $field, $patternOffset + 1, $take)) {
                        $captures = $trialCaptures;
                        $lists = $trialLists;

                        return true;
                    }
                }

                return false;
            }

            if (!array_key_exists($candidateOffset, $candidate)) {
                return false;
            }

            if (!$this->matchValue($pattern[$patternOffset], $candidate[$candidateOffset], $captures, $lists)) {
                return false;
            }

            $patternOffset++;
            $candidateOffset++;
        }

        return $candidateOffset === count($candidate);
    }

    /**
     * @param array<string, mixed> $captures
     */
    private function bindCapture(string $name, mixed $value, array &$captures): bool
    {
        if (MetaVariable::isNonCapturing($name)) {
            return true;
        }

        if (!array_key_exists($name, $captures)) {
            $captures[$name] = $value;

            return true;
        }

        return $this->fingerprint($captures[$name]) === $this->fingerprint($value);
    }

    private function fingerprint(mixed $value): string
    {
        if ($value instanceof Node) {
            return $this->fingerprintNode($value);
        }

        if (is_array($value)) {
            return serialize(array_map(fn (mixed $item): string => $this->fingerprint($item), $value));
        }

        return serialize($value);
    }

    private function fingerprintNode(Node $node): string
    {
        $cacheKey = 'node:' . spl_object_id($node);

        if (isset($this->fingerprints[$cacheKey])) {
            return $this->fingerprints[$cacheKey];
        }

        $data = [$node::class];

        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @var mixed $subNodeValue */
            $subNodeValue = $node->$subNodeName;
            if ($subNodeValue instanceof Node) {
                $data[$subNodeName] = $this->fingerprintNode($subNodeValue);
                continue;
            }

            if (!is_array($subNodeValue)) {
                $data[$subNodeName] = serialize($subNodeValue);
                continue;
            }

            $data[$subNodeName] = $this->serializeSubNodeArray($subNodeValue);
        }

        $this->fingerprints[$cacheKey] = serialize($data);

        return $this->fingerprints[$cacheKey];
    }

    /**
     * @param array<array-key, mixed> $subNodeValue
     * @return list<string>
     */
    private function serializeSubNodeArray(array $subNodeValue): array
    {
        $serialized = [];

        foreach ($subNodeValue as $item) {
            $serialized[] = $item instanceof Node
                ? $this->fingerprintNode($item)
                : serialize($item);
        }

        return $serialized;
    }
}
