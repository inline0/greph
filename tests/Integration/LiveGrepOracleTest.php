<?php

declare(strict_types=1);

namespace Greph\Tests\Integration;

use Greph\Support\CommandRunner;
use Greph\Tests\Oracle\ActualCapture;
use Greph\Tests\Oracle\Scenario;
use Greph\Tests\Oracle\ScenarioComparator;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\TestCase;

final class LiveGrepOracleTest extends TestCase
{
    public function testLiveOraclePreservesNativeOrderingWithoutRewritingCommittedSnapshots(): void
    {
        $root = Workspace::createDirectory('live-grep');
        try {
            Workspace::writeFile($root, 'input/setup/second.php', "<?php\nfunction second(): void {}\n");
            Workspace::writeFile($root, 'input/setup/first.php', "<?php\nfunction first(): void {}\n");
            $scenario = new Scenario('live', $root, [
                'category' => 'text',
                'pattern' => 'function',
                'flags' => ['-F'],
                'live_grep' => true,
                'expectations' => ['grep' => 'exact'],
            ]);
            $native = (new CommandRunner())->run(['grep', '-r', '-n', '-F', 'function', 'setup'], $root . '/input');
            self::assertSame(0, $native->exitCode, $native->stderr);
            $stale = implode("\n", array_reverse(explode("\n", trim($native->stdout)))) . "\n";
            self::assertNotSame($native->stdout, $stale);
            $snapshot = Workspace::writeFile($root, 'scenarios/live/oracle/grep.txt', $stale);

            $actual = (new ActualCapture())->captureFromWorkspace($scenario, $root . '/input');
            self::assertTrue($actual['success'], implode("\n", $actual['errors']));
            self::assertSame($native->stdout, $actual['outputs']['greph.txt']);
            self::assertSame($native->stdout, file_get_contents($scenario->actualDir() . '/grep.live.txt'));
            self::assertSame($stale, file_get_contents($snapshot));
            $comparator = new ScenarioComparator();
            $comparison = $comparator->compare($scenario);
            self::assertIsArray($comparison['expectation']);
            self::assertTrue($comparison['expectation']['pass']);

            Workspace::writeFile($root, 'scenarios/live/actual/greph.txt', "wrong output\n");
            $comparison = $comparator->compare($scenario);
            self::assertIsArray($comparison['expectation']);
            self::assertFalse($comparison['expectation']['pass']);
            unlink($scenario->actualDir() . '/grep.live.txt');
            $comparison = $comparator->compare($scenario);
            self::assertIsArray($comparison['oracles']);
            self::assertIsArray($comparison['oracles']['grep']);
            self::assertSame('missing-oracle', $comparison['oracles']['grep']['reason']);
        } finally {
            Workspace::remove($root);
        }
    }
}
