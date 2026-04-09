<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Cli;

use Phgrep\Cli\AstGrepApplication;
use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstGrepApplicationTest extends TestCase
{
    private string $workspace;

    private string $originalWorkingDirectory;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('ast-grep-application');
        $this->originalWorkingDirectory = getcwd() ?: '.';
        chdir($this->workspace);

        Workspace::writeFile($this->workspace, 'src/App.php', <<<'PHP'
<?php

$items = array(1, 2, 3);
PHP);
    }

    protected function tearDown(): void
    {
        chdir($this->originalWorkingDirectory);
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itDisplaysAstGrepHelpAndRunsScanMode(): void
    {
        $harness = $this->newApplication();
        $application = $harness['application'];

        $helpExit = $application->run(['sg', '--help']);
        $scanExit = $application->run(['sg', 'scan', '-p', 'array($$$ITEMS)', 'src/App.php']);
        $positionalExit = $application->run(['sg', 'array($$$ITEMS)', 'src/App.php']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $helpExit);
        $this->assertSame(0, $scanExit);
        $this->assertSame(0, $positionalExit);
        $this->assertStringContainsString('Usage:' . PHP_EOL . '  sg run --pattern PATTERN [options] [path...]', $stdout);
        $this->assertStringContainsString('src/App.php:3:$items = array(1, 2, 3);', $stdout);
    }

    #[Test]
    public function itSupportsRewriteStyleFlags(): void
    {
        $harness = $this->newApplication();
        $application = $harness['application'];

        $exitCode = $application->run([
            'sg',
            'rewrite',
            '--pattern',
            'array($$$ITEMS)',
            '--rewrite',
            '[$$$ITEMS]',
            '--dry-run',
            'src/App.php',
        ]);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("src/App.php\n", $stdout);
        $this->assertStringContainsString("@@ -3,1 +3,1 @@\n", $stdout);
        $this->assertStringContainsString('+$items = [1, 2, 3];', $stdout);
    }

    #[Test]
    public function itSupportsUpdateAllRewrites(): void
    {
        $harness = $this->newApplication();

        $exitCode = $harness['application']->run([
            'sg',
            'run',
            '--pattern',
            'array($$$ITEMS)',
            '--rewrite',
            '[$$$ITEMS]',
            '--update-all',
            'src/App.php',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("src/App.php\n", $this->readStream($harness['stdout']));
        $this->assertStringContainsString('$items = [1, 2, 3];', file_get_contents($this->workspace . '/src/App.php') ?: '');
    }

    #[Test]
    public function itReportsMissingPatterns(): void
    {
        $harness = $this->newApplication();

        $exitCode = $harness['application']->run(['sg']);

        $this->assertSame(2, $exitCode);
        $this->assertSame("Missing AST pattern.\n", $this->readStream($harness['stderr']));
    }

    /**
     * @return array{
     *   application: AstGrepApplication,
     *   stdout: resource,
     *   stderr: resource
     * }
     */
    private function newApplication(): array
    {
        $output = fopen('php://temp', 'w+');
        $errorOutput = fopen('php://temp', 'w+');

        if (!is_resource($output) || !is_resource($errorOutput)) {
            throw new \RuntimeException('Failed to create test streams.');
        }

        return [
            'application' => new AstGrepApplication(output: $output, errorOutput: $errorOutput),
            'stdout' => $output,
            'stderr' => $errorOutput,
        ];
    }

    /**
     * @param resource $stream
     */
    private function readStream($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
