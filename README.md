<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./docs/public/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="./docs/public/logo-light.svg">
    <img alt="Greph" src="./docs/public/logo-light.svg" height="56">
  </picture>
</p>

<p align="center">
  Pure PHP code search, structural search, and rewrite engine
</p>

<p align="center">
  <a href="https://github.com/inline0/greph/actions/workflows/ci.yml"><img src="https://github.com/inline0/greph/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://packagist.org/packages/greph/greph"><img src="https://img.shields.io/packagist/v/greph/greph.svg" alt="Packagist"></a>
  <a href="https://github.com/inline0/greph/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="license"></a>
</p>

---

## What is Greph?

Greph is a pure PHP search and refactoring engine for source trees. It covers three related modes in a single Composer package: text search, PHP AST search, and PHP AST rewrite. It also ships warmed indexed modes for both text and AST workloads, plus `rg` and `sg` compatibility wrappers for tool and agent environments.

**The problem:** PHP applications and agents that need fast code search usually shell out to native tools (`grep`, `ripgrep`, `ast-grep`), giving up portability and adding deployment friction. Structural search and rewrite is even worse: it normally means leaving the PHP process entirely.

**Greph solves this** by keeping the engine in PHP while still exposing familiar interfaces:

- Native text search and structural AST search backed by `nikic/php-parser`
- Format-preserving AST rewrite with dry-run, interactive, and write modes
- Warmed trigram + identifier text indexes for repeated workloads
- Warmed AST fact index and cached AST search for repeated structural queries
- ripgrep and ast-grep CLI compatibility wrappers backed by the same engine
- pcntl-based parallel worker pool with single-process fallback

## Quick Start

```bash
composer require greph/greph
```

```bash
# Text search
vendor/bin/greph -F "function" src

# Structural search
vendor/bin/greph -p 'new $CLASS()' src

# Structural rewrite preview
vendor/bin/greph -p 'array($$$ITEMS)' -r '[$$$ITEMS]' --dry-run src

# ripgrep-style compatibility
vendor/bin/rg --json -F "function" src

# ast-grep-style compatibility
vendor/bin/sg --pattern 'new $CLASS()' src --lang php

# Warm text index
vendor/bin/greph-index build .
vendor/bin/greph-index search -F "function" .

# Warm AST index / cache
vendor/bin/greph-index ast-index build .
vendor/bin/greph-index ast-index search 'new $CLASS()' src
vendor/bin/greph-index ast-cache build .
vendor/bin/greph-index ast-cache search 'array($$$ITEMS)' src
```

## PHP API

```php
use Greph\Greph;
use Greph\Ast\AstSearchOptions;
use Greph\Text\TextSearchOptions;

// Text search
$results = Greph::searchText(
    'function',
    'src',
    new TextSearchOptions(fixedString: true, caseInsensitive: true),
);

// AST search
$matches = Greph::searchAst(
    'new $CLASS()',
    'src',
    new AstSearchOptions(),
);

// AST rewrite (returns the rewritten contents; the caller decides whether to write)
$rewrites = Greph::rewriteAst(
    'array($$$ITEMS)',
    '[$$$ITEMS]',
    'src',
);

// Indexed text
Greph::buildTextIndex('.');
$results = Greph::searchTextIndexed('function', 'src');

// Indexed AST (fact index)
Greph::buildAstIndex('.');
$matches = Greph::searchAstIndexed('new $CLASS()', 'src');

// Cached AST (parsed-tree cache)
Greph::buildAstCache('.');
$matches = Greph::searchAstCached('array($$$ITEMS)', 'src');
```

## CLI

Greph exposes four executables:

- `greph`: native text + AST search + AST rewrite
- `greph-index`: warmed text index, AST fact index, and cached AST search
- `rg`: ripgrep-style compatibility wrapper
- `sg`: ast-grep-style compatibility wrapper

