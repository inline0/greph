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

        Workspace::writeFile($this->workspace, '.gitignore', "vendor/\n");
        Workspace::writeFile($this->workspace, 'single.txt', "alpha\nneedle\nNEEDLE\n");
        Workspace::writeFile($this->workspace, 'counts.txt', "needle\nneedle\n");
        Workspace::writeFile($this->workspace, 'context.txt', "before\nneedle\nafter\n");
        Workspace::writeFile($this->workspace, 'invert.txt', "needle\nhay\n");
        Workspace::writeFile($this->workspace, 'words.txt', "needle\nneedles\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction visible(): void {}\n");
        Workspace::writeFile($this->workspace, 'src/Other.txt', "function ignored\n");
        Workspace::writeFile($this->workspace, '.hidden/secret.txt', "needle\n");
        Workspace::writeFile($this->workspace, 'vendor/ignored.txt', "ignored needle\n");
        symlink($this->workspace . '/single.txt', $this->workspace . '/link-to-single.txt');
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
        $shortHelpExit = $application->run(['rg', '-h']);
        $searchExit = $application->run(['rg', '--fixed-strings', '--ignore-case', 'needle', 'single.txt']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $helpExit);
        $this->assertSame(0, $shortHelpExit);
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
        $typeNotExit = $application->run(['rg', '--files', '--type-not', 'php', '.']);
        $globExit = $application->run(['rg', '--files', '--glob', '*.php', '.']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $defaultExit);
        $this->assertSame(0, $hiddenExit);
        $this->assertSame(0, $typedExit);
        $this->assertSame(0, $typeNotExit);
        $this->assertSame(0, $globExit);
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
    public function itFollowsSymlinkedFilesWhenRequested(): void
    {
        $harness = $this->newApplication();

        $exitCode = $harness['application']->run(['rg', '-L', '-F', 'needle', '.']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("./link-to-single.txt:needle\n", $this->readStream($harness['stdout']));
    }

    #[Test]
    public function itSupportsSearchModesAndFilterFlags(): void
    {
        $harness = $this->newApplication();
        $application = $harness['application'];

        $countExit = $application->run(['rg', '-c', '-F', 'needle', 'counts.txt']);
        $jsonExit = $application->run(['rg', '--json', '-F', 'needle', 'single.txt']);
        $filesWithExit = $application->run(['rg', '-l', '-F', 'needle', '.']);
        $filesWithoutExit = $application->run(['rg', '--files-without-match', '-F', 'needle', '.']);
        $beforeExit = $application->run(['rg', '-B', '1', '-F', 'needle', 'context.txt']);
        $afterExit = $application->run(['rg', '-A', '1', '-F', 'needle', 'context.txt']);
        $regexpExit = $application->run(['rg', '--regexp', 'needle', 'single.txt']);
        $invertExit = $application->run(['rg', '-v', '-F', 'needle', 'invert.txt']);
        $wordExit = $application->run(['rg', '-w', '-F', 'needle', 'words.txt']);
        $hiddenExit = $application->run(['rg', '--hidden', '-F', 'needle', '.']);
        $ignoredExit = $application->run(['rg', '--no-ignore', '-F', 'ignored', '.']);
        $typeNotExit = $application->run(['rg', '--type-not', 'php', 'function', '.']);
        $maxCountExit = $application->run(['rg', '-m', '1', '-F', 'needle', 'counts.txt']);
        $threadExit = $application->run(['rg', '-j', '2', '-F', 'needle', 'single.txt']);
        $delimiterExit = $application->run(['rg', '-F', '--', 'needle', 'single.txt']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $countExit);
        $this->assertSame(0, $jsonExit);
        $this->assertSame(0, $filesWithExit);
        $this->assertSame(0, $filesWithoutExit);
        $this->assertSame(0, $beforeExit);
        $this->assertSame(0, $afterExit);
        $this->assertSame(0, $regexpExit);
        $this->assertSame(0, $invertExit);
        $this->assertSame(0, $wordExit);
        $this->assertSame(0, $hiddenExit);
        $this->assertSame(0, $ignoredExit);
        $this->assertSame(0, $typeNotExit);
        $this->assertSame(0, $maxCountExit);
        $this->assertSame(0, $threadExit);
        $this->assertSame(0, $delimiterExit);
        $this->assertStringContainsString("2\n", $stdout);
        $this->assertStringContainsString('"type":"match"', $stdout);
        $this->assertStringContainsString("before\n", $stdout);
        $this->assertStringContainsString("after\n", $stdout);
        $this->assertStringContainsString("hay\n", $stdout);
        $this->assertStringContainsString("./words.txt:needle\n", $stdout);
        $this->assertStringContainsString("./vendor/ignored.txt:ignored needle\n", $stdout);
        $this->assertStringContainsString("./src/Other.txt:function ignored\n", $stdout);
    }

    #[Test]
    public function itReportsMissingPatternsAndRejectsInvalidArguments(): void
    {
        $missingHarness = $this->newApplication();
        $missingExit = $missingHarness['application']->run(['rg', '-F']);

        $this->assertSame(2, $missingExit);
        $this->assertSame("Missing search pattern.\n", $this->readStream($missingHarness['stderr']));

        $unsupportedFiles = $this->newApplication()['application'];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported rg --files argument: --sort');
        $unsupportedFiles->run(['rg', '--files', '--sort', 'path', '.']);
    }

    #[Test]
    public function itRejectsInvalidSearchValues(): void
    {
        $missingValue = $this->newApplication()['application'];

        try {
            $missingValue->run(['rg', '--glob']);
            self::fail('Expected missing --glob value.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Missing value for --glob.', $exception->getMessage());
        }

        $invalidThreads = $this->newApplication()['application'];

        try {
            $invalidThreads->run(['rg', '--threads', '0', '-F', 'needle', '.']);
            self::fail('Expected invalid --threads value.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Expected a positive integer for --threads.', $exception->getMessage());
        }

        $invalidContext = $this->newApplication()['application'];

        try {
            $invalidContext->run(['rg', '-A', 'nope', '-F', 'needle', '.']);
            self::fail('Expected invalid -A value.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Expected a non-negative integer for -A.', $exception->getMessage());
        }

        $unsupportedArg = $this->newApplication()['application'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported rg argument: --bogus');
        $unsupportedArg->run(['rg', '--bogus', 'needle', '.']);
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
