<?php

declare(strict_types=1);

namespace Greph\Ast;

use PhpParser\Node;

final class PatternMatcher
{
    /** @var array<string, string> */
    private array $fingerprints = [];

    /**
     * @return array<string, mixed>|null
     */
    public function match(Node $pattern, Node $candidate): ?array
    {
        $this->fingerprints = [];
        $captures = [];

        if (!$this->matchValue($pattern, $candidate, $captures)) {
            return null;
        }

        return $captures;
    }

    /**
     * @param array<string, mixed> $captures
     */
    private function matchValue(mixed $pattern, mixed $candidate, array &$captures): bool
    {
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

                if (!$this->matchValue($patternSubNode, $candidateSubNode, $captures)) {
                    return false;
                }
            }

            return true;
        }

        if (is_array($pattern) && is_array($candidate)) {
            return $this->matchArray(array_values($pattern), array_values($candidate), $captures);
        }

        return $pattern === $candidate;
    }

    /**
     * @param list<mixed> $pattern
     * @param list<mixed> $candidate
     * @param array<string, mixed> $captures
     */
    private function matchArray(array $pattern, array $candidate, array &$captures, int $patternOffset = 0, int $candidateOffset = 0): bool
    {
        while ($patternOffset < count($pattern)) {
            $variadicName = MetaVariable::variadicName($pattern[$patternOffset]);

            if ($variadicName !== null) {
                for ($take = $candidateOffset; $take <= count($candidate); $take++) {
                    $trialCaptures = $captures;
                    $slice = array_slice($candidate, $candidateOffset, $take - $candidateOffset);

                    if (!$this->bindCapture($variadicName, $slice, $trialCaptures)) {
                        continue;
                    }

                    if ($this->matchArray($pattern, $candidate, $trialCaptures, $patternOffset + 1, $take)) {
                        $captures = $trialCaptures;

                        return true;
                    }
                }

                return false;
            }

            if (!array_key_exists($candidateOffset, $candidate)) {
                return false;
            }

            if (!$this->matchValue($pattern[$patternOffset], $candidate[$candidateOffset], $captures)) {
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
     * @param array<int, mixed> $subNodeValue
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
