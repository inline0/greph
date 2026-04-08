<?php

declare(strict_types=1);

namespace Phgrep\Tests\Oracle;

use Phgrep\Support\Json;

final class ScenarioComparator
{
    /**
     * @return array<string, mixed>
     */
    public function compare(Scenario $scenario): array
    {
        $report = [
            'scenario' => $scenario->name,
            'category' => $scenario->category(),
            'mode' => $scenario->mode(),
            'oracles' => [],
            'oracle_disagreement' => $scenario->oracleDisagreement(),
        ];

        $oracleDir = $scenario->oracleDir();
        $actualDir = $scenario->actualDir();
        $actualText = is_file($actualDir . '/phgrep.txt') ? file_get_contents($actualDir . '/phgrep.txt') : '';
        $actualJson = is_file($actualDir . '/phgrep.json') ? Json::decodeFile($actualDir . '/phgrep.json') : [];

        foreach ($scenario->expectations() as $oracle => $mode) {
            if ($mode === 'skip') {
                continue;
            }

            $report['oracles'][$oracle] = match ($oracle) {
                'grep', 'rg' => $this->compareExactText(
                    $mode,
                    (string) $actualText,
                    is_file($oracleDir . '/' . $oracle . '.txt') ? (string) file_get_contents($oracleDir . '/' . $oracle . '.txt') : null,
                ),
                'rg_json' => $this->compareSemanticJson(
                    $mode,
                    $actualJson,
                    is_file($oracleDir . '/rg.json') ? Json::decodeFile($oracleDir . '/rg.json') : null,
                ),
                'sg_json' => $this->compareSemanticJson(
                    $mode,
                    $actualJson,
                    is_file($oracleDir . '/sg.json') ? Json::decodeFile($oracleDir . '/sg.json') : null,
                ),
                'sg' => $this->compareSemanticText(
                    $mode,
                    (string) $actualText,
                    is_file($oracleDir . '/sg.txt') ? (string) file_get_contents($oracleDir . '/sg.txt') : null,
                ),
                default => ['mode' => $mode, 'pass' => false, 'reason' => 'unknown-oracle'],
            };
        }

        $report['expectation'] = $scenario->evaluateReport($report);

        return $report;
    }

    /**
     * @return array{mode: string, pass: bool, reason?: string}
     */
    private function compareExactText(string $mode, string $actual, ?string $oracle): array
    {
        if ($oracle === null) {
            return ['mode' => $mode, 'pass' => false, 'reason' => 'missing-oracle'];
        }

        $actual = $this->normalizeText($actual);
        $oracle = $this->normalizeText($oracle);

        return ['mode' => $mode, 'pass' => $actual === $oracle];
    }

    /**
     * @param array<string, mixed>|list<mixed> $actual
     * @param array<string, mixed>|list<mixed>|null $oracle
     * @return array{mode: string, pass: bool, reason?: string}
     */
    private function compareSemanticJson(string $mode, array $actual, ?array $oracle): array
    {
        if ($oracle === null) {
            return ['mode' => $mode, 'pass' => false, 'reason' => 'missing-oracle'];
        }

        return ['mode' => $mode, 'pass' => $this->canonicalize($actual) === $this->canonicalize($oracle)];
    }

    /**
     * @return array{mode: string, pass: bool, reason?: string}
     */
    private function compareSemanticText(string $mode, string $actual, ?string $oracle): array
    {
        if ($oracle === null) {
            return ['mode' => $mode, 'pass' => false, 'reason' => 'missing-oracle'];
        }

        $actualLines = preg_split('/\r?\n/', trim($actual)) ?: [];
        $oracleLines = preg_split('/\r?\n/', trim($oracle)) ?: [];
        sort($actualLines);
        sort($oracleLines);

        return ['mode' => $mode, 'pass' => $actualLines === $oracleLines];
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);

        return $text === '' || str_ends_with($text, "\n") ? $text : $text . "\n";
    }

    /**
     * @param array<string, mixed>|list<mixed> $value
     */
    private function canonicalize(array $value): string
    {
        $normalized = $this->sortRecursively($value);

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|list<mixed> $value
     * @return array<string, mixed>|list<mixed>
     */
    private function sortRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }

        if (array_is_list($value)) {
            usort(
                $value,
                static fn (mixed $left, mixed $right): int => strcmp(
                    json_encode($left, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    json_encode($right, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                )
            );

            return $value;
        }

        ksort($value);

        return $value;
    }
}
