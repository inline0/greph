<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Cli;

use Greph\Cli\AstGrepApplication;
use Greph\Tests\Support\Workspace;
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

        Workspace::writeFile($this->workspace, '.gitignore', "vendor/\n");
        Workspace::writeFile($this->workspace, 'src/App.php', <<<'PHP'
<?php

$items = array(1, 2, 3);
$client->send($message);
dispatch($event);
PHP);
        Workspace::writeFile($this->workspace, '.hidden/Hidden.php', "<?php\ndispatch(\$hidden);\n");
        Workspace::writeFile($this->workspace, 'vendor/Ignored.php', "<?php\ndispatch(\$ignored);\n");
        Workspace::writeFile($this->workspace, 'notes.txt', "dispatch(\$text)\n");
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
        $shortHelpExit = $application->run(['sg', '-h']);
        $scanExit = $application->run(['sg', 'scan', '-p', 'array($$$ITEMS)', 'src/App.php']);
        $positionalExit = $application->run(['sg', 'array($$$ITEMS)', 'src/App.php']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $helpExit);
        $this->assertSame(0, $shortHelpExit);
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
    public function itSupportsJsonFilesWithMatchesAndFilterFlags(): void
    {
        $harness = $this->newApplication();
        $application = $harness['application'];

        $jsonExit = $application->run(['sg', 'run', '--json', '--pattern', 'dispatch($EVENT)', 'src/App.php']);
        $streamExit = $application->run(['sg', 'run', '--json=stream', '--pattern', 'dispatch($EVENT)', 'src/App.php']);
        $compactExit = $application->run(['sg', 'run', '--json=compact', '--pattern', '$CLIENT->send($MESSAGE)', 'src/App.php']);
        $filesExit = $application->run(['sg', 'run', '--files-with-matches', '--pattern', 'array($$$ITEMS)', 'src/App.php']);
        $hiddenExit = $application->run(['sg', 'run', '--hidden', '--pattern', 'dispatch($EVENT)', '.']);
        $noIgnoreExit = $application->run(['sg', 'run', '--no-ignore', 'hidden', '--pattern', 'dispatch($EVENT)', '.']);
        $globExit = $application->run(['sg', 'run', '--globs', 'src/*.php', '--pattern', 'dispatch($EVENT)', '.']);
        $threadExit = $application->run(['sg', 'run', '--threads', '2', '--lang', 'php', '--pattern', '$CLIENT->send($MESSAGE)', 'src/App.php']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $jsonExit);
        $this->assertSame(0, $streamExit);
        $this->assertSame(0, $compactExit);
        $this->assertSame(0, $filesExit);
        $this->assertSame(0, $hiddenExit);
        $this->assertSame(0, $noIgnoreExit);
        $this->assertSame(0, $globExit);
        $this->assertSame(0, $threadExit);
        $this->assertStringContainsString('"file": "src/App.php"', $stdout);
        $this->assertStringContainsString('src/App.php' . PHP_EOL, $stdout);
        $this->assertStringContainsString('.hidden/Hidden.php:2:dispatch($hidden);', $stdout);
        $this->assertStringContainsString('vendor/Ignored.php:2:dispatch($ignored);', $stdout);
        $this->assertStringContainsString('$client->send($message)', $stdout);
    }

    #[Test]
    public function itReturnsNoMatchStatusForFilesAndRewrites(): void
    {
        $filesHarness = $this->newApplication();
        $rewriteHarness = $this->newApplication();

        $filesExit = $filesHarness['application']->run(['sg', 'run', '--files-with-matches', '--pattern', 'isset($VALUE)', 'src/App.php']);
        $rewriteExit = $rewriteHarness['application']->run([
            'sg',
            'rewrite',
            '--pattern',
            'isset($VALUE)',
            '--rewrite',
            '$VALUE !== null',
            'src/App.php',
        ]);

        $this->assertSame(1, $filesExit);
        $this->assertSame(1, $rewriteExit);
        $this->assertSame('', $this->readStream($filesHarness['stdout']));
        $this->assertSame('', $this->readStream($rewriteHarness['stdout']));
    }

    #[Test]
    public function itSupportsInteractiveRewriteAcceptAndDecline(): void
    {
        $acceptHarness = $this->newApplication("y\n");
        $declineHarness = $this->newApplication("n\n");

        $acceptExit = $acceptHarness['application']->run([
            'sg',
            'rewrite',
            '--pattern',
            'array($$$ITEMS)',
            '--rewrite',
            '[$$$ITEMS]',
            '--interactive',
            'src/App.php',
        ]);

        Workspace::writeFile($this->workspace, 'src/App.php', <<<'PHP'
<?php

$items = array(1, 2, 3);
PHP);

        $declineExit = $declineHarness['application']->run([
            'sg',
            'rewrite',
            '--pattern',
            'array($$$ITEMS)',
            '--rewrite',
            '[$$$ITEMS]',
            '--interactive',
            'src/App.php',
        ]);

        $this->assertSame(0, $acceptExit);
        $this->assertSame(0, $declineExit);
        $this->assertStringContainsString('Rewrite ', $this->readStream($acceptHarness['stdout']));
        $this->assertStringContainsString("src/App.php\n", $this->readStream($acceptHarness['stdout']));
        $this->assertStringContainsString('Rewrite ', $this->readStream($declineHarness['stdout']));
        $this->assertStringNotContainsString('$items = [1, 2, 3];', file_get_contents($this->workspace . '/src/App.php') ?: '');
    }

    #[Test]
    public function itReportsMissingPatterns(): void
    {
        $harness = $this->newApplication();

        $exitCode = $harness['application']->run(['sg']);

        $this->assertSame(2, $exitCode);
        $this->assertSame("Missing AST pattern.\n", $this->readStream($harness['stderr']));
    }

    #[Test]
    public function itRejectsUnsupportedAndInvalidArguments(): void
    {
        $missingValue = $this->newApplication()['application'];

        try {
            $missingValue->run(['sg', 'run', '--pattern']);
            self::fail('Expected missing --pattern value.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Missing value for --pattern.', $exception->getMessage());
        }

        $invalidThreads = $this->newApplication()['application'];

        try {
            $invalidThreads->run(['sg', 'run', '--threads', '0', '--pattern', 'dispatch($EVENT)', 'src/App.php']);
            self::fail('Expected invalid --threads value.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Expected a positive integer for --threads.', $exception->getMessage());
        }

        $unsupported = $this->newApplication()['application'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sg argument: --bogus');
        $unsupported->run(['sg', 'run', '--bogus', '--pattern', 'dispatch($EVENT)', 'src/App.php']);
    }

    #[Test]
    public function itCoversAstGrepPrivateParsingAndFormattingBranches(): void
    {
        $application = $this->newApplication()['application'];

        $missingPatternExit = $application->run(['sg', 'run']);
        $parsedTerminated = $this->invokeMethod($application, 'parseArguments', ['--', 'src/App.php', 'notes.txt']);
        $parsedDefaults = $this->invokeMethod(
            $application,
            'parseArguments',
            ['--type', 'php', '--type-not', 'txt', '--rewrite', '', '--pattern', 'dispatch($EVENT)'],
        );
        $fallbackLine = $this->invokeMethod(
            $application,
            'displayLine',
            new \Greph\Ast\AstMatch(
                file: $this->workspace . '/src/App.php',
                node: new \PhpParser\Node\Expr\Variable('event'),
                captures: [],
                startLine: 99,
                endLine: 99,
                startFilePos: 0,
                endFilePos: 0,
                code: "dispatch(\n    \$event\n);",
            ),
        );
        $splitEmpty = $this->invokeMethod($application, 'splitLines', '');
        $noFilter = $this->invokeMethod($application, 'createFileTypeFilter', [], []);
        $yesFilter = $this->invokeMethod($application, 'createFileTypeFilter', ['php'], ['txt']);

        $this->assertSame(2, $missingPatternExit);
        $this->assertSame(['src/App.php', 'notes.txt'], $parsedTerminated['paths']);
        $this->assertSame(['php'], $parsedDefaults['type']);
        $this->assertSame(['txt'], $parsedDefaults['typeNot']);
        $this->assertNull($parsedDefaults['rewrite']);
        $this->assertSame(['.'], $parsedDefaults['paths']);
        $this->assertSame('dispatch( $event );', $fallbackLine);
        $this->assertSame([], $splitEmpty);
        $this->assertNull($noFilter);
        $this->assertInstanceOf(\Greph\Walker\FileTypeFilter::class, $yesFilter);
    }

    /**
     * @return array{
     *   application: AstGrepApplication,
     *   stdout: resource,
     *   stderr: resource
     * }
     */
    private function newApplication(string $input = ''): array
    {
        $inputStream = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        $errorOutput = fopen('php://temp', 'w+');

        if (!is_resource($inputStream) || !is_resource($output) || !is_resource($errorOutput)) {
            throw new \RuntimeException('Failed to create test streams.');
        }

        fwrite($inputStream, $input);
        rewind($inputStream);

        return [
            'application' => new AstGrepApplication(input: $inputStream, output: $output, errorOutput: $errorOutput),
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

    /**
     * @return mixed
     */
    private function invokeMethod(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }
}
