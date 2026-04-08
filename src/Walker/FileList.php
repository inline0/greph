<?php

declare(strict_types=1);

namespace Phgrep\Walker;

/**
 * @implements \IteratorAggregate<int, string>
 */
final readonly class FileList implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var list<string> */
    private array $paths;

    /**
     * @param iterable<string> $paths
     */
    public function __construct(iterable $paths)
    {
        $normalized = [];

        foreach ($paths as $path) {
            $normalized[] = str_replace('\\', '/', $path);
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        $this->paths = $normalized;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    public function count(): int
    {
        return count($this->paths);
    }

    /**
     * @return \Traversable<int, string>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->paths;
    }

    /**
     * @return list<self>
     */
    public function chunk(int $size): array
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Chunk size must be greater than zero.');
        }

        $chunks = array_chunk($this->paths, $size);

        return array_map(static fn (array $chunk): self => new self($chunk), $chunks);
    }

    /**
     * @return list<string>
     */
    public function jsonSerialize(): array
    {
        return $this->paths;
    }
}
