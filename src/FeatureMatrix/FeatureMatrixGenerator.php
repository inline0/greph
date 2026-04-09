<?php

declare(strict_types=1);

namespace Phgrep\FeatureMatrix;

use Phgrep\Support\CommandRunner;
use Phgrep\Support\Filesystem;
use Phgrep\Support\ProcessResult;
use Phgrep\Support\ToolResolver;

final class FeatureMatrixGenerator
{
    private const STATUS_PASS = 'Pass';
    private const STATUS_FAIL = 'Fail';
    private const STATUS_UNAVAILABLE = 'Unavailable';

    private CommandRunner $commandRunner;

    private ToolResolver $toolResolver;

    public function __construct(
        private readonly string $rootPath,
        ?CommandRunner $commandRunner = null,
        ?ToolResolver $toolResolver = null,
    ) {
        $this->commandRunner = $commandRunner ?? new CommandRunner();
        $this->toolResolver = $toolResolver ?? new ToolResolver();
    }

    /**
     * @return array{
     *   generated_at: string,
     *   root_path: string,
     *   sections: list<array{
     *     title: string,
     *     providers: list<string>,
     *     rows: list<array{
     *       feature: string,
     *       notes: string,
     *       results: array<string, array{
     *         status: string,
     *         command: ?list<string>,
     *         exit_code: ?int,
     *         stdout: string,
     *         stderr: string,
     *         note: string
     *       }>
     *     }>
     *   }>
     * }
     */
    public function generate(): array
    {
        $providers = $this->providers();

        return [
            'generated_at' => gmdate('c'),
            'root_path' => $this->rootPath,
            'sections' => [
                $this->generateRgCompatibilitySection($providers),
                $this->generateSgCompatibilitySection($providers),
                $this->generateNativePhgrepSection($providers),
                $this->generateIndexedSection($providers),
            ],
        ];
    }

