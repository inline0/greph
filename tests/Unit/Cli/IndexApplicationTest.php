<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Cli;

use Greph\Cli\IndexApplication;
use Greph\Tests\Support\Workspace;
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

        Workspace::writeFile($this->workspace, '.gitignore', "vendor/\n");
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction indexed(): void {}\n");
        Workspace::writeFile($this->workspace, 'src/Other.txt', "function other\n");
        Workspace::writeFile(
            $this->workspace,
            'src/Ast.php',
            <<<'PHP'
<?php

$service = new Service();
$items = array(1, 2, 3);
render_widget();
PHP,
        );
        Workspace::writeFile($this->workspace, 'single.txt', "alpha\nneedle\nNEEDLE\n");
        Workspace::writeFile($this->workspace, 'counts.txt', "needle\nneedle\n");
        Workspace::writeFile($this->workspace, 'context.txt', "before\nneedle\nafter\n");
        Workspace::writeFile($this->workspace, 'invert.txt', "needle\nhay\n");
        Workspace::writeFile($this->workspace, '.hidden/secret.txt', "secret needle\n");
        Workspace::writeFile($this->workspace, 'vendor/ignored.txt', "ignored needle\n");
        Workspace::writeFile($this->workspace, '.hidden/Hidden.php', "<?php\n\$hidden = new HiddenThing();\n");
        Workspace::writeFile($this->workspace, 'vendor/Ignored.php', "<?php\n\$ignored = array(1);\n");
        Workspace::writeFile($this->workspace, 'broken.php', "<?php\nif (\n");
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

        $helpExit = $application->run(['greph-index', '--help']);
        $buildExit = $application->run(['greph-index', 'build', '.']);
        $searchExit = $application->run(['greph-index', 'search', '-F', 'function', '.']);
        $jsonExit = $application->run(['greph-index', 'search', '-F', '--json', 'function', '.']);
        $noMatchExit = $application->run(['greph-index', 'search', '-F', 'missing', '.']);
        $countExit = $application->run(['greph-index', 'search', '-F', '-c', 'needle', 'counts.txt']);
        $filesWithExit = $application->run(['greph-index', 'search', '-F', '-l', 'needle', '.']);
        $filesWithoutExit = $application->run(['greph-index', 'search', '-F', '-L', 'needle', '.']);
        $caseInsensitiveExit = $application->run(['greph-index', 'search', '-F', '-i', 'needle', 'single.txt']);
        $globExit = $application->run(['greph-index', 'search', '-F', '--glob', '*.php', 'function', '.']);
        $typeNotExit = $application->run(['greph-index', 'search', '--type-not', 'php', 'function', '.']);
        $hiddenExit = $application->run(['greph-index', 'search', '--hidden', '-F', 'secret', '.']);
        $ignoredExit = $application->run(['greph-index', 'search', '--no-ignore', '-F', 'ignored', '.']);
        $contextExit = $application->run(['greph-index', 'search', '-F', '-C', '1', 'needle', 'context.txt']);
        $maxCountExit = $application->run(['greph-index', 'search', '-F', '-m', '1', 'needle', 'counts.txt']);
        $noFilenameExit = $application->run(['greph-index', 'search', '-h', '-F', 'needle', '.']);
        $withFilenameExit = $application->run(['greph-index', 'search', '-H', '-F', 'needle', 'single.txt']);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/App.php', "<?php\nfunction refreshed(): void {}\n");
        $refreshExit = $application->run(['greph-index', 'refresh', '.']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $helpExit);
        $this->assertSame(0, $buildExit);
        $this->assertSame(0, $searchExit);
        $this->assertSame(0, $jsonExit);
        $this->assertSame(1, $noMatchExit);
        $this->assertSame(0, $countExit);
        $this->assertSame(0, $filesWithExit);
        $this->assertSame(0, $filesWithoutExit);
        $this->assertSame(0, $caseInsensitiveExit);
        $this->assertSame(0, $globExit);
        $this->assertSame(0, $typeNotExit);
        $this->assertSame(0, $hiddenExit);
        $this->assertSame(0, $ignoredExit);
        $this->assertSame(0, $contextExit);
        $this->assertSame(0, $maxCountExit);
        $this->assertSame(0, $noFilenameExit);
        $this->assertSame(0, $withFilenameExit);
        $this->assertSame(0, $refreshExit);
        $this->assertStringContainsString('Built index for', $stdout);
        $this->assertStringContainsString('src/App.php:2:function indexed(): void {}', $stdout);
        $this->assertStringContainsString('"matched_text": "function"', $stdout);
        $this->assertStringContainsString("2\n", $stdout);
        $this->assertStringContainsString("3:NEEDLE\n", $stdout);
        $this->assertStringContainsString("src/Other.txt:1:function other\n", $stdout);
        $this->assertStringContainsString(".hidden/secret.txt:1:secret needle\n", $stdout);
        $this->assertStringContainsString("vendor/ignored.txt:1:ignored needle\n", $stdout);
        $this->assertStringContainsString("before\n", $stdout);
        $this->assertStringContainsString("after\n", $stdout);
        $this->assertStringContainsString('Refreshed index for', $stdout);
    }

    #[Test]
    public function itReportsMissingPatternsAndUnknownSubcommands(): void
    {
        $harness = $this->newApplication();

        $missingPatternExit = $harness['application']->run(['greph-index', 'search']);

        $this->assertSame(2, $missingPatternExit);
        $this->assertSame("Missing search pattern.\n", $this->readStream($harness['stderr']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown subcommand: explode');
        $harness['application']->run(['greph-index', 'explode']);
    }

    #[Test]
    public function itSupportsSubcommandHelpAndCustomIndexDirectories(): void
    {
        $harness = $this->newApplication();
        $application = $harness['application'];

        $buildHelpExit = $application->run(['greph-index', 'build', '--help']);
        $refreshHelpExit = $application->run(['greph-index', 'refresh', '--help']);
        $searchHelpExit = $application->run(['greph-index', 'search', '--help']);
        $buildExit = $application->run(['greph-index', 'build', '.', '--index-dir', '.custom-index']);
        $searchExit = $application->run(['greph-index', 'search', '--index-dir', '.custom-index', '-F', 'function', '.']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $buildHelpExit);
        $this->assertSame(0, $refreshHelpExit);
        $this->assertSame(0, $searchHelpExit);
        $this->assertSame(0, $buildExit);
        $this->assertSame(0, $searchExit);
        $this->assertStringContainsString('greph-index build [path] [--index-dir DIR]', $stdout);
        $this->assertStringContainsString('greph-index ast-index build [path] [--index-dir DIR]', $stdout);
        $this->assertStringContainsString('greph-index ast-cache search [options] pattern [path...]', $stdout);
        $this->assertStringContainsString('.custom-index', $stdout);
    }

    #[Test]
    public function itBuildsRefreshesAndSearchesAstIndexesAndCaches(): void
    {
        $harness = $this->newApplication();
        $application = $harness['application'];

        $indexBuildExit = $application->run(['greph-index', 'ast-index', 'build', '.']);
        $indexSearchExit = $application->run(['greph-index', 'ast-index', 'search', 'new $CLASS()', '.']);
        $indexJsonExit = $application->run(['greph-index', 'ast-index', 'search', '--json', 'array($$$ITEMS)', '.']);
        $indexFilesExit = $application->run(['greph-index', 'ast-index', 'search', '-l', 'render_widget()', '.']);
        $indexHiddenExit = $application->run(['greph-index', 'ast-index', 'search', '--hidden', 'new $CLASS()', '.']);
        $indexIgnoredExit = $application->run(['greph-index', 'ast-index', 'search', '--no-ignore', 'array($$$ITEMS)', '.']);
        $indexFallbackExit = $application->run(['greph-index', 'ast-index', 'search', '--index-dir', '.missing-ast-index', '--fallback', 'scan', 'new $CLASS()', 'src/Ast.php']);
        $cacheBuildExit = $application->run(['greph-index', 'ast-cache', 'build', '.']);
        $cacheSearchExit = $application->run(['greph-index', 'ast-cache', 'search', 'new $CLASS()', '.']);
        $cacheJsonExit = $application->run(['greph-index', 'ast-cache', 'search', '--json', 'array($$$ITEMS)', '.']);
        $cacheFilesExit = $application->run(['greph-index', 'ast-cache', 'search', '--files-with-matches', 'render_widget()', '.']);
        $cacheStrictExit = $application->run([
            'greph-index',
            'ast-cache',
            'search',
            '--index-dir',
            '.missing-ast-cache',
            '--fallback',
            'scan',
            '--strict-parse',
            'if ($COND) { $$$BODY }',
            'broken.php',
        ]);

        sleep(1);
        Workspace::writeFile($this->workspace, 'src/Ast.php', "<?php\n\$value = 1;\n");
        Workspace::writeFile($this->workspace, 'src/Newer.php', "<?php\n\$fresh = new FreshThing();\n");

        $indexRefreshExit = $application->run(['greph-index', 'ast-index', 'refresh', '.']);
        $indexRefreshedSearchExit = $application->run(['greph-index', 'ast-index', 'search', 'new $CLASS()', '.']);
        $cacheRefreshExit = $application->run(['greph-index', 'ast-cache', 'refresh', '.']);
        $cacheRefreshedSearchExit = $application->run(['greph-index', 'ast-cache', 'search', 'new $CLASS()', '.']);

        $stdout = $this->readStream($harness['stdout']);

        $this->assertSame(0, $indexBuildExit);
        $this->assertSame(0, $indexSearchExit);
        $this->assertSame(0, $indexJsonExit);
        $this->assertSame(0, $indexFilesExit);
        $this->assertSame(0, $indexHiddenExit);
        $this->assertSame(0, $indexIgnoredExit);
        $this->assertSame(0, $indexFallbackExit);
        $this->assertSame(0, $cacheBuildExit);
        $this->assertSame(0, $cacheSearchExit);
        $this->assertSame(0, $cacheJsonExit);
        $this->assertSame(0, $cacheFilesExit);
        $this->assertSame(2, $cacheStrictExit);
        $this->assertSame(0, $indexRefreshExit);
        $this->assertSame(0, $indexRefreshedSearchExit);
        $this->assertSame(0, $cacheRefreshExit);
        $this->assertSame(0, $cacheRefreshedSearchExit);
        $this->assertStringContainsString('Built AST index for', $stdout);
        $this->assertStringContainsString('src/Ast.php:3:$service = new Service();', $stdout);
        $this->assertStringContainsString('"code": "array(1, 2, 3)"', $stdout);
        $this->assertStringContainsString("src/Ast.php\n", $stdout);
        $this->assertStringContainsString('.hidden/Hidden.php:2:$hidden = new HiddenThing();', $stdout);
        $this->assertStringContainsString('vendor/Ignored.php:2:$ignored = array(1);', $stdout);
        $this->assertStringContainsString('Built AST cache for', $stdout);
        $this->assertStringContainsString('Refreshed AST index for', $stdout);
        $this->assertStringContainsString('src/Newer.php:2:$fresh = new FreshThing();', $stdout);
        $this->assertStringContainsString('Refreshed AST cache for', $stdout);

        $stderr = $this->readStream($harness['stderr']);
        $this->assertStringContainsString('Syntax error', $stderr);
    }

    #[Test]
    public function itRejectsInvalidIndexedCliArguments(): void
    {
        $buildMissingValue = $this->newApplication()['application'];

        try {
            $buildMissingValue->run(['greph-index', 'build', '--index-dir']);
            self::fail('Expected missing build --index-dir value.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Missing value for --index-dir.', $exception->getMessage());
        }

        $buildUnknown = $this->newApplication()['application'];

        try {
            $buildUnknown->run(['greph-index', 'build', '--bogus']);
            self::fail('Expected unknown build flag.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Unknown argument: --bogus', $exception->getMessage());
        }

        $searchMissingValue = $this->newApplication()['application'];

        try {
            $searchMissingValue->run(['greph-index', 'search', '--glob']);
            self::fail('Expected missing --glob value.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Missing value for --glob.', $exception->getMessage());
        }

        $searchUnknown = $this->newApplication()['application'];

        try {
            $searchUnknown->run(['greph-index', 'search', '--bogus', 'needle', '.']);
            self::fail('Expected unknown search flag.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Unknown argument: --bogus', $exception->getMessage());
        }

        $astSearchUnknown = $this->newApplication()['application'];

        try {
            $astSearchUnknown->run(['greph-index', 'ast-index', 'search', '--fallback', 'bogus', 'new $CLASS()', '.']);
            self::fail('Expected unknown AST fallback mode.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Unknown fallback mode: bogus', $exception->getMessage());
        }
    }

    #[Test]
    public function itCoversIndexedPrivateParsingBranches(): void
    {
        $application = $this->newApplication()['application'];

        $parsedFlags = $this->invokeMethod(
            $application,
            'parseSearchArguments',
            ['-w', '-v', '-n', '-A', '2', '-B', '1', '-C', '3', '--type', 'php', '--type-not', 'txt', 'needle'],
        );
        $parsedTerminated = $this->invokeMethod(
            $application,
            'parseSearchArguments',
            ['--', 'needle', 'single.txt', 'counts.txt'],
        );
        $parsedAst = $this->invokeMethod(
            $application,
            'parseAstSearchArguments',
            ['--json', '--hidden', '--strict-parse', '-l', '--glob', '*.php', '--type', 'php', '--type-not', 'txt', '--index-dir', '.ast', '--lang', 'php', '-j', '4', '--fallback', 'scan', 'new $CLASS()', 'src/Ast.php'],
        );
        $displayNames = $this->invokeMethod(
            $application,
            'shouldDisplayFileNames',
            ['paths' => ['single.txt', 'counts.txt'], 'showFileNames' => null],
        );

        $this->assertTrue($parsedFlags['wholeWord']);
        $this->assertTrue($parsedFlags['invertMatch']);
        $this->assertTrue($parsedFlags['showLineNumbers']);
        $this->assertSame(2, $parsedFlags['afterContext']);
        $this->assertSame(1, $parsedFlags['beforeContext']);
        $this->assertSame(3, $parsedFlags['context']);
        $this->assertSame(['php'], $parsedFlags['type']);
        $this->assertSame(['txt'], $parsedFlags['typeNot']);
        $this->assertSame('needle', $parsedTerminated['pattern']);
        $this->assertSame(['single.txt', 'counts.txt'], $parsedTerminated['paths']);
        $this->assertTrue($parsedAst['json']);
        $this->assertTrue($parsedAst['hidden']);
        $this->assertTrue($parsedAst['strictParse']);
        $this->assertTrue($parsedAst['filesWithMatches']);
        $this->assertSame(['*.php'], $parsedAst['glob']);
        $this->assertSame(['php'], $parsedAst['type']);
        $this->assertSame(['txt'], $parsedAst['typeNot']);
        $this->assertSame('.ast', $parsedAst['indexDir']);
        $this->assertSame('php', $parsedAst['lang']);
        $this->assertSame(4, $parsedAst['jobs']);
        $this->assertSame('scan', $parsedAst['fallback']);
        $this->assertSame('new $CLASS()', $parsedAst['pattern']);
        $this->assertSame(['src/Ast.php'], $parsedAst['paths']);
        $this->assertTrue($displayNames);
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
