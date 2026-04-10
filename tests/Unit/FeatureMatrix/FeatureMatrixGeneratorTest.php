<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\FeatureMatrix;

use Greph\FeatureMatrix\FeatureMatrixGenerator;
use Greph\Support\ProcessResult;
use Greph\Support\ToolResolver;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FeatureMatrixGeneratorTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__, 3);
    }

    #[Test]
    public function itCoversWorkspaceProbeProviderAndWritePaths(): void
    {
        $generator = new FeatureMatrixGenerator(
            $this->rootPath,
            toolResolver: new ToolResolver(static fn (string $candidate): ?string => null),
        );

        $unavailable = $this->invokeMethod(
            $generator,
            'runWorkspaceProbe',
            null,
            static fn (): array => [],
        );
        $failure = $this->invokeMethod(
            $generator,
            'runWorkspaceProbe',
            [PHP_BINARY],
            static function (FeatureMatrixGenerator $generator, array $commandPrefix, string $workspace): array {
                throw new \RuntimeException('boom');
            },
        );
        $resolvedProvider = $this->invokeMethod(
            $generator,
            'optionalProvider',
            static fn (ToolResolver $toolResolver): array => ['php', 'tool'],
        );
        $missingProvider = $this->invokeMethod(
            $generator,
            'optionalProvider',
            static function (ToolResolver $toolResolver): array {
                throw new \RuntimeException('missing');
            },
        );

        $outputWorkspace = Workspace::createDirectory('feature-matrix-write');
        $markdownPath = $outputWorkspace . '/FEATURE_MATRIX.md';
        $jsonPath = $outputWorkspace . '/FEATURE_MATRIX.json';

        try {
            $report = $generator->write($markdownPath, $jsonPath);
        } finally {
            $markdown = file_exists($markdownPath) ? (string) file_get_contents($markdownPath) : '';
            $json = file_exists($jsonPath) ? (string) file_get_contents($jsonPath) : '';
            Workspace::remove($outputWorkspace);
        }

        $this->assertSame('Unavailable', $unavailable['status']);
        $this->assertSame('Provider command was not available in this environment.', $unavailable['note']);
        $this->assertSame('Fail', $failure['status']);
        $this->assertSame('boom', $failure['note']);
        $this->assertSame([PHP_BINARY], $failure['command']);
        $this->assertSame(['php', 'tool'], $resolvedProvider);
        $this->assertNull($missingProvider);
        $this->assertArrayHasKey('generated_at', $report);
        $this->assertStringContainsString('# Feature Matrix', $markdown);
        $this->assertStringContainsString('"sections"', $json);
    }

    #[Test]
    public function itCoversNormalizationMarkdownAndValidatorHelpers(): void
    {
        $generator = new FeatureMatrixGenerator($this->rootPath);
        $workspace = Workspace::createDirectory('feature-matrix-helper');

        try {
            $normalized = $this->invokeMethod(
                $generator,
                'normalizeOutput',
                $workspace . '/path ' . $this->rootPath . str_repeat('x', 700),
                $workspace,
            );
            $markdown = $generator->renderMarkdown([
                'generated_at' => '2026-04-10T00:00:00Z',
                'sections' => [[
                    'title' => 'Helpers',
                    'providers' => ['one', 'two'],
                    'rows' => [[
                        'feature' => 'Render',
                        'notes' => 'Probe notes',
                        'results' => [
                            'one' => ['status' => 'Fail', 'command' => null, 'exit_code' => 1, 'stdout' => '', 'stderr' => '', 'note' => "bad|note\nwrapped"],
                            'two' => ['status' => 'Unavailable', 'command' => null, 'exit_code' => null, 'stdout' => '', 'stderr' => '', 'note' => "missing|tool\nwrapped"],
                        ],
                    ]],
                ]],
            ]);
            $singleArgValidation = $this->invokeMethod(
                $generator,
                'invokeValidator',
                static fn (ProcessResult $result): ?string => $result->exitCode === 0 ? null : 'bad',
                new ProcessResult(1, '', '', 0.1),
                $workspace,
            );
            $twoArgValidation = $this->invokeMethod(
                $generator,
                'invokeValidator',
                static fn (ProcessResult $result, string $path): ?string => str_contains($path, 'feature-matrix-helper') ? null : 'bad',
                new ProcessResult(0, '', '', 0.1),
                $workspace,
            );
            $firstFailureHit = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'firstFailure',
                null,
                'first',
                'second',
            );
            $firstFailureMiss = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'firstFailure',
                null,
                null,
            );
            $exitFailure = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectExitCode',
                new ProcessResult(1, '', '', 0.1),
                0,
            );
            $exitSuccess = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectExitCode',
                new ProcessResult(0, '', '', 0.1),
                0,
            );
            $containsExit = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectContainsAllAndExcludes',
                new ProcessResult(1, '', '', 0.1),
                0,
                ['needle'],
                ['other'],
                'missing',
                'forbidden',
            );
            $containsMissing = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectContainsAllAndExcludes',
                new ProcessResult(0, 'hay', '', 0.1),
                0,
                ['needle'],
                [],
                'missing',
                '',
            );
            $containsForbidden = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectContainsAllAndExcludes',
                new ProcessResult(0, "needle\nother\n", '', 0.1),
                0,
                ['needle'],
                ['other'],
                'missing',
                'forbidden',
            );
            $containsSuccess = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectContainsAllAndExcludes',
                new ProcessResult(0, "needle\n", '', 0.1),
                0,
                ['needle'],
                ['other'],
                'missing',
                'forbidden',
            );
            Workspace::writeFile($workspace, 'present-miss.txt', "other\n");
            $workspaceMissing = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectWorkspaceFileContains',
                $workspace,
                'present-miss.txt',
                'needle',
                'workspace-missing',
            );
            Workspace::writeFile($workspace, 'present.txt', "needle\n");
            $workspaceSuccess = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectWorkspaceFileContains',
                $workspace,
                'present.txt',
                'needle',
                'workspace-missing',
            );
            $anyOutputFailure = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectAnyOutput',
                new ProcessResult(0, '', '', 0.1),
                'need-output',
            );
            $anyOutputSuccess = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectAnyOutput',
                new ProcessResult(0, 'output', '', 0.1),
                'need-output',
            );
            $fileCountExit = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectPositiveReportedFileCount',
                new ProcessResult(1, '{}', '', 0.1),
                'bad-count',
            );
            $fileCountInvalid = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectPositiveReportedFileCount',
                new ProcessResult(0, '{"file_count":0}', '', 0.1),
                'bad-count',
            );
            $fileCountSuccess = $this->invokeStaticMethod(
                FeatureMatrixGenerator::class,
                'expectPositiveReportedFileCount',
                new ProcessResult(0, '{"file_count":2}', '', 0.1),
                'bad-count',
            );

            $this->assertStringContainsString('<workspace>/path <greph>', $normalized);
            $this->assertStringContainsString('...[truncated]', $normalized);
            $this->assertStringContainsString('Fail<br><sub>bad\|note wrapped</sub>', $markdown);
            $this->assertStringContainsString('Unavailable<br><sub>missing\|tool wrapped</sub>', $markdown);
            $this->assertSame('bad', $singleArgValidation);
            $this->assertNull($twoArgValidation);
            $this->assertSame('first', $firstFailureHit);
            $this->assertNull($firstFailureMiss);
            $this->assertSame('Expected exit 0, got 1.', $exitFailure);
            $this->assertNull($exitSuccess);
            $this->assertSame('Expected exit 0, got 1.', $containsExit);
            $this->assertSame('missing', $containsMissing);
            $this->assertSame('forbidden', $containsForbidden);
            $this->assertNull($containsSuccess);
            $this->assertSame('workspace-missing', $workspaceMissing);
            $this->assertNull($workspaceSuccess);
            $this->assertSame('need-output', $anyOutputFailure);
            $this->assertNull($anyOutputSuccess);
            $this->assertSame('Expected exit 0, got 1.', $fileCountExit);
            $this->assertSame('bad-count', $fileCountInvalid);
            $this->assertNull($fileCountSuccess);
        } finally {
            Workspace::remove($workspace);
        }

        $exitMismatch = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectExitAndStdoutContains',
            new ProcessResult(1, 'needle', '', 0.1),
            0,
            'needle',
        );
        $missingNeedle = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectExitAndStdoutContains',
            new ProcessResult(0, 'hay', '', 0.1),
            0,
            'needle',
        );
        $wrongLines = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectExactOutputLines',
            new ProcessResult(0, "wrong\n", '', 0.1),
            ['needle'],
        );
        $wrongLinesExit = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectExactOutputLines',
            new ProcessResult(1, "needle\n", '', 0.1),
            ['needle'],
        );
        $wrongCount = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectCountOutput',
            new ProcessResult(0, "3\n", '', 0.1),
            '2',
        );
        $wrongCountExit = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectCountOutput',
            new ProcessResult(1, "2\n", '', 0.1),
            '2',
        );
        $missingContext = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectContextOutput',
            new ProcessResult(0, "before\nneedle\n", '', 0.1),
        );
        $missingContextExit = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectContextOutput',
            new ProcessResult(1, "before\nneedle\nafter\n", '', 0.1),
        );
        $multipleLines = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectSingleMatchLine',
            new ProcessResult(0, "one\ntwo\n", '', 0.1),
        );
        $multipleLinesExit = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectSingleMatchLine',
            new ProcessResult(1, "one\n", '', 0.1),
        );
        $missingGlobInclude = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectGlobFilteredOutput',
            new ProcessResult(0, "src/Other.txt\n", '', 0.1),
        );
        $excludedGlob = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectGlobFilteredOutput',
            new ProcessResult(0, "src/App.php\nsrc/Other.txt\n", '', 0.1),
        );
        $globExit = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectGlobFilteredOutput',
            new ProcessResult(1, "src/App.php\n", '', 0.1),
        );
        $filesModeHidden = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectFilesModeOutput',
            new ProcessResult(0, "single.txt\n.hidden/secret.txt\n", '', 0.1),
        );
        $filesModeMissing = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectFilesModeOutput',
            new ProcessResult(0, "counts.txt\n", '', 0.1),
        );
        $filesModeExit = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectFilesModeOutput',
            new ProcessResult(1, "single.txt\n", '', 0.1),
        );
        $jsonLinesInvalid = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectRipgrepJsonStream',
            new ProcessResult(0, "not-json\n", '', 0.1),
        );
        $jsonLinesExit = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectRipgrepJsonStream',
            new ProcessResult(1, "{\"type\":\"match\"}\n", '', 0.1),
        );
        $jsonLinesEmpty = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectRipgrepJsonStream',
            new ProcessResult(0, "\n", '', 0.1),
        );
        $jsonLinesNoMatch = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectRipgrepJsonStream',
            new ProcessResult(0, "{\"type\":\"begin\"}\n", '', 0.1),
        );
        $structuredEmpty = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectStructuredJson',
            new ProcessResult(0, '', '', 0.1),
        );
        $structuredZero = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectStructuredJson',
            new ProcessResult(0, "0\n", '', 0.1),
        );
        $structuredInvalidLine = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectStructuredJson',
            new ProcessResult(0, "not-json\n", '', 0.1),
        );
        $structuredLinesSuccess = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectStructuredJson',
            new ProcessResult(0, "{\"file\":\"one\"}\n{\"file\":\"two\"}\n", '', 0.1),
        );
        $structuredExit = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectStructuredJson',
            new ProcessResult(1, '[]', '', 0.1),
        );
        $grephInvalid = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectGrephTextJson',
            new ProcessResult(0, '{}', '', 0.1),
        );
        $grephExit = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectGrephTextJson',
            new ProcessResult(1, '[]', '', 0.1),
        );
        $grephMissingFile = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectGrephTextJson',
            new ProcessResult(0, '[{"file":"other.txt"}]', '', 0.1),
        );
        $grephNonArray = $this->invokeStaticMethod(
            FeatureMatrixGenerator::class,
            'expectGrephTextJson',
            new ProcessResult(0, 'null', '', 0.1),
        );

        $this->assertSame('Expected exit 0, got 1.', $exitMismatch);
        $this->assertSame('Expected stdout to contain `needle`.', $missingNeedle);
        $this->assertSame('Expected output lines `needle`, got `wrong`.', $wrongLines);
        $this->assertSame('Expected exit 0, got 1.', $wrongLinesExit);
        $this->assertSame('Expected count output `2`, got `3`.', $wrongCount);
        $this->assertSame('Expected exit 0, got 1.', $wrongCountExit);
        $this->assertSame('Expected context output to contain `after`.', $missingContext);
        $this->assertSame('Expected exit 0, got 1.', $missingContextExit);
        $this->assertSame('Expected exactly one output line, got 2.', $multipleLines);
        $this->assertSame('Expected exit 0, got 1.', $multipleLinesExit);
        $this->assertSame('Expected filtered output to include `src/App.php`.', $missingGlobInclude);
        $this->assertSame('Expected filtered output to exclude `src/Other.txt`.', $excludedGlob);
        $this->assertSame('Expected exit 0, got 1.', $globExit);
        $this->assertSame('Expected files output to exclude hidden files by default.', $filesModeHidden);
        $this->assertSame('Expected files output to include `single.txt`.', $filesModeMissing);
        $this->assertSame('Expected exit 0, got 1.', $filesModeExit);
        $this->assertSame('Expected newline-delimited JSON events.', $jsonLinesInvalid);
        $this->assertSame('Expected exit 0, got 1.', $jsonLinesExit);
        $this->assertSame('Expected JSON-event output lines.', $jsonLinesEmpty);
        $this->assertSame('Expected at least one `match` JSON event.', $jsonLinesNoMatch);
        $this->assertSame('Expected JSON output, got empty stdout.', $structuredEmpty);
        $this->assertSame('Expected JSON output lines.', $structuredZero);
        $this->assertSame('Expected JSON object or JSON lines output.', $structuredInvalidLine);
        $this->assertNull($structuredLinesSuccess);
        $this->assertSame('Expected exit 0, got 1.', $structuredExit);
        $this->assertSame('Expected JSON payload to include `single.txt`.', $grephInvalid);
        $this->assertSame('Expected exit 0, got 1.', $grephExit);
        $this->assertSame('Expected JSON payload to include `single.txt`.', $grephMissingFile);
        $this->assertSame('Expected greph JSON array output.', $grephNonArray);
    }

    #[Test]
    public function itCoversIndexedProbeFailurePaths(): void
    {
        $workspace = Workspace::createDirectory('feature-matrix-scripts');
        $buildFailureScript = Workspace::writeFile($workspace, 'build-fail.php', <<<'PHP'
<?php
$command = $argv[1] ?? '';
if ($command === 'build') {
    fwrite(STDOUT, "build failed\n");
    exit(1);
}
fwrite(STDOUT, "ok\n");
PHP);
        $refreshFailureScript = Workspace::writeFile($workspace, 'refresh-fail.php', <<<'PHP'
<?php
$command = $argv[1] ?? '';
if ($command === 'build') {
    fwrite(STDOUT, "built\n");
    exit(0);
}
if ($command === 'refresh') {
    fwrite(STDOUT, "refresh failed\n");
    exit(1);
}
fwrite(STDOUT, "refreshed needle\n");
PHP);
        $generator = new FeatureMatrixGenerator($this->rootPath);

        try {
            $refreshBuildFailure = $this->invokeMethod(
                $generator,
                'runIndexedRefreshProbe',
                [PHP_BINARY, $buildFailureScript],
            );
            $searchBuildFailure = $this->invokeMethod(
                $generator,
                'runIndexedSearchProbe',
                [PHP_BINARY, $buildFailureScript],
                ['search', '-F', 'needle', '.'],
                static fn (ProcessResult $result): ?string => null,
            );
            $refreshFailure = $this->invokeMethod(
                $generator,
                'runIndexedRefreshProbe',
                [PHP_BINARY, $refreshFailureScript],
            );

            $this->assertSame('Fail', $refreshBuildFailure['status']);
            $this->assertSame('Initial indexed build failed.', $refreshBuildFailure['note']);
            $this->assertSame('Fail', $searchBuildFailure['status']);
            $this->assertSame('Initial indexed build failed.', $searchBuildFailure['note']);
            $this->assertSame('Fail', $refreshFailure['status']);
            $this->assertSame('Indexed refresh failed.', $refreshFailure['note']);
        } finally {
            Workspace::remove($workspace);
        }
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

    /**
     * @return mixed
     */
    private function invokeStaticMethod(string $class, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$arguments);
    }
}
