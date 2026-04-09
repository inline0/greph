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

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCli(array $arguments, string $stdin = ''): array
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
