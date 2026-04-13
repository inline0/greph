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

- `249` PHPUnit tests
- `1328` assertions
- oracle regression summary `39/39`

## Performance

Benchmark tables below are sourced from GitHub Actions CI, never from local runs. Each baseline runs against the WordPress corpus on `ubuntu-latest` with PHP `8.4`, five measured runs, and one warmup run.

Comparison tools:
- `rg`: ripgrep
- `grep`: GNU grep
- `sg`: ast-grep

### Scan Mode: Text

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Literal "function"` | `441.04ms` | `81.93ms` | `185.24ms` |
| `Literal case insensitive` | `443.29ms` | `88.79ms` | `235.02ms` |
| `Literal quiet "function"` | `241.22ms` | `3.26ms` | `1.08ms` |
| `Literal short "wp"` | `439.52ms` | `101.55ms` | `160.44ms` |
| `Literal whole word` | `921.99ms` | `82.66ms` | `179.76ms` |
| `Regex new instance` | `448.83ms` | `41.27ms` | `167.49ms` |
| `Regex array call` | `388.76ms` | `46.32ms` | `190.27ms` |
| `Regex prefix literal` | `433.71ms` | `41.09ms` | `154.04ms` |
| `Regex suffix literal` | `576.38ms` | `129.65ms` | `225.08ms` |
| `Regex exact line literal` | `542.68ms` | `75.13ms` | `161.96ms` |
| `Regex literal collapse` | `429.30ms` | `85.65ms` | `180.00ms` |

### Scan Mode: Traversal

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Full traversal` | `45.63ms` | `20.18ms` | `55.40ms` |

### Scan Mode: Parallel Text

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `1 worker` | `456.99ms` | `143.65ms` | `223.35ms` |
| `2 workers` | `446.09ms` | `147.18ms` | `223.39ms` |
| `4 workers` | `448.29ms` | `144.22ms` | `223.62ms` |

### Scan Mode: AST

| Operation | greph | sg |
| --- | ---: | ---: |
| `new $CLASS()` | `3436.26ms` | `8564.72ms` |
| `array($$$ITEMS)` | `6669.04ms` | `8652.00ms` |

### Indexed Text Mode

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed literal "function"` | `62.93ms` | `138.89ms` | `203.94ms` |
| `Indexed literal case insensitive` | `71.35ms` | `145.51ms` | `267.65ms` |
| `Indexed literal short "wp"` | `100.82ms` | `174.77ms` | `2145.97ms` |
| `Indexed literal whole word` | `66.11ms` | `137.11ms` | `203.59ms` |
| `Indexed regex new instance` | `6.67ms` | `67.46ms` | `169.57ms` |
| `Indexed regex array call` | `18.82ms` | `78.25ms` | `192.38ms` |

### Indexed Summary Queries

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed count "function"` | `277.81ms` | `109.83ms` | `181.37ms` |
| `Indexed files with "function"` | `81.44ms` | `100.21ms` | `121.04ms` |
| `Indexed files without "function"` | `81.91ms` | `139.90ms` | `107.87ms` |

### Indexed / Cached AST

| Operation | greph | sg |
| --- | ---: | ---: |
| `Indexed new $CLASS()` | `2724.44ms` | `8554.87ms` |
| `Indexed array($$$ITEMS)` | `6178.27ms` | `8642.14ms` |
| `Cached new $CLASS()` | `1688.84ms` | `8572.19ms` |
| `Cached array($$$ITEMS)` | `3861.60ms` | `8668.03ms` |

### Build Costs

| Operation | greph |
| --- | ---: |
| `Build trigram index` | `10403.94ms` |
| `Build AST fact index` | `1418.25ms` |
| `Build cached AST store` | `10381.02ms` |

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
