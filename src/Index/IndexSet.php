<?php

declare(strict_types=1);

namespace Greph\Index;

final readonly class IndexSet
{
    /**
     * @param list<IndexSetEntry> $entries
     */
    public function __construct(
        public string $path,
        public string $basePath,
        public string $name,
        public array $entries,
    ) {
    }

    /**
     * @param list<string> $names
     * @return list<IndexSetEntry>
     */
    public function entries(?IndexMode $mode = null, array $names = []): array
    {
        $nameFilter = array_fill_keys($names, true);
        $entries = array_values(array_filter(
            $this->entries,
            static function (IndexSetEntry $entry) use ($mode, $nameFilter): bool {
                if (!$entry->enabled) {
                    return false;
                }

                if ($mode !== null && $entry->mode !== $mode) {
                    return false;
                }

                if ($nameFilter !== [] && !isset($nameFilter[$entry->name])) {
                    return false;
                }

                return true;
            },
        ));

        usort(
            $entries,
            static fn (IndexSetEntry $left, IndexSetEntry $right): int => [
                -$left->priority,
                $left->mode->value,
                $left->name,
                $left->rootPath,
            ] <=> [
                -$right->priority,
                $right->mode->value,
                $right->name,
                $right->rootPath,
            ],
        );

        return $entries;
    }
}
