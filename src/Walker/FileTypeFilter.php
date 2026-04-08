<?php

declare(strict_types=1);

namespace Phgrep\Walker;

final class FileTypeFilter
{
    /** @var array<string, list<string>> */
    private const TYPE_MAP = [
        'css' => ['css', 'sass', 'scss'],
        'html' => ['htm', 'html', 'phtml'],
        'js' => ['cjs', 'js', 'mjs'],
        'json' => ['json'],
        'md' => ['markdown', 'md'],
        'php' => ['inc', 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phpt', 'phtml'],
        'txt' => ['txt'],
        'ts' => ['ts', 'tsx'],
        'xml' => ['xml'],
        'yaml' => ['yaml', 'yml'],
    ];

    /** @var list<string> */
    private array $includedExtensions;

    /** @var list<string> */
    private array $excludedExtensions;

    /**
     * @param list<string> $includeTypes
     * @param list<string> $excludeTypes
     */
    public function __construct(array $includeTypes = [], array $excludeTypes = [])
    {
        $this->includedExtensions = $this->expandTypes($includeTypes);
        $this->excludedExtensions = $this->expandTypes($excludeTypes);
    }

    public function matches(string $path): bool
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $extension = strtolower($extension);

        if ($extension === '') {
            return $this->includedExtensions === [];
        }

        if ($this->includedExtensions !== [] && !in_array($extension, $this->includedExtensions, true)) {
            return false;
        }

        return !in_array($extension, $this->excludedExtensions, true);
    }

    /**
     * @param list<string> $types
     * @return list<string>
     */
    private function expandTypes(array $types): array
    {
        $extensions = [];

        foreach ($types as $type) {
            $type = strtolower(trim($type));

            if ($type === '') {
                continue;
            }

            foreach (self::TYPE_MAP[$type] ?? [$type] as $extension) {
                $extensions[] = $extension;
            }
        }

        $extensions = array_values(array_unique($extensions));
        sort($extensions, SORT_STRING);

        return $extensions;
    }
}
