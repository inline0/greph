<?php

declare(strict_types=1);

namespace Phgrep\Tests\Oracle;

use Phgrep\Support\Json;

final readonly class ScenarioRepository
{
    public function __construct(private string $rootPath)
    {
    }

    public function get(string $name): Scenario
    {
        $path = $this->rootPath . '/scenarios/' . $name . '/scenario.json';

        if (!is_file($path)) {
            throw new \InvalidArgumentException(sprintf('Unknown scenario: %s', $name));
        }

        return new Scenario($name, $this->rootPath, $this->loadDefinition($path));
    }

    /**
     * @return list<Scenario>
     */
    public function all(): array
    {
        $root = $this->rootPath . '/scenarios';

        if (!is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        $scenarios = [];

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || $fileInfo->getFilename() !== 'scenario.json') {
                continue;
            }

            $relativeName = substr($fileInfo->getPathname(), strlen($root) + 1, -strlen('/scenario.json'));

            if ($relativeName === '') {
                continue;
            }

            $scenarios[] = new Scenario($relativeName, $this->rootPath, $this->loadDefinition($fileInfo->getPathname()));
        }

        usort($scenarios, static fn (Scenario $left, Scenario $right): int => strcmp($left->name, $right->name));

        return $scenarios;
    }

    /**
     * @return list<Scenario>
     */
    public function category(string $category): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (Scenario $scenario): bool => $scenario->category() === $category,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDefinition(string $path): array
    {
        $definition = Json::decodeFile($path);

        if (array_is_list($definition)) {
            throw new \RuntimeException(sprintf('Scenario definition must decode to an object: %s', $path));
        }

        $normalized = [];

        foreach ($definition as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException(sprintf('Scenario definition contains a non-string key: %s', $path));
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
