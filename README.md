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
use Greph\Index\IndexLifecycleProfile;
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
Greph::buildTextIndex('.', lifecycle: IndexLifecycleProfile::OpportunisticRefresh);
$results = Greph::searchTextIndexed('function', 'src');
$results = Greph::searchTextIndexedMany('function', '.', [
    'core/.greph-index',
    'plugins/demo/.greph-index',
]);
$results = Greph::searchTextIndexedSet(
    'function',
    '.',
    new TextSearchOptions(fixedString: true),
    '.greph-index-set.json',
    ['core-text', 'plugin-text'],
);

// Indexed AST (fact index)
Greph::buildAstIndex('.');
$matches = Greph::searchAstIndexed('new $CLASS()', 'src');
$matches = Greph::searchAstIndexedMany('new $CLASS()', '.', [
    'core/.greph-ast-index',
    'plugins/demo/.greph-ast-index',
]);
$matches = Greph::searchAstIndexedSet(
    'new $CLASS()',
    '.',
    new AstSearchOptions(),
    '.greph-index-set.json',
);

// Cached AST (parsed-tree cache)
Greph::buildAstCache('.');
$matches = Greph::searchAstCached('array($$$ITEMS)', 'src');
$matches = Greph::searchAstCachedMany('array($$$ITEMS)', '.', [
    'core/.greph-ast-cache',
    'plugins/demo/.greph-ast-cache',
]);
$matches = Greph::searchAstCachedSet(
    'array($$$ITEMS)',
    '.',
    new AstSearchOptions(),
    '.greph-index-set.json',
);
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
./vendor/bin/greph-index build . --lifecycle opportunistic-refresh
./vendor/bin/greph-index search -F "function" .
./vendor/bin/greph-index search -F "function" . --index-dir core/.greph-index --index-dir plugins/demo/.greph-index
./vendor/bin/greph-index set build
./vendor/bin/greph-index set stats --dry-refresh
./vendor/bin/greph-index set search --show-index-origin -F "function" .
./vendor/bin/greph-index ast-index build .
./vendor/bin/greph-index ast-index search 'new $CLASS()' src
./vendor/bin/greph-index ast-index search 'new $CLASS()' . --index-dir core/.greph-ast-index --index-dir plugins/demo/.greph-ast-index
./vendor/bin/greph-index set search --mode ast-index 'new $CLASS()' .
./vendor/bin/greph-index ast-cache build .
./vendor/bin/greph-index ast-cache search 'array($$$ITEMS)' src
./vendor/bin/greph-index set search --mode ast-cache 'array($$$ITEMS)' .

./vendor/bin/rg -F "function" src
./vendor/bin/rg --files src

./vendor/bin/sg run --pattern 'new $CLASS()' src
./vendor/bin/sg run --pattern 'array($$$ITEMS)' --rewrite '[$$$ITEMS]' src
```

The compatibility wrappers are intentionally probe-driven rather than hand-waved. See [FEATURE_MATRIX.md](FEATURE_MATRIX.md) for the current supported surface and the raw evidence in [FEATURE_MATRIX.json](FEATURE_MATRIX.json).

## Warm Index Lifecycle

Greph stays daemon-free. Warm indexes live on disk and are reused across runs.

Lifecycle profiles:

- `static`: never freshness-check or mutate automatically
- `manual-refresh`: inspect freshness in `stats`, never auto-refresh during search
- `opportunistic-refresh`: refresh on search only when the changed set is cheap
- `strict-stale-check`: refuse stale indexed searches

This makes mixed sets practical without a daemon:

- keep a stable `static` core index around
- keep plugin or app indexes as `opportunistic-refresh`
- let strict workloads fail hard with `strict-stale-check`

Examples:

```bash
./vendor/bin/greph-index build . --lifecycle static
./vendor/bin/greph-index build . --lifecycle opportunistic-refresh --auto-refresh-max-files 32 --auto-refresh-max-bytes 1048576
./vendor/bin/greph-index ast-cache build . --lifecycle strict-stale-check
./vendor/bin/greph-index stats . --dry-refresh
```

`greph-index stats` reports lifecycle, stale status, change summary, query-cache usage, and optional dry-run search behavior for text, AST index, and AST cache stores.

## Planner Diagnostics

Warm indexed search can emit the actual planner decision to `stderr` without
changing the user-facing result stream.

```bash
./vendor/bin/greph-index search --trace-plan -F "function" .
./vendor/bin/greph-index ast-index search --trace-plan 'new $CLASS()' .
./vendor/bin/greph-index set search --trace-plan --show-index-origin -F "apply_filters" .
```

The trace includes:

- selected file count
- candidate source
- postings term count for warmed text plans
- candidate and verified file counts
- cache eligibility/population
- AST pattern root and cached-tree hit counts for warmed AST paths

## Multi-Index Search

Greph can search multiple warmed indexes in one request. This is intended for real layouts like WordPress core + plugin + theme, monorepos, or a static baseline plus a mutable overlay.

```bash
./vendor/bin/greph-index search -F "apply_filters" . \
  --index-dir wordpress/.greph-index \
  --index-dir wp-content/plugins/my-plugin/.greph-index

./vendor/bin/greph-index ast-index search 'new $CLASS()' . \
  --index-dir wordpress/.greph-ast-index \
  --index-dir wp-content/plugins/my-plugin/.greph-ast-index