```bash
./vendor/bin/greph -F "function" src
./vendor/bin/greph -p 'new $CLASS()' src
./vendor/bin/greph -p 'array($$$ITEMS)' -r '[$$$ITEMS]' src

./vendor/bin/greph-index build .
./vendor/bin/greph-index search -F "function" .
./vendor/bin/greph-index ast-index build .
./vendor/bin/greph-index ast-index search 'new $CLASS()' src
./vendor/bin/greph-index ast-cache build .
./vendor/bin/greph-index ast-cache search 'array($$$ITEMS)' src

./vendor/bin/rg -F "function" src
./vendor/bin/rg --files src

./vendor/bin/sg run --pattern 'new $CLASS()' src
./vendor/bin/sg run --pattern 'array($$$ITEMS)' --rewrite '[$$$ITEMS]' src
```

The compatibility wrappers are intentionally probe-driven rather than hand-waved. See [FEATURE_MATRIX.md](FEATURE_MATRIX.md) for the current supported surface and the raw evidence in [FEATURE_MATRIX.json](FEATURE_MATRIX.json).

## Documentation

The repo includes a dedicated docs app under [`docs/`](docs) that mirrors the same release/docs structure used in sibling projects.

```bash
cd docs
npm install
npm run dev
```

Topics covered:

- Getting started, CLI reference, and PHP API
- Text mode, AST mode, and rewrite mode
- Indexed text, indexed AST, and cached AST
- `rg` and `sg` compatibility wrappers
- File walker, parallel workers, feature matrix, testing, and benchmarks

## Testing

Greph is validated with unit tests, an oracle-style regression corpus, and a probe-driven feature matrix.

```bash
# PHPUnit unit + integration tests
composer test

# Oracle regression corpus (text, AST, rewrite)
composer test:oracle

# Static analysis
composer analyse

# Coding standards
composer cs

# Full release-grade verification (analyse + cs + test + test:oracle)
composer verify

# Refresh the generated feature matrix
php bin/feature-matrix
```

The oracle harness diffs Greph output against the canonical `grep`, `ripgrep`, and `ast-grep` binaries on a fixed scenario set. Scenarios live under [`scenarios/`](scenarios), the runner lives under [`tests/Oracle/`](tests/Oracle), and the verified compatibility surface lives in [FEATURE_MATRIX.md](FEATURE_MATRIX.md).

Current local verification baseline:

- `252` PHPUnit tests
- `1366` assertions
- oracle regression summary `39/39`

## Performance

Benchmark tables below are sourced from GitHub Actions CI, never from local runs. The current published baseline comes from GitHub Actions run `24339598566`, against the WordPress corpus on `ubuntu-latest` with PHP `8.4`, five measured runs, and one warmup run.

Comparison tools:
- `rg`: ripgrep
- `grep`: GNU grep
- `sg`: ast-grep

### Scan Mode: Text

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Literal "function"` | `443.95ms` | `85.74ms` | `186.25ms` |
| `Literal case insensitive` | `442.16ms` | `87.69ms` | `236.47ms` |
| `Literal quiet "function"` | `240.30ms` | `3.38ms` | `1.08ms` |
| `Literal short "wp"` | `433.93ms` | `100.08ms` | `158.95ms` |
| `Literal whole word` | `952.92ms` | `85.57ms` | `181.67ms` |
| `Regex new instance` | `448.77ms` | `41.04ms` | `169.17ms` |
| `Regex array call` | `391.57ms` | `47.61ms` | `185.46ms` |
| `Regex prefix literal` | `434.85ms` | `42.37ms` | `155.31ms` |
| `Regex suffix literal` | `575.41ms` | `129.01ms` | `229.56ms` |
| `Regex exact line literal` | `540.73ms` | `75.56ms` | `162.11ms` |
| `Regex literal collapse` | `426.96ms` | `86.10ms` | `180.46ms` |

### Scan Mode: Traversal

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Full traversal` | `43.57ms` | `12.86ms` | `47.19ms` |

