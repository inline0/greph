<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Cli;

use Greph\Cli\Application;
use Greph\Tests\Support\Workspace;
use Greph\Walker\FileTypeFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private string $workspace;

    private string $originalWorkingDirectory;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('application');
        $this->originalWorkingDirectory = getcwd() ?: '.';
        chdir($this->workspace);

        Workspace::writeFile($this->workspace, 'single.txt', "alpha\nneedle\n");
        Workspace::writeFile($this->workspace, 'plain.txt', "plain text\n");
        Workspace::writeFile($this->workspace, 'src/App.php', <<<'PHP'
<?php

function visible(): void {}
$items = array(1, 2, 3);
PHP);
        Workspace::writeFile($this->workspace, 'src/Other.php', "<?php\n\$value = 1;\n");
    }

    protected function tearDown(): void
    {
        chdir($this->originalWorkingDirectory);
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itParsesArgumentsAcrossSupportedFlags(): void
    {
        $application = $this->newApplication()['application'];
        /** @var array<string, mixed> $parsed */
        $parsed = $this->invokePrivate(
            $application,
            'parseArguments',
            [
                'greph',
                '-F',
                '-i',
                '-w',
                '-v',
                '-c',
                '-l',
                '-L',
                '-q',
                '--json',
                '--no-ignore',
                '--hidden',
                '--glob',
                '*.php',
                '--dry-run',
                '--interactive',
                '-h',
                '-H',
                '-n',
                '-p',
                'new $CLASS()',
                '-r',
                '$CLASS::make()',
                '-j',
                '4',
                '-m',
                '2',
                '-A',
                '1',
                '-B',
                '2',
                '-C',
                '3',
                '--type',
                'php',
                '--type-not',
                'md',
                '--lang',
                'php',
                'src',
                'single.txt',
            ],
        );

        $this->assertTrue($parsed['fixedString']);
        $this->assertTrue($parsed['caseInsensitive']);
        $this->assertTrue($parsed['wholeWord']);
        $this->assertTrue($parsed['invertMatch']);
        $this->assertTrue($parsed['countOnly']);
        $this->assertTrue($parsed['filesWithMatches']);
        $this->assertTrue($parsed['filesWithoutMatches']);
        $this->assertTrue($parsed['quiet']);
        $this->assertTrue($parsed['json']);
        $this->assertTrue($parsed['noIgnore']);
        $this->assertTrue($parsed['hidden']);
        $this->assertSame(['*.php'], $parsed['glob']);
        $this->assertTrue($parsed['dryRun']);
        $this->assertTrue($parsed['interactive']);
        $this->assertTrue($parsed['showFileNames']);
        $this->assertTrue($parsed['showLineNumbers']);
        $this->assertSame(4, $parsed['jobs']);
        $this->assertSame(2, $parsed['maxCount']);
        $this->assertSame(2, $parsed['beforeContext']);
        $this->assertSame(1, $parsed['afterContext']);
        $this->assertSame(3, $parsed['context']);
        $this->assertSame(['php'], $parsed['type']);
        $this->assertSame(['md'], $parsed['typeNot']);
        $this->assertSame('php', $parsed['lang']);
        $this->assertSame('new $CLASS()', $parsed['astPattern']);
        $this->assertSame('$CLASS::make()', $parsed['rewrite']);
        $this->assertNull($parsed['pattern']);
        $this->assertSame(['src', 'single.txt'], $parsed['paths']);
    }

    #[Test]
    public function itParsesHelpDefaultPathsAndDoubleDash(): void
    {
        $application = $this->newApplication()['application'];
        /** @var array<string, mixed> $help */
        $help = $this->invokePrivate($application, 'parseArguments', ['greph', '--help']);
        /** @var array<string, mixed> $defaultPath */
        $defaultPath = $this->invokePrivate($application, 'parseArguments', ['greph', 'needle']);
        /** @var array<string, mixed> $doubleDash */
        $doubleDash = $this->invokePrivate($application, 'parseArguments', ['greph', '-F', '--', '-literal', 'single.txt']);

        $this->assertTrue($help['help']);
        $this->assertSame(['.'], $defaultPath['paths']);
        $this->assertSame('-literal', $doubleDash['pattern']);
        $this->assertSame(['single.txt'], $doubleDash['paths']);
    }

    #[Test]
    public function itRejectsUnknownArgumentsAndMissingValues(): void
    {
        $application = $this->newApplication()['application'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown argument: --bogus');
        $this->invokePrivate($application, 'parseArguments', ['greph', '--bogus']);
    }

    #[Test]
    public function itRejectsMissingOptionValues(): void
    {
        $application = $this->newApplication()['application'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing value for -A.');
        $this->invokePrivate($application, 'parseArguments', ['greph', '-A']);
    }

    #[Test]
    public function itCreatesOptionalFileTypeFilters(): void
    {
        $application = $this->newApplication()['application'];

        $this->assertNull($this->invokePrivate($application, 'createFileTypeFilter', [], []));

        $filter = $this->invokePrivate($application, 'createFileTypeFilter', ['php'], ['md']);

        $this->assertInstanceOf(FileTypeFilter::class, $filter);
        $this->assertTrue($filter->matches($this->workspace . '/src/App.php'));
        $this->assertFalse($filter->matches($this->workspace . '/README.md'));
    }

    #[Test]
    public function itDisplaysUsageAndTextModeResults(): void
    {
        $harness = $this->newApplication();
        $application = $harness['application'];

        $helpExit = $application->run(['greph', '--help']);
        $missingPatternExit = $application->run(['greph']);
        $matchExit = $application->run(['greph', '-F', 'needle', 'single.txt']);
        $jsonExit = $application->run(['greph', '-F', '--json', 'needle', 'single.txt']);
        $noMatchExit = $application->run(['greph', '-F', 'missing', 'single.txt']);
        $filesWithoutMatchesExit = $application->run(['greph', '-F', '-L', 'needle', '.']);
        $quietExit = $application->run(['greph', '-F', '-q', 'needle', 'single.txt']);
        $quietNoMatchExit = $application->run(['greph', '-F', '-q', 'missing', 'single.txt']);

        $stdout = $this->readStream($harness['stdout']);
        $stderr = $this->readStream($harness['stderr']);

        $this->assertSame(0, $helpExit);
        $this->assertSame(2, $missingPatternExit);
        $this->assertSame(0, $matchExit);
        $this->assertSame(0, $jsonExit);
        $this->assertSame(1, $noMatchExit);
        $this->assertSame(0, $filesWithoutMatchesExit);
        $this->assertSame(0, $quietExit);
        $this->assertSame(1, $quietNoMatchExit);
        $this->assertStringContainsString('Usage:', $stdout);
        $this->assertStringContainsString("2:needle\n", $stdout);
        $this->assertStringContainsString('"matched_text": "needle"', $stdout);
        $this->assertStringContainsString("plain.txt\n", $stdout);
        $this->assertSame("Missing search pattern.\n", $stderr);
    }

    #[Test]
    public function itRunsAstSearchAndRewriteModes(): void
    {
        $searchHarness = $this->newApplication();
        $searchApplication = $searchHarness['application'];

        $plainSearchExit = $searchApplication->run(['greph', '-p', 'array($$$ITEMS)', 'src/App.php']);
        $jsonSearchExit = $searchApplication->run(['greph', '-p', 'array($$$ITEMS)', '--json', 'src/App.php']);
        $noMatchExit = $searchApplication->run(['greph', '-p', 'new $CLASS()', 'src/Other.php']);
        $dryRunExit = $searchApplication->run(['greph', '-p', 'array($$$ITEMS)', '-r', '[$$$ITEMS]', '--dry-run', 'src/App.php']);

        $searchOutput = $this->readStream($searchHarness['stdout']);

        $this->assertSame(0, $plainSearchExit);
        $this->assertSame(0, $jsonSearchExit);
        $this->assertSame(1, $noMatchExit);
        $this->assertSame(0, $dryRunExit);
        $this->assertStringContainsString('src/App.php:4:array(1, 2, 3)', $searchOutput);
        $this->assertStringContainsString('"file": "src/App.php"', $searchOutput);
        $this->assertStringContainsString("=== src/App.php ===\n", $searchOutput);
        $this->assertStringContainsString('$items = [1, 2, 3];', $searchOutput);

        $interactiveRejectHarness = $this->newApplication("n\n");
        $interactiveRejectExit = $interactiveRejectHarness['application']->run(
            ['greph', '-p', 'array($$$ITEMS)', '-r', '[$$$ITEMS]', '--interactive', 'src/App.php']
        );

        $this->assertSame(0, $interactiveRejectExit);
        $this->assertStringContainsString('Rewrite ' . $this->workspace . '/src/App.php? [y/N] ', $this->readStream($interactiveRejectHarness['stdout']));
        $this->assertStringContainsString('array(1, 2, 3)', file_get_contents($this->workspace . '/src/App.php') ?: '');

        $interactiveAcceptHarness = $this->newApplication("yes\n");
        $interactiveAcceptExit = $interactiveAcceptHarness['application']->run(
            ['greph', '-p', 'array($$$ITEMS)', '-r', '[$$$ITEMS]', '--interactive', 'src/App.php']
        );

        $this->assertSame(0, $interactiveAcceptExit);
        $this->assertStringContainsString("src/App.php\n", $this->readStream($interactiveAcceptHarness['stdout']));
        $this->assertStringContainsString('$items = [1, 2, 3];', file_get_contents($this->workspace . '/src/App.php') ?: '');

        $rewriteNoChangeHarness = $this->newApplication();
        $rewriteNoChangeExit = $rewriteNoChangeHarness['application']->run(
            ['greph', '-p', 'new $CLASS()', '-r', '$CLASS::make()', 'src/Other.php']
        );

        $this->assertSame(1, $rewriteNoChangeExit);
    }

    #[Test]
    public function itExposesRemainingAstAndFilenameBranches(): void
    {
        $harness = $this->newApplication();
        $application = $harness['application'];

        $runAstArguments = [
            'help' => false,
            'fixedString' => false,
            'caseInsensitive' => false,
            'wholeWord' => false,
            'invertMatch' => false,
            'countOnly' => false,
            'filesWithMatches' => false,
            'filesWithoutMatches' => false,
            'quiet' => false,
            'json' => false,
            'noIgnore' => false,
            'hidden' => false,
            'glob' => [],
            'dryRun' => false,
            'interactive' => false,
            'showFileNames' => null,
            'showLineNumbers' => true,
            'jobs' => 1,
            'maxCount' => null,
            'beforeContext' => 0,
            'afterContext' => 0,
            'context' => null,
            'type' => [],
            'typeNot' => [],
            'lang' => 'php',
            'astPattern' => null,
            'rewrite' => null,
            'pattern' => null,
            'paths' => ['src/App.php'],
        ];

        $this->assertSame(2, $this->invokePrivate($application, 'runAst', $runAstArguments));
        $this->assertSame("Missing AST pattern.\n", $this->readStream($harness['stderr']));
        $this->assertTrue($this->invokePrivate($application, 'shouldDisplayFileNames', ['paths' => ['src/App.php', 'single.txt'], 'showFileNames' => null]));
        $this->assertFalse($this->invokePrivate($application, 'shouldDisplayFileNames', ['paths' => ['single.txt'], 'showFileNames' => false]));
    }

    /**
     * @return array{
     *   application: Application,
     *   stdin: resource,
     *   stdout: resource,
     *   stderr: resource
     * }
     */
    private function newApplication(string $stdin = ''): array
    {
        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        $errorOutput = fopen('php://temp', 'w+');

        if (!is_resource($input) || !is_resource($output) || !is_resource($errorOutput)) {
            throw new \RuntimeException('Failed to create test streams.');
        }

        fwrite($input, $stdin);
        rewind($input);

        return [
            'application' => new Application(input: $input, output: $output, errorOutput: $errorOutput),
            'stdin' => $input,
            'stdout' => $output,
            'stderr' => $errorOutput,
        ];
    }

    private function readStream(mixed $stream): string
    {
        if (!is_resource($stream)) {
            throw new \RuntimeException('Expected a stream resource.');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);

        return $contents === false ? '' : $contents;
    }

    private function invokePrivate(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }
}
