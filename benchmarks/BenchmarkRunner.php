<?php

declare(strict_types=1);

namespace Phgrep\Benchmarks;

use Phgrep\Ast\AstSearchOptions;
use Phgrep\Index\TextIndexBuilder;
use Phgrep\Index\TextIndexStore;
use Phgrep\Phgrep;
use Phgrep\Support\CommandRunner;
use Phgrep\Support\Filesystem;
use Phgrep\Support\Json;
use Phgrep\Support\ToolResolver;
use Phgrep\Text\TextSearchOptions;

final class BenchmarkRunner
{
    private CommandRunner $commandRunner;

    private ToolResolver $toolResolver;

    public function __construct(private readonly string $rootPath, ?CommandRunner $commandRunner = null, ?ToolResolver $toolResolver = null)
    {
        $this->commandRunner = $commandRunner ?? new CommandRunner();
        $this->toolResolver = $toolResolver ?? new ToolResolver();
    }

    /**
     * @param list<string> $compareTools
     * @return list<BenchmarkResult>
     */
    public function run(?string $category = null, ?string $corpusFilter = null, array $compareTools = []): array
    {
        (new SyntheticCorpusGenerator($this->rootPath . '/benchmarks/corpora/synthetic'))->ensure();

        $results = [];

        foreach ($this->corpora() as $corpusName => $corpusPath) {
            if ($corpusFilter !== null && $corpusFilter !== $corpusName) {
                continue;
            }

            if (!is_dir($corpusPath)) {
                continue;
            }

            foreach ($this->suites() as $suite) {
                if ($category !== null && $suite['category'] !== $category) {
                    continue;
                }

                $results[] = $this->runPhgrepBenchmark($suite, $corpusName, $corpusPath);

                foreach ($compareTools as $tool) {
                    $external = $this->runExternalBenchmark($suite, $tool, $corpusName, $corpusPath);

                    if ($external !== null) {
                        $results[] = $external;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $suite
     */
    private function runPhgrepBenchmark(array $suite, string $corpusName, string $corpusPath): BenchmarkResult
    {
        $fileCount = iterator_count(Phgrep::walk($corpusPath));
        $indexPath = $this->rootPath . '/build/benchmarks/indexes/' . $corpusName;

        if ($suite['category'] === 'indexed-text') {
            if (!(new TextIndexStore())->exists($indexPath)) {
                (new TextIndexBuilder())->build($corpusPath, $indexPath);
            }
        }

        $memoryBefore = memory_get_usage(true);
        $start = hrtime(true);
        $matchCount = 0;

        switch ($suite['category']) {
            case 'text':
                $results = Phgrep::searchText((string) $suite['pattern'], $corpusPath, new TextSearchOptions(
                    fixedString: (bool) ($suite['fixed'] ?? false),
                    caseInsensitive: (bool) ($suite['case_insensitive'] ?? false),
                    jobs: (int) ($suite['jobs'] ?? 1),
                ));

                foreach ($results as $result) {
                    $matchCount += $result->matchCount();
                }
                break;

            case 'ast':
                $results = Phgrep::searchAst((string) $suite['pattern'], $corpusPath, new AstSearchOptions(
                    jobs: (int) ($suite['jobs'] ?? 1),
                    language: (string) ($suite['lang'] ?? 'php'),
                ));
                $matchCount = count($results);
                break;

            case 'ast-internal':
                $matchCount = (new \Phgrep\Ast\AstSearcher())->countFiles(
                    Phgrep::walk($corpusPath),
                    (string) $suite['pattern'],
                    new AstSearchOptions(
                        jobs: (int) ($suite['jobs'] ?? 1),
                        language: (string) ($suite['lang'] ?? 'php'),
                    ),
                );
                break;

            case 'ast-parse':
                $matchCount = (new \Phgrep\Ast\AstSearcher())->countParsedFiles(
                    Phgrep::walk($corpusPath),
                    (string) $suite['pattern'],
                    new AstSearchOptions(
                        jobs: (int) ($suite['jobs'] ?? 1),
                        language: (string) ($suite['lang'] ?? 'php'),
                    ),
                );
                break;

            case 'walker':
                $matchCount = count(Phgrep::walk($corpusPath));
                break;

            case 'parallel':
                $results = Phgrep::searchText((string) $suite['pattern'], $corpusPath, new TextSearchOptions(
                    fixedString: (bool) ($suite['fixed'] ?? false),
                    jobs: (int) ($suite['jobs'] ?? 1),
                ));

                foreach ($results as $result) {
                    $matchCount += $result->matchCount();
                }
                break;

            case 'indexed-build':
                Filesystem::remove($indexPath);
                $result = (new TextIndexBuilder())->build($corpusPath, $indexPath);
                $matchCount = $result->fileCount;
                break;

            case 'indexed-text':
                $results = Phgrep::searchTextIndexed(
                    (string) $suite['pattern'],
                    $corpusPath,
                    new TextSearchOptions(
                        fixedString: (bool) ($suite['fixed'] ?? false),
                        caseInsensitive: (bool) ($suite['case_insensitive'] ?? false),
                    ),
                    $indexPath,
                );

                foreach ($results as $result) {
                    $matchCount += $result->matchCount();
                }
                break;

            default:
                throw new \RuntimeException(sprintf('Unknown benchmark category: %s', $suite['category']));
        }

        return new BenchmarkResult(
            category: (string) $suite['category'],
            suite: (string) $suite['suite'],
            operation: (string) $suite['name'],
            corpus: $corpusName,
            tool: 'phgrep',
            durationMs: (hrtime(true) - $start) / 1_000_000,
            memoryBytes: max(0, memory_get_usage(true) - $memoryBefore),
            fileCount: $fileCount,
            matchCount: $matchCount,
        );
    }

    /**
     * @param array<string, mixed> $suite
     */
    private function runExternalBenchmark(array $suite, string $tool, string $corpusName, string $corpusPath): ?BenchmarkResult
    {
        $command = match ($tool) {
            'grep' => $this->externalGrepCommand($suite, $corpusPath),
            'rg' => $this->externalRipgrepCommand($suite, $corpusPath),
            'sg' => $this->externalAstGrepCommand($suite, $corpusPath),
            default => null,
        };

        if ($command === null) {
            return null;
        }

        try {
            $result = $this->commandRunner->run($command, $this->rootPath);
        } catch (\Throwable $throwable) {
            return new BenchmarkResult(
                category: (string) $suite['category'],
                suite: (string) $suite['suite'],
                operation: (string) $suite['name'],
                corpus: $corpusName,
                tool: $tool,
                durationMs: 0.0,
                memoryBytes: 0,
                fileCount: 0,
                matchCount: 0,
                skipped: true,
                skipReason: $throwable->getMessage(),
            );
        }

        return new BenchmarkResult(
            category: (string) $suite['category'],
            suite: (string) $suite['suite'],
            operation: (string) $suite['name'],
            corpus: $corpusName,
            tool: $tool,
            durationMs: $result->durationMs,
            memoryBytes: 0,
            fileCount: 0,
            matchCount: 0,
            skipped: !$result->successful(),
            skipReason: $result->successful() ? null : trim($result->output()),
        );
    }

    /**
     * @return array<string, string>
     */
    private function corpora(): array
    {
        return [
            'synthetic-1k' => $this->rootPath . '/benchmarks/corpora/synthetic/1k-files',
            'synthetic-10k' => $this->rootPath . '/benchmarks/corpora/synthetic/10k-files',
            'synthetic-100k-single' => $this->rootPath . '/benchmarks/corpora/synthetic/100k-lines-single',
            'wordpress' => $this->rootPath . '/benchmarks/corpora/wordpress',
            'laravel' => $this->rootPath . '/benchmarks/corpora/laravel',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suites(): array
    {
        $suites = [];

        foreach (glob($this->rootPath . '/benchmarks/suites/*.php') ?: [] as $suitePath) {
            /** @var list<array<string, mixed>> $suiteDefinitions */
            $suiteDefinitions = require $suitePath;

            foreach ($suiteDefinitions as $suiteDefinition) {
                $suites[] = $suiteDefinition;
            }
        }

        return $suites;
    }

    /**
     * @param array<string, mixed> $suite
     * @return list<string>|null
     */
    private function externalGrepCommand(array $suite, string $corpusPath): ?array
    {
        if (!in_array($suite['category'], ['text', 'walker', 'parallel', 'indexed-text'], true)) {
            return null;
        }

        if ($suite['category'] === 'walker') {
            return array_merge($this->toolResolver->grep(), ['-r', '-l', '.', $corpusPath]);
        }

        $command = array_merge($this->toolResolver->grep(), ['-r', '-n']);

        if (($suite['fixed'] ?? false) === true) {
            $command[] = '-F';
        } else {
            $command[] = '-E';
        }

        if (($suite['case_insensitive'] ?? false) === true) {
            $command[] = '-i';
        }

        return array_merge($command, [(string) $suite['pattern'], $corpusPath]);
    }

    /**
     * @param array<string, mixed> $suite
     * @return list<string>|null
     */
    private function externalRipgrepCommand(array $suite, string $corpusPath): ?array
    {
        if (!in_array($suite['category'], ['text', 'walker', 'parallel', 'indexed-text'], true)) {
            return null;
        }

        if ($suite['category'] === 'walker') {
            return array_merge($this->toolResolver->ripgrep(), ['--files', $corpusPath]);
        }

        $command = array_merge($this->toolResolver->ripgrep(), ['-n', '--color', 'never']);

        if (($suite['fixed'] ?? false) === true) {
            $command[] = '-F';
        }

        if (($suite['case_insensitive'] ?? false) === true) {
            $command[] = '-i';
        }

        return array_merge($command, [(string) $suite['pattern'], $corpusPath]);
    }

    /**
     * @param array<string, mixed> $suite
     * @return list<string>|null
     */
    private function externalAstGrepCommand(array $suite, string $corpusPath): ?array
    {
        if (!in_array($suite['category'], ['ast'], true)) {
            return null;
        }

        return array_merge(
            $this->toolResolver->astGrep(),
            ['run', '--lang', (string) ($suite['lang'] ?? 'php'), '-p', (string) $suite['pattern'], $corpusPath],
        );
    }
}