### Scan Mode: Parallel Text

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `1 worker` | `429.59ms` | `83.96ms` | `182.03ms` |
| `2 workers` | `427.13ms` | `83.56ms` | `193.52ms` |
| `4 workers` | `431.55ms` | `85.32ms` | `179.22ms` |

### Scan Mode: AST

| Operation | greph | sg |
| --- | ---: | ---: |
| `new $CLASS()` | `2833.44ms` | `4275.41ms` |
| `array($$$ITEMS)` | `5607.00ms` | `4317.52ms` |

### Indexed Text Mode

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed literal "function"` | `95.54ms` | `88.86ms` | `183.92ms` |
| `Indexed literal case insensitive` | `100.22ms` | `95.94ms` | `247.49ms` |
| `Indexed literal short "wp"` | `125.64ms` | `104.30ms` | `169.99ms` |
| `Indexed literal whole word` | `92.44ms` | `86.12ms` | `182.61ms` |
| `Indexed regex new instance` | `9.76ms` | `41.83ms` | `169.61ms` |
| `Indexed regex array call` | `25.93ms` | `46.54ms` | `185.18ms` |

### Indexed Summary Queries

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed count "function"` | `11.28ms` | `54.21ms` | `156.26ms` |
| `Indexed files with "function"` | `9.88ms` | `47.67ms` | `104.18ms` |
| `Indexed files without "function"` | `10.03ms` | `84.48ms` | `104.44ms` |
| `Indexed quiet "function"` | `5.77ms` | `3.35ms` | `1.12ms` |

### Indexed / Cached AST

| Operation | greph | sg |
| --- | ---: | ---: |
| `Indexed new $CLASS()` | `11.14ms` | `4284.66ms` |
| `Indexed array($$$ITEMS)` | `308.76ms` | `4336.90ms` |
| `Cached new $CLASS()` | `13.00ms` | `4284.16ms` |
| `Cached array($$$ITEMS)` | `296.52ms` | `4330.10ms` |

### Build Costs

| Operation | greph |
| --- | ---: |
| `Build trigram index` | `12417.00ms` |
| `Build AST fact index` | `1358.68ms` |
| `Build cached AST store` | `10033.73ms` |

## Requirements

- PHP 8.2+
- `ext-json` (built-in on virtually every PHP install)
- `nikic/php-parser` ^5.7 (the only Composer dependency)

Optional:

- `ext-pcntl` for parallel worker scans. Greph degrades gracefully to single-process execution when missing.
- External `rg`, `grep`, and `sg` binaries for benchmark comparisons. Greph itself does not require them at runtime.

## Features

| Category | Features |
|----------|----------|
| Text Search | fixed-string, regex, case-insensitive, whole-word, context, counts, file-only modes |
| AST Search | PHP structural search with captures, repeated metavariables, variadics, JSON output |
| Rewrite | dry-run, interactive, write mode, format-preserving, captured-variable splicing |
| Indexed Text | warmed trigram + identifier postings, summary queries, cold/warm benchmark coverage |
| Indexed AST | fact-backed search and cached parsed-tree search |
| Compatibility | `rg` and `sg` wrappers backed by the same engine, probe-verified against the upstream binaries |
| Validation | probe-driven feature matrix, oracle regressions, CI benchmarks, 100% `src/` coverage |

## Architecture

```text
src/
├── Greph.php                  # Static facade (open, search, rewrite, index)
├── Cli/                       # greph, greph-index, rg, sg frontends
├── Text/                      # grep-style search engine
├── Ast/                       # PHP AST search and rewrite engine
├── Index/                     # warmed text / AST indexes and caches
├── Walker/                    # filesystem traversal and ignore rules
├── Parallel/                  # worker pool and result collection
├── FeatureMatrix/             # generated compatibility probes
├── Output/                    # grep-style formatting
├── Support/                   # filesystem, command, JSON, tool helpers
└── Exceptions/                # typed exceptions
```

## License

MIT