```

For repeated multi-index workflows, use a manifest instead of repeating flags:

```json
{
  "name": "wordpress-local",
  "indexes": [
    {
      "name": "core-text",
      "root": "wordpress",
      "mode": "text",
      "lifecycle": "static",
      "priority": 100
    },
    {
      "name": "plugin-text",
      "root": "wp-content/plugins/my-plugin",
      "mode": "text",
      "lifecycle": "opportunistic-refresh",
      "max_changed_files": 16,
      "max_changed_bytes": 262144,
      "priority": 200
    },
    {
      "name": "plugin-ast",
      "root": "wp-content/plugins/my-plugin",
      "mode": "ast-index",
      "priority": 200
    },
    {
      "name": "plugin-cache",
      "root": "wp-content/plugins/my-plugin",
      "mode": "ast-cache",
      "priority": 200
    }
  ]
}
```

Save that as `.greph-index-set.json`, then:

```bash
./vendor/bin/greph-index set build
./vendor/bin/greph-index set stats --dry-refresh
./vendor/bin/greph-index set search --show-index-origin -F "apply_filters" .
./vendor/bin/greph-index set search --mode ast-index 'new $CLASS()' .
./vendor/bin/greph-index set search --mode ast-cache 'array($$$ITEMS)' .
```

Useful WordPress shape:

- `wordpress` text index: `static`
- `wordpress` AST index: `static`
- plugin text/AST indexes: `opportunistic-refresh`
- theme text/AST indexes: `opportunistic-refresh`

That keeps warmed baseline indexes stable while letting active overlays refresh cheaply when a command runs.

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

Benchmark tables below are sourced from GitHub Actions CI, never from local runs. The current published baseline comes from GitHub Actions run `24342599916`, against the WordPress corpus on `ubuntu-latest` with PHP `8.4`, five measured runs, and one warmup run.

Comparison tools:
- `rg`: ripgrep
- `grep`: GNU grep
- `sg`: ast-grep

### Scan Mode: Text

Cold scan mode optimizes for one-shot searches without any prebuilt index. It is the baseline path for ad hoc text queries over a tree.

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Literal "function"` | `458.82ms` | `81.62ms` | `174.79ms` |
| `Literal case insensitive` | `448.42ms` | `83.65ms` | `238.35ms` |
| `Literal quiet "function"` | `255.60ms` | `3.24ms` | `1.19ms` |
| `Literal short "wp"` | `438.63ms` | `98.22ms` | `153.09ms` |
| `Literal whole word` | `703.06ms` | `81.54ms` | `177.84ms` |
| `Regex new instance` | `459.58ms` | `36.76ms` | `155.48ms` |
| `Regex array call` | `399.86ms` | `44.81ms` | `177.25ms` |
| `Regex prefix literal` | `439.99ms` | `36.72ms` | `154.77ms` |
| `Regex suffix literal` | `583.73ms` | `124.62ms` | `224.11ms` |
| `Regex exact line literal` | `549.01ms` | `72.48ms` | `158.47ms` |
| `Regex literal collapse` | `439.21ms` | `81.90ms` | `172.53ms` |

### Scan Mode: Traversal

Traversal isolates walker and ignore-rule overhead without the text matcher dominating the result.

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Full traversal` | `44.98ms` | `12.36ms` | `44.48ms` |

### Scan Mode: Parallel Text

Parallel text shows whether worker fan-out helps on real one-shot scan workloads after traversal and matcher costs are included.

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `1 worker` | `439.83ms` | `81.72ms` | `180.08ms` |
| `2 workers` | `426.52ms` | `83.31ms` | `179.49ms` |
| `4 workers` | `441.82ms` | `83.23ms` | `182.74ms` |

### Scan Mode: AST

Cold AST mode optimizes for one-shot structural search without a prebuilt fact index or cached parse store.

| Operation | greph | sg |
| --- | ---: | ---: |
| `new $CLASS()` | `2859.27ms` | `4090.82ms` |
| `array($$$ITEMS)` | `5537.70ms` | `4133.41ms` |

### Indexed Text Mode

Indexed text mode optimizes for repeated text queries on the same tree, where warm postings and query caches can avoid most rescanning.

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed literal "function"` | `90.50ms` | `86.12ms` | `173.60ms` |
| `Indexed literal case insensitive` | `98.94ms` | `92.68ms` | `250.24ms` |
| `Indexed literal short "wp"` | `124.91ms` | `108.16ms` | `151.26ms` |
| `Indexed literal whole word` | `92.73ms` | `86.45ms` | `176.18ms` |
| `Indexed regex new instance` | `10.46ms` | `37.67ms` | `166.84ms` |
| `Indexed regex array call` | `26.24ms` | `44.46ms` | `174.53ms` |

### Indexed Summary Queries

Indexed summary queries optimize for count, filename, and quiet/existence answers that can often be served directly from warmed postings.

| Operation | greph | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed count "function"` | `10.90ms` | `49.61ms` | `148.46ms` |
| `Indexed files with "function"` | `10.00ms` | `45.57ms` | `95.96ms` |
| `Indexed files without "function"` | `9.74ms` | `81.56ms` | `96.10ms` |
| `Indexed quiet "function"` | `5.50ms` | `3.40ms` | `1.18ms` |

### Indexed / Cached AST

Indexed and cached AST modes optimize for repeated structural PHP queries by reusing file-level facts or whole parsed trees instead of reparsing the repository.

| Operation | greph | sg |
| --- | ---: | ---: |
| `Indexed new $CLASS()` | `10.89ms` | `4093.61ms` |
| `Indexed array($$$ITEMS)` | `324.79ms` | `4149.52ms` |
| `Cached new $CLASS()` | `13.03ms` | `4096.84ms` |
| `Cached array($$$ITEMS)` | `314.29ms` | `4146.10ms` |

### Build Costs

Build costs measure the upfront price of preparing warmed indexes and caches that later queries reuse.

| Operation | greph |
| --- | ---: |
| `Build trigram index` | `12306.51ms` |
| `Build AST fact index` | `1354.98ms` |
| `Build cached AST store` | `10148.88ms` |

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
