<?php

declare(strict_types=1);

namespace Greph\Tests\Oracle;

final readonly class Scenario
{
    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        public string $name,
        public string $rootPath,
        public array $definition,
    ) {
    }

    public function category(): string
    {
        return (string) ($this->definition['category'] ?? '');
    }

    public function mode(): string
    {
        return (string) ($this->definition['mode'] ?? match ($this->category()) {
            'ast' => 'ast',
            'rewrite' => 'rewrite',
            default => 'text',
        });
    }

    public function description(): string
    {
        return (string) ($this->definition['description'] ?? '');
    }

    public function pattern(): string
    {
        return (string) ($this->definition['pattern'] ?? '');
    }

    public function rewrite(): ?string
    {
        $rewrite = $this->definition['rewrite'] ?? null;

        return is_string($rewrite) ? $rewrite : null;
    }

    public function language(): string
    {
        return (string) ($this->definition['lang'] ?? 'php');
    }

    /**
     * @return list<string>
     */
    public function flags(): array
    {
        return array_values(array_map('strval', (array) ($this->definition['flags'] ?? [])));
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = $this->definition['paths'] ?? null;

        if (is_array($paths) && $paths !== []) {
            return array_values(array_map('strval', $paths));
        }

        return [(string) ($this->definition['path'] ?? 'setup')];
    }

    /**
     * @return array<string, string>
     */
    public function expectations(): array
    {
        /** @var array<string, string> $expectations */
        $expectations = (array) ($this->definition['expectations'] ?? match ($this->mode()) {
            'ast' => ['sg' => 'semantic', 'sg_json' => 'semantic'],
            'rewrite' => ['sg' => 'exact', 'sg_json' => 'semantic'],
            default => ['grep' => 'exact', 'rg' => 'exact', 'rg_json' => 'semantic'],
        });

        return $expectations;
    }

    /**
     * @return array<string, mixed>
     */
    public function oracleDisagreement(): array
    {
        return (array) ($this->definition['oracle_disagreement'] ?? []);
    }

    public function scenarioDir(): string
    {
        return $this->rootPath . '/scenarios/' . $this->name;
    }

    public function setupDir(): string
    {
        return $this->scenarioDir() . '/setup';
    }

    public function setupScriptPath(): string
    {
        return $this->scenarioDir() . '/setup.sh';
    }

    public function oracleDir(): string
    {
        return $this->scenarioDir() . '/oracle';
    }

    public function actualDir(): string
    {
        return $this->scenarioDir() . '/actual';
    }

    public function reportsDir(): string
    {
        return $this->scenarioDir() . '/reports';
    }

    public function reportPath(): string
    {
        return $this->reportsDir() . '/comparison.json';
    }

    /**
     * @return list<string>
     */
    public function expectedOracleFiles(): array
    {
        return match ($this->mode()) {
            'ast', 'rewrite' => ['sg.txt', 'sg.json'],
            default => ['grep.txt', 'rg.txt', 'rg.json'],
        };
    }

    /**
     * @return list<string>
     */
    public function expectedActualFiles(): array
    {
        return ['greph.txt', 'greph.json'];
    }

    /**
     * @param array<string, mixed> $report
     * @return array{pass: bool, failures: list<string>}
     */
    public function evaluateReport(array $report): array
    {
        $failures = [];
        /** @var array<string, array{pass: bool}> $oracleResults */
        $oracleResults = (array) ($report['oracles'] ?? []);

        foreach ($this->expectations() as $oracle => $mode) {
            if ($mode === 'skip') {
                continue;
            }

            if (($oracleResults[$oracle]['pass'] ?? false) !== true) {
                $failures[] = sprintf('%s:%s', $oracle, $mode);
            }
        }

        return [
            'pass' => $failures === [],
            'failures' => $failures,
        ];
    }
}
