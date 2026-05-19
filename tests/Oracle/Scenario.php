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
        return self::asString($this->definition['category'] ?? '');
    }

    public function mode(): string
    {
        $mode = $this->definition['mode'] ?? null;

        if (is_string($mode)) {
            return $mode;
        }

        return match ($this->category()) {
            'ast' => 'ast',
            'rewrite' => 'rewrite',
            default => 'text',
        };
    }

    public function description(): string
    {
        return self::asString($this->definition['description'] ?? '');
    }

    public function pattern(): string
    {
        return self::asString($this->definition['pattern'] ?? '');
    }

    public function rewrite(): ?string
    {
        $rewrite = $this->definition['rewrite'] ?? null;

        return is_string($rewrite) ? $rewrite : null;
    }

    public function language(): string
    {
        $lang = $this->definition['lang'] ?? 'php';

        return is_string($lang) ? $lang : 'php';
    }

    /**
     * @return list<string>
     */
    public function flags(): array
    {
        $flags = $this->definition['flags'] ?? [];

        if (!is_array($flags)) {
            return [];
        }

        $result = [];

        foreach ($flags as $flag) {
            if (is_scalar($flag) || $flag === null) {
                $result[] = (string) $flag;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = $this->definition['paths'] ?? null;

        if (is_array($paths) && $paths !== []) {
            $result = [];

            foreach ($paths as $path) {
                if (is_scalar($path) || $path === null) {
                    $result[] = (string) $path;
                }
            }

            return $result;
        }

        $path = $this->definition['path'] ?? 'setup';

        return [is_string($path) ? $path : 'setup'];
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
        $disagreement = $this->definition['oracle_disagreement'] ?? [];

        if (!is_array($disagreement)) {
            return [];
        }

        $result = [];

        foreach ($disagreement as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
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

    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return '';
    }
}
