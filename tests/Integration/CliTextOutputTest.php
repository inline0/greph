<?php

declare(strict_types=1);

namespace Phgrep\Tests\Integration;

use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CliTextOutputTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('cli-text-output');
        Workspace::writeFile($this->workspace, 'single.txt', "alpha\nneedle\n");
        Workspace::writeFile($this->workspace, 'src/app.php', "<?php\nfunction visible(): void {}\n");
        Workspace::writeFile($this->workspace, 'src/readme.txt', "function ignored\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itOmitsFilenameForSingleFileSearchesByDefault(): void
    {
        $result = $this->runCli(['-F', 'needle', 'single.txt']);

        $this->assertSame(0, $result['exit']);
        $this->assertSame("2:needle\n", $result['stdout']);
    }

    #[Test]
    public function itShowsFilenameWhenSearchingDirectoriesByDefault(): void
    {
        $result = $this->runCli(['-F', 'needle', '.']);

        $this->assertSame(0, $result['exit']);
        $this->assertSame("single.txt:2:needle\n", $result['stdout']);
    }

    #[Test]
    public function itSupportsFilenameOverrides(): void
    {
        $hiddenFilename = $this->runCli(['-h', '-F', 'needle', '.']);
        $forcedFilename = $this->runCli(['-H', '-F', 'needle', 'single.txt']);

        $this->assertSame("2:needle\n", $hiddenFilename['stdout']);
        $this->assertSame("single.txt:2:needle\n", $forcedFilename['stdout']);
    }

    #[Test]
    public function itFiltersSearchesUsingGlobPatterns(): void
    {
        $result = $this->runCli(['-F', '--glob', '*.php', 'function', '.']);

        $this->assertSame(0, $result['exit']);
        $this->assertSame("src/app.php:2:function visible(): void {}\n", $result['stdout']);
    }

    #[Test]
    public function itEmitsStructuredJsonForTextMatches(): void
    {
        $result = $this->runCli(['-F', '--json', 'needle', '.']);

        $this->assertSame(0, $result['exit']);
        $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(3, $payload);
        $payloadByFile = [];

        foreach ($payload as $entry) {
            $payloadByFile[$entry['file']] = $entry;
        }

        $this->assertEqualsCanonicalizing(['single.txt', 'src/app.php', 'src/readme.txt'], array_keys($payloadByFile));
        $this->assertCount(1, $payloadByFile['single.txt']['matches']);
        $this->assertSame(2, $payloadByFile['single.txt']['matches'][0]['line']);
        $this->assertSame(1, $payloadByFile['single.txt']['matches'][0]['column']);
        $this->assertSame('needle', $payloadByFile['single.txt']['matches'][0]['matched_text']);
        $this->assertSame([], $payloadByFile['src/app.php']['matches']);
        $this->assertSame([], $payloadByFile['src/readme.txt']['matches']);
    }

    #[Test]
    public function itReturnsOneWhenTextSearchFindsNoMatches(): void
    {
        $result = $this->runCli(['-F', 'missing', '.']);

        $this->assertSame(1, $result['exit']);
        $this->assertSame('', $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    #[Test]
    public function itReturnsTwoForTextRuntimeErrors(): void
    {
        $result = $this->runCli(['-F', 'needle', '/definitely/missing']);

        $this->assertSame(2, $result['exit']);
        $this->assertSame('', $result['stdout']);
        $this->assertStringContainsString('Path does not exist: /definitely/missing', $result['stderr']);
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCli(array $arguments): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/phgrep'], $arguments);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->workspace);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start CLI process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }
}
