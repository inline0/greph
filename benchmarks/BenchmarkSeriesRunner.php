<?php

declare(strict_types=1);

namespace Phgrep\Benchmarks;

final class BenchmarkSeriesRunner
{
    private BenchmarkRunner $benchmarkRunner;

    private BenchmarkAggregator $aggregator;

    public function __construct(
        string $rootPath,
        ?BenchmarkRunner $benchmarkRunner = null,
        ?BenchmarkAggregator $aggregator = null,
    ) {
        $this->benchmarkRunner = $benchmarkRunner ?? new BenchmarkRunner($rootPath);
        $this->aggregator = $aggregator ?? new BenchmarkAggregator();
    }

    /**
     * @param list<string> $compareTools
     * @return array{
     *   meta: array<string, mixed>,
     *   aggregate: list<array<string, mixed>>,
     *   runs: list<list<array<string, mixed>>>
     * }
     */
    public function run(
        int $repeat,
        int $warmup,
        ?string $category = null,
        ?string $corpus = null,
        array $compareTools = [],
    ): array {
        if ($repeat < 1) {
            throw new \InvalidArgumentException('Repeat count must be greater than zero.');
        }

        if ($warmup < 0) {
            throw new \InvalidArgumentException('Warmup count must not be negative.');
        }

        for ($index = 0; $index < $warmup; $index++) {
            $this->benchmarkRunner->run($category, $corpus, $compareTools);
        }

        $runs = [];

        for ($index = 0; $index < $repeat; $index++) {
            $runs[] = $this->benchmarkRunner->run($category, $corpus, $compareTools);
        }

        $aggregate = $this->aggregator->aggregate($runs);

        return [
            'meta' => [
                'repeat' => $repeat,
                'warmup' => $warmup,
                'category' => $category,
                'corpus' => $corpus,
                'compare_tools' => $compareTools,
                'generated_at' => gmdate(DATE_ATOM),
                'php_version' => PHP_VERSION,
            ],
            'aggregate' => array_map(static fn (BenchmarkResult $result): array => $result->toArray(), $aggregate),
            'runs' => array_map(
                static fn (array $run): array => array_map(static fn (BenchmarkResult $result): array => $result->toArray(), $run),
                $runs,
            ),
        ];
    }
}
