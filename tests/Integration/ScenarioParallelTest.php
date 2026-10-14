<?php

declare(strict_types=1);

namespace Greph\Tests\Integration;

use Greph\Support\CommandRunner;
use Greph\Tests\Oracle\Scenario;
use Greph\Tests\Oracle\ScenarioRunner;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScenarioParallelTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('scenario-parallel');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itRunsScenariosInWorkersAndReturnsResultsInInputOrder(): void
    {
        $scenarios = [];
        foreach (['third', 'first', 'second'] as $name) {
            Workspace::writeFile($this->workspace, 'scenarios/' . $name . '/setup/source.php', '<?php echo 1;');
            Workspace::writeFile(
                $this->workspace,
                'scenarios/' . $name . '/setup.sh',
                'printf "%s" "$PPID" > ' . escapeshellarg($this->workspace . '/' . $name . '.pid') . "\n",
            );
            foreach (['grep.txt', 'rg.txt', 'rg.json'] as $file) {
                Workspace::writeFile($this->workspace, 'scenarios/' . $name . '/oracle/' . $file, str_ends_with($file, '.json') ? '[]' : '');
            }
            $scenarios[] = new Scenario($name, $this->workspace, ['category' => 'text', 'pattern' => 'not-present']);
        }

        $results = (new ScenarioRunner())->runAll($scenarios, jobs: 2);
        self::assertSame([true, true, true], array_column($results, 'pass'));
        self::assertSame(['third', 'first', 'second'], array_column(array_column($results, 'comparison'), 'scenario'));
        foreach ($scenarios as $scenario) {
            $pid = (int) file_get_contents($this->workspace . '/' . $scenario->name . '.pid');
            self::assertGreaterThan(0, $pid);
            if (function_exists('pcntl_fork')) {
                self::assertNotSame(getmypid(), $pid);
            } else {
                self::assertSame(getmypid(), $pid);
            }
        }
    }

    #[Test]
    public function itRejectsInvalidJobCountsInTheCli(): void
    {
        $runner = new CommandRunner();
        foreach (['0', '-1', 'wrong'] as $jobs) {
            $result = $runner->run([PHP_BINARY, dirname(__DIR__, 2) . '/bin/test-regression', '--jobs', $jobs]);
            self::assertSame(2, $result->exitCode);
            self::assertStringContainsString('positive integer', $result->stderr);
        }
    }
}
