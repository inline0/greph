<?php

declare(strict_types=1);

namespace Phgrep\Tests\Integration;

use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CliAstRewriteTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('cli-ast-rewrite');
        Workspace::writeFile($this->workspace, 'src/Legacy.php', <<<'PHP'
<?php

$items = array(1, 2, 3);
PHP);
        Workspace::writeFile($this->workspace, 'src/Search.php', <<<'PHP'
<?php

dispatch($event);
dispatch($job);
PHP);
        Workspace::writeFile($this->workspace, 'src/Unchanged.php', "<?php\n\n\$value = 42;\n");
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itPrintsDryRunRewritesWithoutWritingFiles(): void
    {
        $result = $this->runCli(['-p', 'array($$$ITEMS)', '-r', '[$$$ITEMS]', '--dry-run', '.']);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString("=== src/Legacy.php ===\n", $result['stdout']);
        $this->assertStringContainsString("\$items = [1, 2, 3];\n", $result['stdout']);
        $this->assertStringContainsString('array(1, 2, 3)', file_get_contents($this->workspace . '/src/Legacy.php') ?: '');
    }

    #[Test]
    public function itSupportsInteractiveRewriteConfirmation(): void
    {
        $accepted = $this->runCli(['-p', 'array($$$ITEMS)', '-r', '[$$$ITEMS]', '--interactive', '.'], "y\n");

        $this->assertSame(0, $accepted['exit']);
        $this->assertStringContainsString('Rewrite', $accepted['stdout']);
        $this->assertStringContainsString("src/Legacy.php\n", $accepted['stdout']);
        $this->assertStringContainsString('[1, 2, 3]', file_get_contents($this->workspace . '/src/Legacy.php') ?: '');
    }

    #[Test]
    public function itEmitsStructuredJsonForAstMatches(): void
    {
        $result = $this->runCli(['-p', 'dispatch($EVENT)', '--json', 'src/Search.php']);

        $this->assertSame(0, $result['exit']);
        $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(2, $payload);
        $this->assertSame('src/Search.php', $payload[0]['file']);
        $this->assertSame(3, $payload[0]['start_line']);
        $this->assertSame(3, $payload[0]['end_line']);
        $this->assertSame("dispatch(\$event)", trim($payload[0]['code']));
    }

    #[Test]
    public function itLeavesFilesUnchangedWhenInteractiveRewriteIsDeclined(): void
    {
        $declined = $this->runCli(['-p', 'array($$$ITEMS)', '-r', '[$$$ITEMS]', '--interactive', '.'], "n\n");

        $this->assertSame(0, $declined['exit']);
        $this->assertStringContainsString('Rewrite', $declined['stdout']);
        $this->assertStringContainsString('array(1, 2, 3)', file_get_contents($this->workspace . '/src/Legacy.php') ?: '');
    }

    #[Test]
    public function itReturnsOneWhenRewriteFindsNoChanges(): void
    {
        $result = $this->runCli(['-p', 'array($$$ITEMS)', '-r', '[$$$ITEMS]', 'src/Unchanged.php']);

        $this->assertSame(1, $result['exit']);
        $this->assertSame('', $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    #[Test]
    public function itReturnsTwoForInvalidRewritePatterns(): void
    {
        $result = $this->runCli(['-p', 'array($$$ITEMS)', '-r', 'if ($COND) { $$$BODY }', 'src/Legacy.php']);

        $this->assertSame(2, $result['exit']);
        $this->assertSame('', $result['stdout']);
        $this->assertStringContainsString('Syntax error', $result['stderr']);
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCli(array $arguments, string $stdin = ''): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/greph'], $arguments);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->workspace);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start CLI process.');
        }

        fwrite($pipes[0], $stdin);
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
