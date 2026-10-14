<?php

declare(strict_types=1);

namespace Greph\Tests\Oracle;

use Greph\Support\Filesystem;
use Greph\Support\Json;
use Greph\Tests\Support\Workspace;
use Greph\Parallel\WorkerPool;
use Greph\Walker\FileList;

final class ScenarioRunner
{
    private OracleCapture $oracleCapture;

    private ActualCapture $actualCapture;

    private ScenarioComparator $scenarioComparator;

    private WorkspaceFactory $workspaceFactory;

    public function __construct(
        ?OracleCapture $oracleCapture = null,
        ?ActualCapture $actualCapture = null,
        ?ScenarioComparator $scenarioComparator = null,
        ?WorkspaceFactory $workspaceFactory = null,
    ) {
        $this->oracleCapture = $oracleCapture ?? new OracleCapture();
        $this->actualCapture = $actualCapture ?? new ActualCapture();
        $this->scenarioComparator = $scenarioComparator ?? new ScenarioComparator();
        $this->workspaceFactory = $workspaceFactory ?? new WorkspaceFactory();
    }

    /**
     * @return array{
     *   pass: bool,
     *   oracle: array{success: bool, outputs: array<string, mixed>, errors: list<string>},
     *   actual: array{success: bool, outputs: array<string, mixed>, errors: list<string>},
     *   comparison: array<string, mixed>
     * }
     */
    public function run(Scenario $scenario, bool $refreshOracle = false): array
    {
        $oracleWorkspace = Workspace::createDirectory('scenario-oracle');
        $actualWorkspace = Workspace::createDirectory('scenario-actual');

        try {
            Workspace::remove($oracleWorkspace);
            Workspace::remove($actualWorkspace);
            $oracleWorkspace = $this->workspaceFactory->create($scenario, 'scenario-oracle');
            $actualWorkspace = $this->workspaceFactory->create($scenario, 'scenario-actual');

            $oracleResult = (!$refreshOracle && $this->hasOracleOutputs($scenario))
                ? ['success' => true, 'outputs' => [], 'errors' => []]
                : $this->oracleCapture->captureFromWorkspace($scenario, $oracleWorkspace);

            if (!$oracleResult['success']) {
                return [
                    'pass' => false,
                    'oracle' => $oracleResult,
                    'actual' => ['success' => false, 'outputs' => [], 'errors' => ['skipped: oracle failed']],
                    'comparison' => ['expectation' => ['pass' => false, 'failures' => ['oracle-capture-failed']]],
                ];
            }

            $actualResult = $this->actualCapture->captureFromWorkspace($scenario, $actualWorkspace);

            if (!$actualResult['success']) {
                return [
                    'pass' => false,
                    'oracle' => $oracleResult,
                    'actual' => $actualResult,
                    'comparison' => ['expectation' => ['pass' => false, 'failures' => ['actual-capture-failed']]],
                ];
            }

            $comparison = $this->scenarioComparator->compare($scenario);
            Filesystem::ensureDirectory($scenario->reportsDir());
            Json::encodeFile($scenario->reportPath(), $comparison);

            $expectation = is_array($comparison['expectation'] ?? null) ? $comparison['expectation'] : [];

            return [
                'pass' => ($expectation['pass'] ?? false) === true,
                'oracle' => $oracleResult,
                'actual' => $actualResult,
                'comparison' => $comparison,
            ];
        } finally {
            Workspace::remove($oracleWorkspace);
            Workspace::remove($actualWorkspace);
        }
    }

    /**
     * @param list<Scenario> $scenarios
     * @return list<array{
     *   pass: bool,
     *   oracle: array{success: bool, outputs: array<string, mixed>, errors: list<string>},
     *   actual: array{success: bool, outputs: array<string, mixed>, errors: list<string>},
     *   comparison: array<string, mixed>
     * }>
     */
    public function runAll(array $scenarios, bool $refreshOracle = false, int $jobs = 1): array
    {
        if ($jobs < 1) {
            throw new \InvalidArgumentException('Scenario jobs must be a positive integer.');
        }
        $byName = [];
        $chunks = [];
        foreach ($scenarios as $scenario) {
            $byName[$scenario->name] = $scenario;
            $chunks[] = new FileList([$scenario->name]);
        }

        return (new WorkerPool())->map(
            $chunks,
            fn (FileList $chunk): array => $this->run($byName[$chunk->paths()[0]], $refreshOracle),
            $jobs,
        );
    }

    private function hasOracleOutputs(Scenario $scenario): bool
    {
        foreach ($scenario->expectedOracleFiles() as $file) {
            if (!is_file($scenario->oracleDir() . '/' . $file)) {
                return false;
            }
        }

        return true;
    }
}
