<?php

declare(strict_types=1);

namespace Greph\Tests\Oracle;

use Greph\Support\Json;
use Greph\Support\ToolResolver;

final class ComplianceReporter
{
    private ScenarioRepository $scenarioRepository;

    private ToolResolver $toolResolver;

    public function __construct(string $rootPath, ?ToolResolver $toolResolver = null)
    {
        $this->scenarioRepository = new ScenarioRepository($rootPath);
        $this->toolResolver = $toolResolver ?? new ToolResolver();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $scenarios = $this->scenarioRepository->all();
        $report = [
            'generated_at' => date(DATE_ATOM),
            'tools' => [
                'grep' => true,
                'rg' => true,
                'sg' => $this->toolResolver->hasAstGrep(),
            ],
            'summary' => [
                'total' => count($scenarios),
                'passing' => 0,
                'failing' => 0,
                'missing_reports' => 0,
            ],
            'categories' => [],
            'scenarios' => [],
        ];

        foreach ($scenarios as $scenario) {
            $comparisonPath = $scenario->reportPath();
            $comparison = is_file($comparisonPath) ? Json::decodeFile($comparisonPath) : null;
            $pass = (bool) ($comparison['expectation']['pass'] ?? false);

            if ($comparison === null) {
                $report['summary']['missing_reports']++;
            } elseif ($pass) {
                $report['summary']['passing']++;
            } else {
                $report['summary']['failing']++;
            }

            $category = $scenario->category();
            $report['categories'][$category] ??= ['total' => 0, 'passing' => 0, 'failing' => 0];
            $report['categories'][$category]['total']++;

            if ($comparison !== null) {
                $report['categories'][$category][$pass ? 'passing' : 'failing']++;
            }

            $report['scenarios'][] = [
                'name' => $scenario->name,
                'category' => $category,
                'mode' => $scenario->mode(),
                'has_oracle' => $this->hasFiles($scenario->oracleDir(), $scenario->expectedOracleFiles()),
                'has_actual' => $this->hasFiles($scenario->actualDir(), $scenario->expectedActualFiles()),
                'has_report' => $comparison !== null,
                'pass' => $comparison === null ? null : $pass,
                'failures' => $comparison['expectation']['failures'] ?? [],
            ];
        }

        ksort($report['categories']);

        return $report;
    }

    /**
     * @param array<string, mixed> $report
     */
    public function renderText(array $report): string
    {
        $lines = [
            'greph compliance report',
            '=======================',
            sprintf('Scenarios: %d', $report['summary']['total']),
            sprintf('Passing: %d', $report['summary']['passing']),
            sprintf('Failing: %d', $report['summary']['failing']),
            sprintf('Missing reports: %d', $report['summary']['missing_reports']),
            sprintf(
                'Tools: grep=%s rg=%s sg=%s',
                $report['tools']['grep'] ? 'yes' : 'no',
                $report['tools']['rg'] ? 'yes' : 'no',
                $report['tools']['sg'] ? 'yes' : 'no'
            ),
            '',
        ];

        foreach ($report['categories'] as $category => $summary) {
            $lines[] = sprintf(
                '%s: total=%d pass=%d fail=%d',
                $category,
                $summary['total'],
                $summary['passing'],
                $summary['failing'],
            );
        }

        $lines[] = '';

        foreach ($report['scenarios'] as $scenario) {
            $status = $scenario['pass'] === null ? 'missing' : ($scenario['pass'] ? 'pass' : 'fail');
            $lines[] = sprintf('%s [%s] %s', $scenario['name'], $scenario['category'], $status);

            if ($scenario['failures'] !== []) {
                $lines[] = '  failures: ' . implode(', ', $scenario['failures']);
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param list<string> $files
     */
    private function hasFiles(string $directory, array $files): bool
    {
        foreach ($files as $file) {
            if (!is_file($directory . '/' . $file)) {
                return false;
            }
        }

        return true;
    }
}