    /**
     * @param array<string, list<string>|null> $providers
     * @return array{
     *   title: string,
     *   providers: list<string>,
     *   rows: list<array{
     *     feature: string,
     *     notes: string,
     *     results: array<string, array{
     *       status: string,
     *       command: ?list<string>,
     *       exit_code: ?int,
     *       stdout: string,
     *       stderr: string,
     *       note: string
     *     }>
     *   }>
     * }
     */
    private function generateRgCompatibilitySection(array $providers): array
    {
        $sectionProviders = ['rg', 'bin/rg'];

        return [
            'title' => 'rg Compatibility Surface',
            'providers' => $sectionProviders,
            'rows' => [
                $this->buildRow(
                    'Fixed-string search',
                    'Probe: `-F needle single.txt`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-F', 'needle', 'single.txt'],
                        static fn (ProcessResult $result): ?string => self::expectExactOutputLines($result, ['needle']),
                    ),
                ),
                $this->buildRow(
                    'Case-insensitive fixed-string search',
                    'Probe: `-F -i needle single.txt`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-F', '-i', 'needle', 'single.txt'],
                        static fn (ProcessResult $result): ?string => self::expectExactOutputLines($result, ['needle', 'NEEDLE']),
                    ),
                ),
                $this->buildRow(
                    'Whole-word search',
                    'Probe: `-F -w needle words.txt`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-F', '-w', 'needle', 'words.txt'],
                        static fn (ProcessResult $result): ?string => self::expectExactOutputLines($result, ['needle']),
                    ),
                ),
                $this->buildRow(
                    'Invert match',
                    'Probe: `-F -v needle invert.txt`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-F', '-v', 'needle', 'invert.txt'],
                        static fn (ProcessResult $result): ?string => self::expectExactOutputLines($result, ['hay']),
                    ),
                ),
                $this->buildRow(
                    'Count mode',
                    'Probe: `-F -c needle counts.txt`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-F', '-c', 'needle', 'counts.txt'],
                        static fn (ProcessResult $result): ?string => self::expectCountOutput($result, '2'),
                    ),
                ),
                $this->buildRow(
                    'Files with matches',
                    'Probe: `-F -l needle .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-F', '-l', 'needle', '.'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, 'single.txt'),
                    ),
                ),
                $this->buildRow(
                    'Files without matches',
                    'Probe: `-F --files-without-match needle .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-F', '--files-without-match', 'needle', '.'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, 'unmatched.txt'),
                    ),
                ),
                $this->buildRow(
                    'Context lines',
                    'Probe: `-F -C 1 needle context.txt`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-F', '-C', '1', 'needle', 'context.txt'],
                        static fn (ProcessResult $result): ?string => self::expectContextOutput($result),
                    ),
                ),
                $this->buildRow(
                    'Max count',
                    'Probe: `-F -m 1 needle counts.txt`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-F', '-m', '1', 'needle', 'counts.txt'],
                        static fn (ProcessResult $result): ?string => self::expectSingleMatchLine($result),
                    ),
                ),
                $this->buildRow(
                    'Glob filter',
                    'Probe: `--glob *.php function .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['--glob', '*.php', 'function', '.'],
                        static fn (ProcessResult $result): ?string => self::expectGlobFilteredOutput($result),
                    ),
                ),
                $this->buildRow(
                    'Type filter',
                    'Probe: `--type php function .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['--type', 'php', 'function', '.'],
                        static fn (ProcessResult $result): ?string => self::expectGlobFilteredOutput($result),
                    ),
                ),
                $this->buildRow(
                    'Hidden files',
                    'Probe: `--hidden -F secret .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['--hidden', '-F', 'secret', '.'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, '.hidden/secret.txt'),
                    ),
                ),
                $this->buildRow(
                    'No ignore',
                    'Probe: `--no-ignore -F ignored .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['--no-ignore', '-F', 'ignored', '.'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, 'vendor/ignored.txt'),
                    ),
                ),
                $this->buildRow(
                    '--files mode',
                    'Probe: `--files .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['--files', '.'],
                        static fn (ProcessResult $result): ?string => self::expectFilesModeOutput($result),
                    ),
                ),
                $this->buildRow(
                    'Structured JSON output',
                    'Probe: `--json -F needle single.txt` using ripgrep JSON-event semantics',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['--json', '-F', 'needle', 'single.txt'],
                        static fn (ProcessResult $result): ?string => self::expectRipgrepJsonStream($result),
                    ),
                ),
            ],
        ];
    }

    /**
     * @param array<string, list<string>|null> $providers
     * @return array{
     *   title: string,
     *   providers: list<string>,
     *   rows: list<array{
     *     feature: string,
     *     notes: string,
     *     results: array<string, array{
     *       status: string,
     *       command: ?list<string>,
     *       exit_code: ?int,
     *       stdout: string,
     *       stderr: string,
     *       note: string
     *     }>
     *   }>
     * }
     */
    private function generateSgCompatibilitySection(array $providers): array
    {
        $sectionProviders = ['sg', 'bin/sg'];

        return [
            'title' => 'sg Compatibility Surface',
            'providers' => $sectionProviders,
            'rows' => [
                $this->buildRow(
                    'Pattern search with `run --pattern`',
                    'Probe: `run --pattern array($$$ITEMS) src/App.php`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['run', '--pattern', 'array($$$ITEMS)', 'src/App.php'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, 'array(1, 2, 3)'),
                    ),
                ),
                $this->buildRow(
                    'Rewrite via `run --rewrite`',
                    'Probe: `run --pattern array($$$ITEMS) --rewrite [$$$ITEMS] src/App.php`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['run', '--pattern', 'array($$$ITEMS)', '--rewrite', '[$$$ITEMS]', 'src/App.php'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, '[1, 2, 3]'),
                    ),
                ),
                $this->buildRow(
                    'Structured JSON output',
                    'Probe: `run --json --pattern dispatch($EVENT) src/App.php`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['run', '--json', '--pattern', 'dispatch($EVENT)', 'src/App.php'],
                        static fn (ProcessResult $result): ?string => self::expectStructuredJson($result),
                    ),
                ),
                $this->buildRow(
                    'Rewrite dry-run',
                    'Probe: `run --pattern array($$$ITEMS) --rewrite [$$$ITEMS] src/App.php` without `--update-all`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['run', '--pattern', 'array($$$ITEMS)', '--rewrite', '[$$$ITEMS]', 'src/App.php'],
                        static function (ProcessResult $result, string $workspace): ?string {
                            if ($result->exitCode !== 0) {
                                return sprintf('Expected exit 0, got %d.', $result->exitCode);
                            }

                            if (!str_contains($result->stdout, '[1, 2, 3]')) {
                                return 'Expected dry-run output to contain rewritten code.';
                            }

                            $contents = file_get_contents($workspace . '/src/App.php') ?: '';

                            if (!str_contains($contents, 'array(1, 2, 3)')) {
                                return 'Expected source file to remain unchanged without `--update-all`.';
                            }

                            return null;
                        },
                    ),
                ),
                $this->buildRow(
                    'Interactive rewrite accept',
                    'Probe: `run --pattern array($$$ITEMS) --rewrite [$$$ITEMS] --interactive src/App.php` with `y`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['run', '--pattern', 'array($$$ITEMS)', '--rewrite', '[$$$ITEMS]', '--interactive', 'src/App.php'],
                        static function (ProcessResult $result, string $workspace): ?string {
                            if ($result->exitCode !== 0) {
                                return sprintf('Expected exit 0, got %d.', $result->exitCode);
                            }

                            $contents = file_get_contents($workspace . '/src/App.php') ?: '';

                            if (!str_contains($contents, '[1, 2, 3]')) {
                                return 'Expected interactive rewrite accept to update the file.';
                            }

                            return null;
                        },
                        "y\n",
                    ),
                ),
                $this->buildRow(
                    'Interactive rewrite decline',
                    'Probe: `run --pattern array($$$ITEMS) --rewrite [$$$ITEMS] --interactive src/App.php` with `n`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['run', '--pattern', 'array($$$ITEMS)', '--rewrite', '[$$$ITEMS]', '--interactive', 'src/App.php'],
                        static function (ProcessResult $result, string $workspace): ?string {
                            if ($result->exitCode !== 0) {
                                return sprintf('Expected exit 0, got %d.', $result->exitCode);
                            }

                            $contents = file_get_contents($workspace . '/src/App.php') ?: '';

                            if (!str_contains($contents, 'array(1, 2, 3)')) {
                                return 'Expected interactive rewrite decline to leave the file unchanged.';
                            }

                            return null;
                        },
                        "n\n",
                    ),
                ),
                $this->buildRow(
                    'Explicit PHP language flag',
                    'Probe: `run --lang php --pattern $CLIENT->send($MESSAGE) src/App.php`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['run', '--lang', 'php', '--pattern', '$CLIENT->send($MESSAGE)', 'src/App.php'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, '$client->send($message)'),
                    ),
                ),
            ],
        ];
    }

    /**
     * @param array<string, list<string>|null> $providers
     * @return array{
     *   title: string,
     *   providers: list<string>,
     *   rows: list<array{
     *     feature: string,
     *     notes: string,
     *     results: array<string, array{
     *       status: string,
     *       command: ?list<string>,
     *       exit_code: ?int,
     *       stdout: string,
     *       stderr: string,
     *       note: string
     *     }>
     *   }>
     * }
     */
    private function generateNativePhgrepSection(array $providers): array
    {
        $sectionProviders = ['bin/phgrep'];

        return [
            'title' => 'Native phgrep Surface',
            'providers' => $sectionProviders,
            'rows' => [
                $this->buildRow(
                    'Native text JSON output',
                    'Probe: `-F --json needle .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-F', '--json', 'needle', '.'],
                        static fn (ProcessResult $result): ?string => self::expectPhgrepTextJson($result),
                    ),
                ),
                $this->buildRow(
                    'Native AST JSON output',
                    'Probe: `-p dispatch($EVENT) --json src/App.php`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-p', 'dispatch($EVENT)', '--json', 'src/App.php'],
                        static fn (ProcessResult $result): ?string => self::expectStructuredJson($result),
                    ),
                ),
                $this->buildRow(
                    'Native AST rewrite dry-run',
                    'Probe: `-p array($$$ITEMS) -r [$$$ITEMS] --dry-run src/App.php`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runCommandProbe(
                        $providers[$provider],
                        ['-p', 'array($$$ITEMS)', '-r', '[$$$ITEMS]', '--dry-run', 'src/App.php'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, '[1, 2, 3]'),
                    ),
                ),
            ],
        ];
    }

    /**
     * @param array<string, list<string>|null> $providers
     * @return array{
     *   title: string,
     *   providers: list<string>,
     *   rows: list<array{
     *     feature: string,
     *     notes: string,
     *     results: array<string, array{
     *       status: string,
     *       command: ?list<string>,
     *       exit_code: ?int,
     *       stdout: string,
     *       stderr: string,
     *       note: string
     *     }>
     *   }>
     * }
     */
    private function generateIndexedSection(array $providers): array
    {
        $sectionProviders = ['bin/phgrep-index'];

        return [
            'title' => 'Indexed phgrep Surface',
            'providers' => $sectionProviders,
            'rows' => [
                $this->buildRow(
                    'Index build',
                    'Probe: `build .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runIndexedBuildProbe($providers[$provider]),
                ),
                $this->buildRow(
                    'Index refresh',
                    'Probe: `refresh .` after editing a tracked file',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runIndexedRefreshProbe($providers[$provider]),
                ),
                $this->buildRow(
                    'Indexed fixed-string search',
                    'Probe: `search -F needle .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runIndexedSearchProbe(
                        $providers[$provider],
                        ['search', '-F', 'needle', '.'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, 'single.txt:2:needle'),
                    ),
                ),
                $this->buildRow(
                    'Indexed regex search',
                    'Probe: `search new\\s+instance .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runIndexedSearchProbe(
                        $providers[$provider],
                        ['search', 'new\\s+instance', 'notes.txt'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, 'new instance'),
                    ),
                ),
                $this->buildRow(
                    'Indexed count mode',
                    'Probe: `search -F -c needle counts.txt`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runIndexedSearchProbe(
                        $providers[$provider],
                        ['search', '-F', '-c', 'needle', 'counts.txt'],
                        static fn (ProcessResult $result): ?string => self::expectCountOutput($result, '2'),
                    ),
                ),
                $this->buildRow(
                    'Indexed files with matches',
                    'Probe: `search -F -l needle .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runIndexedSearchProbe(
                        $providers[$provider],
                        ['search', '-F', '-l', 'needle', '.'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, 'single.txt'),
                    ),
                ),
                $this->buildRow(
                    'Indexed files without matches',
                    'Probe: `search -F -L needle .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runIndexedSearchProbe(
                        $providers[$provider],
                        ['search', '-F', '-L', 'needle', '.'],
                        static fn (ProcessResult $result): ?string => self::expectExitAndStdoutContains($result, 0, 'unmatched.txt'),
                    ),
                ),
                $this->buildRow(
                    'Indexed JSON output',
                    'Probe: `search -F --json needle .`',
                    $sectionProviders,
                    static fn (self $generator, string $provider): array => $generator->runIndexedSearchProbe(
                        $providers[$provider],
                        ['search', '-F', '--json', 'needle', '.'],
                        static fn (ProcessResult $result): ?string => self::expectPhgrepTextJson($result),
                    ),
                ),
            ],
        ];
    }

    /**
     * @param list<string> $providers
     * @param callable(self, string): array<string, mixed> $probe
     * @return array{
     *   feature: string,
     *   notes: string,
     *   results: array<string, array{
     *     status: string,
     *     command: ?list<string>,
     *     exit_code: ?int,
     *     stdout: string,
     *     stderr: string,
     *     note: string
     *   }>
     * }
     */
    private function buildRow(string $feature, string $notes, array $providers, callable $probe): array
    {
        $results = [];

        foreach ($providers as $provider) {
            /** @var array{
             *   status: string,
             *   command: ?list<string>,
             *   exit_code: ?int,
             *   stdout: string,
             *   stderr: string,
             *   note: string
             * } $probed
             */
            $probed = $probe($this, $provider);
            $results[$provider] = $probed;
        }

        return [
            'feature' => $feature,
            'notes' => $notes,
            'results' => $results,
        ];
    }

    /**
     * @param list<string>|null $commandPrefix
     * @param list<string> $arguments
     * @param callable(ProcessResult, string): ?string|callable(ProcessResult): ?string $validator
     * @return array{
     *   status: string,
     *   command: ?list<string>,
     *   exit_code: ?int,
     *   stdout: string,
     *   stderr: string,
     *   note: string
     * }
     */
    private function runCommandProbe(?array $commandPrefix, array $arguments, callable $validator, string $stdin = ''): array
    {
        return $this->runWorkspaceProbe(
            $commandPrefix,
            static function (self $generator, array $commandPrefix, string $workspace) use ($arguments, $validator, $stdin): array {
                /** @var list<string> $command */
                $command = array_values(array_merge($commandPrefix, $arguments));
                $result = $generator->commandRunner->run($command, $workspace, [], $stdin);

                return $generator->finalizeProbe(
                    $result,
                    $command,
                    $workspace,
                    $generator->invokeValidator($validator, $result, $workspace),
                );
            },
        );
    }

    /**
     * @param list<string>|null $commandPrefix
     * @return array{
     *   status: string,
     *   command: ?list<string>,
     *   exit_code: ?int,
     *   stdout: string,
     *   stderr: string,
     *   note: string
     * }
     */
    private function runIndexedBuildProbe(?array $commandPrefix): array
    {
        return $this->runWorkspaceProbe(
            $commandPrefix,
            static function (self $generator, array $commandPrefix, string $workspace): array {
                /** @var list<string> $command */
                $command = array_values(array_merge($commandPrefix, ['build', '.']));
                $result = $generator->commandRunner->run($command, $workspace);

                return $generator->finalizeProbe(
                    $result,
                    $command,
                    $workspace,
                    self::expectExitAndStdoutContains($result, 0, 'Built index for'),
                );
            },
        );
    }

    /**
     * @param list<string>|null $commandPrefix
     * @return array{
     *   status: string,
     *   command: ?list<string>,
     *   exit_code: ?int,
     *   stdout: string,
     *   stderr: string,
     *   note: string
     * }
     */
    private function runIndexedRefreshProbe(?array $commandPrefix): array
    {
        return $this->runWorkspaceProbe(
            $commandPrefix,
            static function (self $generator, array $commandPrefix, string $workspace): array {
                /** @var list<string> $buildCommand */
                $buildCommand = array_values(array_merge($commandPrefix, ['build', '.']));
                $buildResult = $generator->commandRunner->run($buildCommand, $workspace);

                if (!$buildResult->successful()) {
                    return $generator->finalizeProbe($buildResult, $buildCommand, $workspace, 'Initial indexed build failed.');
                }

                file_put_contents($workspace . '/notes.txt', "new instance\nrefreshed needle\n", FILE_APPEND);
                /** @var list<string> $command */
                $command = array_values(array_merge($commandPrefix, ['refresh', '.']));
                $result = $generator->commandRunner->run($command, $workspace);

                if (!$result->successful()) {
                    return $generator->finalizeProbe($result, $command, $workspace, 'Indexed refresh failed.');
                }

                /** @var list<string> $searchCommand */
                $searchCommand = array_values(array_merge($commandPrefix, ['search', '-F', 'refreshed needle', '.']));
                $searchResult = $generator->commandRunner->run($searchCommand, $workspace);

                return $generator->finalizeProbe(
                    $searchResult,
                    $searchCommand,
                    $workspace,
                    self::expectExitAndStdoutContains($searchResult, 0, 'refreshed needle'),
                    sprintf(
                        "Build: %s\nRefresh: %s",
                        trim($generator->normalizeOutput($buildResult->stdout, $workspace)),
                        trim($generator->normalizeOutput($result->stdout, $workspace)),
                    ),
                );
            },
        );
    }

    /**
     * @param list<string>|null $commandPrefix
     * @param list<string> $arguments
     * @param callable(ProcessResult, string): ?string|callable(ProcessResult): ?string $validator
     * @return array{
     *   status: string,
     *   command: ?list<string>,
     *   exit_code: ?int,
     *   stdout: string,
     *   stderr: string,
     *   note: string
     * }
     */
    private function runIndexedSearchProbe(?array $commandPrefix, array $arguments, callable $validator): array
    {
        return $this->runWorkspaceProbe(
            $commandPrefix,
            static function (self $generator, array $commandPrefix, string $workspace) use ($arguments, $validator): array {
                /** @var list<string> $buildCommand */
                $buildCommand = array_values(array_merge($commandPrefix, ['build', '.']));
                $buildResult = $generator->commandRunner->run($buildCommand, $workspace);

                if (!$buildResult->successful()) {
                    return $generator->finalizeProbe($buildResult, $buildCommand, $workspace, 'Initial indexed build failed.');
                }

                /** @var list<string> $command */
                $command = array_values(array_merge($commandPrefix, $arguments));
                $result = $generator->commandRunner->run($command, $workspace);

                return $generator->finalizeProbe(
                    $result,
                    $command,
                    $workspace,
                    $generator->invokeValidator($validator, $result, $workspace),
                    trim($generator->normalizeOutput($buildResult->stdout, $workspace)),
                );
            },
        );
    }

    /**
     * @param list<string>|null $commandPrefix
     * @param callable(self, list<string>, string): array{
     *   status: string,
     *   command: ?list<string>,
     *   exit_code: ?int,
     *   stdout: string,
     *   stderr: string,
     *   note: string
     * } $callback
     * @return array{
     *   status: string,
     *   command: ?list<string>,
     *   exit_code: ?int,
     *   stdout: string,
     *   stderr: string,
     *   note: string
     * }
     */
    private function runWorkspaceProbe(?array $commandPrefix, callable $callback): array
    {
        if ($commandPrefix === null) {
            return [
                'status' => self::STATUS_UNAVAILABLE,
                'command' => null,
                'exit_code' => null,
                'stdout' => '',
                'stderr' => '',
                'note' => 'Provider command was not available in this environment.',
            ];
        }

        $workspace = $this->createWorkspace();

        try {
            return $callback($this, $commandPrefix, $workspace);
        } catch (\Throwable $throwable) {
            return [
                'status' => self::STATUS_FAIL,
                'command' => $commandPrefix,
                'exit_code' => null,
                'stdout' => '',
                'stderr' => '',
                'note' => $throwable->getMessage(),
            ];
        } finally {
            Filesystem::remove($workspace);
        }
    }

    /**
     * @param list<string> $command
     * @return array{
     *   status: string,
     *   command: list<string>,
     *   exit_code: int,
     *   stdout: string,
     *   stderr: string,
     *   note: string
     * }
     */
    private function finalizeProbe(
        ProcessResult $result,
        array $command,
        string $workspace,
        ?string $failure,
        string $note = '',
    ): array {
        return [
            'status' => $failure === null ? self::STATUS_PASS : self::STATUS_FAIL,
            'command' => array_values($command),
            'exit_code' => $result->exitCode,
            'stdout' => $this->normalizeOutput($result->stdout, $workspace),
            'stderr' => $this->normalizeOutput($result->stderr, $workspace),
            'note' => $failure ?? $note,
        ];
    }

    private function createWorkspace(): string
    {
        $workspace = sys_get_temp_dir() . '/phgrep-feature-matrix-' . bin2hex(random_bytes(6));

        Filesystem::ensureDirectory($workspace . '/src');
        Filesystem::ensureDirectory($workspace . '/vendor');
        Filesystem::ensureDirectory($workspace . '/.hidden');
        Filesystem::ensureDirectory($workspace . '/.git');

        file_put_contents($workspace . '/.gitignore', "vendor/\n");
        file_put_contents($workspace . '/single.txt', "alpha\nneedle\nNEEDLE\n");
        file_put_contents($workspace . '/words.txt', "needle\nneedles\n");
        file_put_contents($workspace . '/invert.txt', "needle\nhay\n");
        file_put_contents($workspace . '/context.txt', "before\nneedle\nafter\n");
        file_put_contents($workspace . '/counts.txt', "needle\nneedle\n");
        file_put_contents($workspace . '/unmatched.txt', "hay\n");
        file_put_contents($workspace . '/notes.txt', "old instance\nnew instance\n");
        file_put_contents($workspace . '/.hidden/secret.txt', "secret needle\n");
        file_put_contents($workspace . '/vendor/ignored.txt', "ignored needle\n");
        file_put_contents($workspace . '/src/App.php', <<<'PHP'
<?php

function visible(): void {}

$items = array(1, 2, 3);
$client->send($message);
dispatch($event);
PHP);
        file_put_contents($workspace . '/src/Other.txt', "function ignored\n");

        return $workspace;
    }

    /**
     * @return array<string, list<string>|null>
     */
    private function providers(): array
    {
        return [
            'rg' => $this->optionalProvider(static fn (ToolResolver $toolResolver): array => $toolResolver->ripgrep()),
            'bin/rg' => [PHP_BINARY, $this->rootPath . '/bin/rg'],
            'sg' => $this->optionalProvider(static fn (ToolResolver $toolResolver): array => $toolResolver->astGrep()),
            'bin/sg' => [PHP_BINARY, $this->rootPath . '/bin/sg'],
            'bin/phgrep' => [PHP_BINARY, $this->rootPath . '/bin/phgrep'],
            'bin/phgrep-index' => [PHP_BINARY, $this->rootPath . '/bin/phgrep-index'],
        ];
    }

    /**
     * @param callable(ToolResolver): list<string> $resolver
     * @return list<string>|null
     */
    private function optionalProvider(callable $resolver): ?array
    {
        try {
            return $resolver($this->toolResolver);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function normalizeOutput(string $output, string $workspace): string
    {
        $output = str_replace($workspace, '<workspace>', $output);
        $output = str_replace($this->rootPath, '<phgrep>', $output);

        if (strlen($output) <= 600) {
            return $output;
        }

        return substr($output, 0, 600) . "\n...[truncated]\n";
    }

    /**
     * @param array<string, mixed> $report
     */
    public function renderMarkdown(array $report): string
    {
        $lines = [
            '# Feature Matrix',
            '',
            'Generated from live command probes, not hand-maintained guesses.',
            '',
            sprintf(
                'Generated at `%s` from real fixture workspaces. Raw evidence is stored in [FEATURE_MATRIX.json](FEATURE_MATRIX.json).',
                $report['generated_at'],
            ),
            '',
            'Status legend:',
            '- `Pass`: the command probe succeeded.',
            '- `Fail`: the command probe ran but did not satisfy the expected behavior.',
            '- `Unavailable`: the provider command was not available in this environment.',
            '',
        ];

        foreach ($report['sections'] as $section) {
            $lines[] = '## ' . $section['title'];
            $lines[] = '';

            $header = array_merge(['Feature'], $section['providers'], ['Notes']);
            $separator = array_fill(0, count($header), '---');

            $lines[] = '| ' . implode(' | ', $header) . ' |';
            $lines[] = '| ' . implode(' | ', $separator) . ' |';

            foreach ($section['rows'] as $row) {
                $cells = [$row['feature']];

                foreach ($section['providers'] as $provider) {
                    $result = $row['results'][$provider];
                    $cell = $result['status'];

                    if ($result['status'] === self::STATUS_FAIL && $result['note'] !== '') {
                        $cell .= '<br><sub>' . $this->escapeInline($result['note']) . '</sub>';
                    }

                    if ($result['status'] === self::STATUS_UNAVAILABLE && $result['note'] !== '') {
                        $cell .= '<br><sub>' . $this->escapeInline($result['note']) . '</sub>';
                    }

                    $cells[] = $cell;
                }

                $cells[] = $row['notes'];
                $lines[] = '| ' . implode(' | ', $cells) . ' |';
            }

            $lines[] = '';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function escapeInline(string $value): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], trim($value));
    }

    /**
     * @return array<string, mixed>
     */
    public function write(string $markdownPath, string $jsonPath): array
    {
        $report = $this->generate();
        file_put_contents($markdownPath, $this->renderMarkdown($report));
        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return $report;
    }

    /**
     * @param callable(ProcessResult, string): ?string|callable(ProcessResult): ?string $validator
     */
    private function invokeValidator(callable $validator, ProcessResult $result, string $workspace): ?string
    {
        $reflection = new \ReflectionFunction(\Closure::fromCallable($validator));

        if ($reflection->getNumberOfParameters() >= 2) {
            /** @var ?string $validated */
            $validated = $validator($result, $workspace);

            return $validated;
        }

        /** @var ?string $validated */
        $validated = $validator($result);

        return $validated;
    }

    private static function expectExitAndStdoutContains(ProcessResult $result, int $exitCode, string $needle): ?string
    {
        if ($result->exitCode !== $exitCode) {
            return sprintf('Expected exit %d, got %d.', $exitCode, $result->exitCode);
        }

        if (!str_contains($result->stdout, $needle)) {
            return sprintf('Expected stdout to contain `%s`.', $needle);
        }

        return null;
    }

    /**
     * @param list<string> $lines
     */
    private static function expectExactOutputLines(ProcessResult $result, array $lines): ?string
    {
        if ($result->exitCode !== 0) {
            return sprintf('Expected exit 0, got %d.', $result->exitCode);
        }

        $actual = array_values(array_filter(explode("\n", trim($result->stdout)), static fn (string $line): bool => $line !== ''));

        if ($actual !== $lines) {
            return sprintf(
                'Expected output lines `%s`, got `%s`.',
                implode(', ', $lines),
                implode(', ', $actual),
            );
        }

        return null;
    }

    private static function expectCountOutput(ProcessResult $result, string $value): ?string
    {
        if ($result->exitCode !== 0) {
            return sprintf('Expected exit 0, got %d.', $result->exitCode);
        }

        if (trim($result->stdout) !== $value) {
            return sprintf('Expected count output `%s`, got `%s`.', $value, trim($result->stdout));
        }

        return null;
    }

    private static function expectContextOutput(ProcessResult $result): ?string
    {
        if ($result->exitCode !== 0) {
            return sprintf('Expected exit 0, got %d.', $result->exitCode);
        }

        foreach (['before', 'needle', 'after'] as $needle) {
            if (!str_contains($result->stdout, $needle)) {
                return sprintf('Expected context output to contain `%s`.', $needle);
            }
        }

        return null;
    }

    private static function expectSingleMatchLine(ProcessResult $result): ?string
    {
        if ($result->exitCode !== 0) {
            return sprintf('Expected exit 0, got %d.', $result->exitCode);
        }

        $lines = array_values(array_filter(explode("\n", trim($result->stdout))));

        if (count($lines) !== 1) {
            return sprintf('Expected exactly one output line, got %d.', count($lines));
        }

        return null;
    }

    private static function expectGlobFilteredOutput(ProcessResult $result): ?string
    {
        if ($result->exitCode !== 0) {
            return sprintf('Expected exit 0, got %d.', $result->exitCode);
        }

        if (!str_contains($result->stdout, 'src/App.php')) {
            return 'Expected filtered output to include `src/App.php`.';
        }

        if (str_contains($result->stdout, 'src/Other.txt')) {
            return 'Expected filtered output to exclude `src/Other.txt`.';
        }

        return null;
    }

    private static function expectFilesModeOutput(ProcessResult $result): ?string
    {
        if ($result->exitCode !== 0) {
            return sprintf('Expected exit 0, got %d.', $result->exitCode);
        }

        if (!str_contains($result->stdout, 'single.txt')) {
            return 'Expected files output to include `single.txt`.';
        }

        if (str_contains($result->stdout, '.hidden/secret.txt')) {
            return 'Expected files output to exclude hidden files by default.';
        }

        return null;
    }

    private static function expectRipgrepJsonStream(ProcessResult $result): ?string
    {
        if ($result->exitCode !== 0) {
            return sprintf('Expected exit 0, got %d.', $result->exitCode);
        }

        $lines = array_values(array_filter(explode("\n", trim($result->stdout))));

        if ($lines === []) {
            return 'Expected JSON-event output lines.';
        }

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);

            if (!is_array($decoded)) {
                return 'Expected newline-delimited JSON events.';
            }

            if (($decoded['type'] ?? null) === 'match') {
                return null;
            }
        }

        return 'Expected at least one `match` JSON event.';
    }

    private static function expectStructuredJson(ProcessResult $result): ?string
    {
        if ($result->exitCode !== 0) {
            return sprintf('Expected exit 0, got %d.', $result->exitCode);
        }

        $trimmed = trim($result->stdout);

        if ($trimmed === '') {
            return 'Expected JSON output, got empty stdout.';
        }

        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            return null;
        }

        $lines = array_values(array_filter(explode("\n", $trimmed)));

        if ($lines === []) {
            return 'Expected JSON output lines.';
        }

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);

            if (!is_array($decoded)) {
                return 'Expected JSON object or JSON lines output.';
            }
        }

        return null;
    }

    private static function expectPhgrepTextJson(ProcessResult $result): ?string
    {
        if ($result->exitCode !== 0) {
            return sprintf('Expected exit 0, got %d.', $result->exitCode);
        }

        $decoded = json_decode(trim($result->stdout), true);

        if (!is_array($decoded)) {
            return 'Expected phgrep JSON array output.';
        }

        foreach ($decoded as $entry) {
            if (($entry['file'] ?? null) === 'single.txt') {
                return null;
            }
        }

        return 'Expected JSON payload to include `single.txt`.';
    }
}
