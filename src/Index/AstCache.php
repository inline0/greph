<?php

declare(strict_types=1);

namespace Phgrep\Index;

final readonly class AstCache
{
    /**
     * @param list<array{id: int, p: string, s: int, m: int, h: bool, g: bool, o: int}> $files
     * @param array<int, array{
     *   zero_arg_new: bool,
     *   long_array: bool,
     *   function_calls: list<string>,
     *   method_calls: list<string>,
     *   static_calls: list<string>,
     *   new_targets: list<string>,
     *   classes: list<string>,
     *   interfaces: list<string>,
     *   traits: list<string>,
     *   cached: bool
     * }> $facts
     */
    public function __construct(
        public string $rootPath,
        public string $indexPath,
        public int $version,
        public int $builtAt,
        public int $nextFileId,
        public array $files,
        public array $facts,
    ) {
    }
}
