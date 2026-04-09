<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Cli;

use Phgrep\Cli\IndexApplication;
use Phgrep\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexApplicationTest extends TestCase
{
    private string $workspace;

    private string $originalWorkingDirectory;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('index-application');
        $this->originalWorkingDirectory = getcwd() ?: '.';
        chdir($this->workspace);

        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction indexed(): void {}\n");
        Workspace::writeFile($this->workspace, 'plain.txt', "plain text\n");
    }

    protected function tearDown(): void
    {
        chdir($this->originalWorkingDirectory);
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itBuildsRefreshesAndSearchesThroughTheSeparateIndexedCli(): void
    {
        $harness = $this->newApplication();
        $application = $harness['application'];

        $helpExit = $application->run(['phgrep-index', '--help']);
        $buildExit = $application->run(['phgrep-index', 'build', '.']);
        $searchExit = $application->run(['phgrep-index', 'search', '-F', 'function', '.']);
        $jsonExit = $application->run(['phgrep-index', 'search', '-F', '--json', 'function', '.']);
        $noMatchExit = $application->run(['phgrep-index', 'search', '-F', 'missing', '.']);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction refreshed(): void {}\n");
        $refreshExit = $application->run(['phgrep-index', 'refresh', '.']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $helpExit);
        $this->assertSame(0, $buildExit);
        $this->assertSame(0, $searchExit);
        $this->assertSame(0, $jsonExit);
        $this->assertSame(1, $noMatchExit);
        $this->assertSame(0, $refreshExit);
        $this->assertStringContainsString('Built index for', $stdout);
        $this->assertStringContainsString('src/App.php:2:function indexed(): void {}', $stdout);
        $this->assertStringContainsString('"matched_text": "function"', $stdout);
        $this->assertStringContainsString('Refreshed index for', $stdout);
    }

    #[Test]
    public function itReportsMissingPatternsAndUnknownSubcommands(): void
    {
        $harness = $this->newApplication();

        $missingPatternExit = $harness['application']->run(['phgrep-index', 'search']);

        $this->assertSame(2, $missingPatternExit);
        $this->assertSame("Missing search pattern.\n", $this->readStream($harness['stderr']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown subcommand: explode');
        $harness['application']->run(['phgrep-index', 'explode']);
    }

    /**
     * @return array{
     *   application: IndexApplication,
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
            'application' => new IndexApplication(output: $output, errorOutput: $errorOutput),
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
