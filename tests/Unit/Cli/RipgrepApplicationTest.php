<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Cli;

use Phgrep\Cli\RipgrepApplication;
use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RipgrepApplicationTest extends TestCase
{
    private string $workspace;

    private string $originalWorkingDirectory;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('ripgrep-application');
        $this->originalWorkingDirectory = getcwd() ?: '.';
        chdir($this->workspace);

        Workspace::writeFile($this->workspace, 'single.txt', "alpha\nneedle\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction visible(): void {}\n");
        Workspace::writeFile($this->workspace, '.hidden/secret.txt', "needle\n");
    }

    protected function tearDown(): void
    {
        chdir($this->originalWorkingDirectory);
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itDisplaysRipgrepHelpAndSupportsCommonLongFlags(): void
    {
        $harness = $this->newApplication();
        $application = $harness['application'];

        $helpExit = $application->run(['rg', '--help']);
        $searchExit = $application->run(['rg', '--fixed-strings', '--ignore-case', 'needle', 'single.txt']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $helpExit);
        $this->assertSame(0, $searchExit);
        $this->assertStringContainsString('Usage:' . PHP_EOL . '  rg [options] pattern [path...]', $stdout);
        $this->assertStringContainsString("needle\n", $stdout);
    }

    #[Test]
    public function itSupportsFilesModeWithFilteringFlags(): void
    {
        $harness = $this->newApplication();
        $application = $harness['application'];

        $defaultExit = $application->run(['rg', '--files', '.']);
        $hiddenExit = $application->run(['rg', '--files', '--hidden', '.']);
        $typedExit = $application->run(['rg', '--files', '--type', 'php', '.']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $defaultExit);
        $this->assertSame(0, $hiddenExit);
        $this->assertSame(0, $typedExit);
        $this->assertStringContainsString("single.txt\n", $stdout);
        $this->assertStringContainsString("src/App.php\n", $stdout);
        $this->assertStringContainsString(".hidden/secret.txt\n", $stdout);
    }

    #[Test]
    public function itSupportsRipgrepFilenameFlags(): void
    {
        $withFilenameHarness = $this->newApplication();
        $noFilenameHarness = $this->newApplication();

        $withFilenameExit = $withFilenameHarness['application']->run(['rg', '-H', '-F', 'needle', 'single.txt']);
        $noFilenameExit = $noFilenameHarness['application']->run(['rg', '-I', '-F', 'needle', '.']);

        $this->assertSame(0, $withFilenameExit);
        $this->assertSame(0, $noFilenameExit);
        $this->assertStringContainsString("single.txt:needle\n", $this->readStream($withFilenameHarness['stdout']));
        $this->assertStringContainsString("needle\n", $this->readStream($noFilenameHarness['stdout']));
        $this->assertStringNotContainsString('single.txt:', $this->readStream($noFilenameHarness['stdout']));
    }

    #[Test]
    public function itRejectsUnsupportedFilesFlags(): void
    {
        $application = $this->newApplication()['application'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported rg --files argument: --sort');
        $application->run(['rg', '--files', '--sort', 'path', '.']);
    }

    /**
     * @return array{
     *   application: RipgrepApplication,
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
            'application' => new RipgrepApplication(output: $output, errorOutput: $errorOutput),
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
