---
title: "Benchmarks"
description: "Repo-local benchmark harness, real-world corpora, and CI-published numbers."
path: "advanced/benchmarks"
order: 150
section: "Advanced"
meta_title: "Benchmarks"
meta_description: "Repo-local benchmark harness, real-world corpora, and CI-published numbers."
---

# Benchmarks

The repository has a benchmark harness under `bin/` and `benchmarks/`. It measures every search mode against real corpora (WordPress, Laravel) and generated synthetic datasets, captures structured reports, and lets you diff two report series against each other.

The harness is part of the repository, not of the Composer package: since 0.2.3, `benchmarks/` and the `bin/bench*` scripts are left out of the package, so run them from a clone (`git clone https://github.com/inline0/greph && cd greph && composer install`).

The published numbers in the [README](https://github.com/inline0/greph#performance) are sourced from GitHub Actions runs, never from local machines. Local runs are useful for iteration; CI runs are the source of truth.

## Corpora

The synthetic corpora (`synthetic-1k`, `synthetic-10k`, `synthetic-100k-single`) are generated under `benchmarks/corpora/synthetic/` on the first run. The real corpora are not included: put a checkout at `benchmarks/corpora/wordpress` or `benchmarks/corpora/laravel` (the CI workflow clones `WordPress/WordPress` with `--depth=1`). A corpus whose directory is missing is skipped.

## Running benchmarks

```bash
# Full benchmark suite, every corpus that is present
./bin/bench

# Specific category (the category names used in benchmarks/suites/)
./bin/bench --category text
./bin/bench --category ast

# Compare against external tools when available
./bin/bench --compare rg,grep
./bin/bench --compare sg

# Choose a corpus
./bin/bench --corpus wordpress
./bin/bench --corpus laravel
./bin/bench --corpus synthetic-1k

# Write the run as JSON
./bin/bench --corpus wordpress --output build/run-1.json

# Measured series: 5 runs after 1 warmup run
./bin/bench-series --repeat 5 --warmup 1 --corpus wordpress --output build/head-series.json

# Aggregate separate runs into one series
./bin/bench-aggregate --output build/head-series.json build/run-*.json
```

`bin/bench-compare base-series.json head-series.json` diffs two series files (the output of `bench-series` or `bench-aggregate`) and prints a Markdown summary, useful when iterating on a hot path. `bin/bench-ci` triggers the Benchmark workflow on GitHub Actions through the `gh` CLI.

## Categories

The README publishes eight groups of results, each measuring a specific layer of the engine. Each group collects one or more of the harness categories defined in `benchmarks/suites/`.

### Scan Mode: Text

Native text search against literal, regex, and combined patterns. The published baseline runs on the WordPress corpus and includes:

- Literal `function`
- Literal case insensitive
- Literal whole word
- Regex new instance
- Regex array call
- Regex prefix literal
- Regex suffix literal
- Regex exact line literal
- Regex literal collapse

### Scan Mode: Traversal

File walking only, no search. Measures the cost of the gitignore-aware walker on its own.

### Scan Mode: Parallel Text

Since 0.2.4, `parallel-count-scaling` counts lines matching `function\s+[A-Za-z_]` with `1`, `2`, and `4` jobs. Count mode uses the `jobs * 750` file threshold, so the 2- and 4-job rows start workers on a WordPress corpus with more than 3,000 files when `pcntl_fork` is available. Smaller corpora can still run in-process; see [Parallel Workers](/docs/advanced/parallel).

The earlier `parallel-scaling` suite searched the short fixed string `function`. Its threshold exceeded the WordPress corpus size, so the old published rows measured single-process searches. The new suite has a separate identity: the comparison report does not calculate ratios between these different workloads.

### Scan Mode: AST

Native AST search against representative patterns:

- `new $CLASS()`
- `array($$$ITEMS)`

### Indexed Text Mode

Warmed indexed text search against the same patterns as the scan mode. Includes literal, case-insensitive, whole-word, short-token, and regex queries.

### Indexed Summary Queries

Warmed indexed text search using count, files-with-matches, and files-without-matches outputs. These benefit most from the postings store because the per-file work is the cheapest.

### Indexed / Cached AST

Warmed AST fact search and cached AST search against the same patterns as the AST scan mode.

### Build Costs

Cold-build costs for the trigram index, the AST fact index, and the AST cache. Useful when deciding which mode to maintain for a given workload.

## Reading the published numbers

The README publishes a snapshot of the latest CI run for each baseline. Each section is sourced from a specific GitHub Actions run and labeled with the corpus, runner, PHP version, and number of measured runs and warmups. If you need to reproduce a number, use the same combination locally.

The comparison columns (`rg`, `grep`, `sg`) are gathered in the same job, on the same runner, against the same corpus. They are not extrapolated from external benchmarks.

## How to add a benchmark

1. Add a benchmark definition under `benchmarks/`.
2. Add a corresponding scenario to the regression suite if there is no equivalent yet (so the behavior under measurement is also verified).
3. Run `./bin/bench --category <yours>` to validate the new benchmark.
4. Compare against `main` with the Benchmark workflow, which runs on pull requests that touch `src/`, `benchmarks/` or `bin/` and posts a head-versus-base comparison in the run summary.
5. Update the README table once the CI run that includes the new benchmark has landed on `main`.

## Performance philosophy

Greph is not trying to outrun ripgrep or grep on cold scans. Ripgrep is implemented in Rust with hand-tuned SIMD literal scanning, and in the published numbers both `rg` and GNU `grep` are faster than Greph on every cold text scan. The goals are different:

- Be fast enough that interactive use and agent loops feel native.
- Answer repeated queries from warmed indexes: indexed regex and indexed count and file-list queries are faster than both `rg` and `grep` scanning the same tree.
- Beat `ast-grep` on indexed and cached AST workloads, where Greph's warm caches avoid reparsing the tree. Cold AST search is mixed: `new $CLASS()` is faster than `sg`, `array($$$ITEMS)` is slower.
- Make every performance improvement measurable and reproducible.

Performance claims that are not backed by a benchmark report are not landed.
