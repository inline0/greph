<?php

declare(strict_types=1);

namespace Phgrep\Tests\Oracle;

use Phgrep\Support\CommandRunner;
use Phgrep\Support\Filesystem;
use Phgrep\Support\Json;
use Phgrep\Support\ToolResolver;

final class OracleCapture
{
    private CommandRunner $commandRunner;

    private ToolResolver $toolResolver;

    private FlagParser $flagParser;

    private OutputNormalizer $normalizer;

    public function __construct(
        ?CommandRunner $commandRunner = null,
        ?ToolResolver $toolResolver = null,
        ?FlagParser $flagParser = null,
        ?OutputNormalizer $normalizer = null,
    ) {
        $this->commandRunner = $commandRunner ?? new CommandRunner();
        $this->toolResolver = $toolResolver ?? new ToolResolver();
        $this->flagParser = $flagParser ?? new FlagParser();
        $this->normalizer = $normalizer ?? new OutputNormalizer();
    }

    /**
     * @return array{success: bool, outputs: array<string, mixed>, errors: list<string>}
     */
    public function captureFromWorkspace(Scenario $scenario, string $workspaceRoot): array
    {
        $outputs = [];
        $errors = [];

        try {
            $flags = $this->flagParser->parse($scenario);
            Filesystem::ensureDirectory($scenario->oracleDir());

            if ($scenario->mode() === 'rewrite') {
                $outputs = $this->captureRewriteOracle($scenario, $workspaceRoot, $flags);
            } elseif ($scenario->mode() === 'ast') {
                $outputs = $this->captureAstOracle($scenario, $workspaceRoot, $flags);
            } else {
                $outputs = $this->captureTextOracle($scenario, $workspaceRoot, $flags);
            }

            foreach ($outputs as $name => $output) {
                $path = $scenario->oracleDir() . '/' . $name;

                if (is_array($output)) {
                    Json::encodeFile($path, $output);
                } else {
                    file_put_contents($path, (string) $output);
                }
            }
        } catch (\Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }

        return [
            'success' => $errors === [],
            'outputs' => $outputs,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $flags
     * @return array<string, mixed>
     */
    private function captureTextOracle(Scenario $scenario, string $workspaceRoot, array $flags): array
    {
        $pathArguments = $scenario->paths();

        $grepCommand = array_merge($this->toolResolver->grep(), ['-r', '-n']);

        if ($flags['fixedString']) {
            $grepCommand[] = '-F';
        }

        if ($flags['caseInsensitive']) {
            $grepCommand[] = '-i';
        }

        if ($flags['wholeWord']) {
            $grepCommand[] = '-w';
        }

        if ($flags['invertMatch']) {
            $grepCommand[] = '-v';
        }

        if ($flags['countOnly']) {
            $grepCommand[] = '-c';
        }

        if ($flags['filesWithMatches']) {
            $grepCommand[] = '-l';
        }

        if ($flags['filesWithoutMatches']) {
            $grepCommand[] = '-L';
        }

        if ($flags['maxCount'] !== null) {
            $grepCommand[] = '-m';
            $grepCommand[] = (string) $flags['maxCount'];
        }

        if ($flags['beforeContext'] > 0) {
            $grepCommand[] = '-B';
            $grepCommand[] = (string) $flags['beforeContext'];
        }

        if ($flags['afterContext'] > 0) {
            $grepCommand[] = '-A';
            $grepCommand[] = (string) $flags['afterContext'];
        }

        if ($flags['context'] !== null) {
            $grepCommand[] = '-C';
            $grepCommand[] = (string) $flags['context'];
        }

        foreach ($flags['glob'] as $glob) {
            $grepCommand[] = sprintf('--include=%s', $glob);
        }

        $grepCommand = array_merge(
            $grepCommand,
            $this->flagParser->grepTypeGlobs($flags['type'], $flags['typeNot']),
            [$scenario->pattern()],
            $pathArguments,
        );

        $rgCommand = array_merge($this->toolResolver->ripgrep(), ['-n', '--color', 'never']);

        if ($flags['fixedString']) {
            $rgCommand[] = '-F';
        }

        if ($flags['caseInsensitive']) {
            $rgCommand[] = '-i';
        }

        if ($flags['wholeWord']) {
            $rgCommand[] = '-w';
        }

        if ($flags['invertMatch']) {
            $rgCommand[] = '-v';
        }

        if ($flags['countOnly']) {
            $rgCommand[] = '-c';
        }

        if ($flags['filesWithMatches']) {
            $rgCommand[] = '-l';
        }

        if ($flags['filesWithoutMatches']) {
            $rgCommand[] = '-L';
        }

        if ($flags['maxCount'] !== null) {
            $rgCommand[] = '-m';
            $rgCommand[] = (string) $flags['maxCount'];
        }

        if ($flags['beforeContext'] > 0) {
            $rgCommand[] = '-B';
            $rgCommand[] = (string) $flags['beforeContext'];
        }

        if ($flags['afterContext'] > 0) {
            $rgCommand[] = '-A';
            $rgCommand[] = (string) $flags['afterContext'];
        }

        if ($flags['context'] !== null) {
            $rgCommand[] = '-C';
            $rgCommand[] = (string) $flags['context'];
        }

        foreach ($flags['type'] as $type) {
            $rgCommand[] = '--type';
            $rgCommand[] = $type;
        }

        foreach ($flags['typeNot'] as $type) {
            $rgCommand[] = '--type-not';
            $rgCommand[] = $type;
        }

        foreach ($flags['glob'] as $glob) {
            $rgCommand[] = '-g';
            $rgCommand[] = $glob;
        }

        if ($flags['noIgnore']) {
            $rgCommand[] = '--no-ignore';
        }

        if ($flags['hidden']) {
            $rgCommand[] = '--hidden';
        }

        $rgCommand = array_merge($rgCommand, [$scenario->pattern()], $pathArguments);
        $rgJsonCommand = array_merge($rgCommand, ['--json']);

        $grep = $this->commandRunner->run($grepCommand, $workspaceRoot);
        $rg = $this->commandRunner->run($rgCommand, $workspaceRoot);
        $rgJson = $this->commandRunner->run($rgJsonCommand, $workspaceRoot);

        return [
            'grep.txt' => $this->normalizer->normalizeTextOutput($grep->output()),
            'rg.txt' => $this->normalizer->normalizeTextOutput($rg->output()),
            'rg.json' => $this->normalizer->parseRipgrepJson($rgJson->stdout),
        ];
    }

    /**
     * @param array<string, mixed> $flags
     * @return array<string, mixed>
     */
    private function captureAstOracle(Scenario $scenario, string $workspaceRoot, array $flags): array
    {
        $command = array_merge(
            $this->toolResolver->astGrep(),
            ['run', '--lang', $scenario->language(), '-p', $scenario->pattern(), '--json=pretty']
        );

        if ($flags['hidden']) {
            $command[] = '--no-ignore';
            $command[] = 'hidden';
        }

        if ($flags['noIgnore']) {
            foreach (['hidden', 'dot', 'exclude', 'global', 'parent', 'vcs'] as $ignoreFlag) {
                $command[] = '--no-ignore';
                $command[] = $ignoreFlag;
            }
        }

        foreach ($flags['glob'] as $glob) {
            $command[] = '--globs';
            $command[] = $glob;
        }

        $command = array_merge($command, $scenario->paths());
        $jsonResult = $this->commandRunner->run($command, $workspaceRoot);
        $canonicalJson = $this->normalizer->parseAstGrepJson($jsonResult->stdout);
        $lines = array_map(
            static fn (array $match): string => sprintf(
                '%s:%d:%s',
                $match['file'],
                $match['start_line'],
                trim((string) preg_replace('/\s+/', ' ', $match['code']))
            ),
            $canonicalJson,
        );

        return [
            'sg.txt' => $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL,
            'sg.json' => $canonicalJson,
        ];
    }

    /**
     * @param array<string, mixed> $flags
     * @return array<string, mixed>
     */
    private function captureRewriteOracle(Scenario $scenario, string $workspaceRoot, array $flags): array
    {
        $beforeSnapshot = $this->normalizer->snapshotToRewriteJson($workspaceRoot . '/setup');
        $command = array_merge(
            $this->toolResolver->astGrep(),
            ['run', '--lang', $scenario->language(), '-p', $scenario->pattern(), '-r', (string) $scenario->rewrite(), '-U']
        );

        if ($flags['hidden']) {
            $command[] = '--no-ignore';
            $command[] = 'hidden';
        }

        if ($flags['noIgnore']) {
            foreach (['hidden', 'dot', 'exclude', 'global', 'parent', 'vcs'] as $ignoreFlag) {
                $command[] = '--no-ignore';
                $command[] = $ignoreFlag;
            }
        }

        foreach ($flags['glob'] as $glob) {
            $command[] = '--globs';
            $command[] = $glob;
        }

        $command = array_merge($command, $scenario->paths());
        $this->commandRunner->run($command, $workspaceRoot);

        $afterSnapshot = $this->normalizer->snapshotToRewriteJson($workspaceRoot . '/setup');
        $rewrittenFiles = $this->normalizer->diffRewriteSnapshots($beforeSnapshot, $afterSnapshot);
        $lines = array_map(static fn (array $file): string => $file['file'], $rewrittenFiles);

        return [
            'sg.txt' => $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL,
            'sg.json' => $rewrittenFiles,
        ];
    }
}
