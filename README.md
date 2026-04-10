# phgrep

Pure PHP code search and structural refactoring tool.

## Modes

- `bin/rg`: ripgrep-style compatibility wrapper backed by the PHP engine.
- `bin/sg`: ast-grep-style compatibility wrapper backed by the PHP engine.
- `bin/phgrep`: native combined text + AST CLI.
- `bin/phgrep-index`: separate indexed text mode. Build or refresh an on-disk trigram index, then run text searches against that index.

Indexed mode is intentionally separate from scan mode, and the benchmark tables below keep those paths separate too.

Compatibility note:
- `rg` and `sg` are compatibility entrypoints for agent/tooling use, not full reimplementations of every upstream flag.
- [FEATURE_MATRIX.md](FEATURE_MATRIX.md) tracks exactly what the PHP port implements, what is partial, and what is still out of scope.

## Quick Start

```bash
composer install
composer verify

bin/rg -F "function" src
bin/sg -p 'new $CLASS()' src

bin/phgrep-index build .
bin/phgrep-index search -F "function" .
```

By default, `bin/phgrep-index` stores its index in `.phgrep-index` under the indexed root.

## Feature Matrix

`FEATURE_MATRIX.md` is generated from live command probes, not maintained by hand.

```bash
bin/feature-matrix
```

That command refreshes:
- `FEATURE_MATRIX.md`
- `FEATURE_MATRIX.json`

The Markdown file is the readable summary. The JSON file keeps raw command, exit code, stdout, stderr, and note data for each probe.

## Performance

Benchmark tables below are always sourced from GitHub Actions CI, never from local runs. Current completed `main` baseline: run [`24234526655`](https://github.com/inline0/phgrep/actions/runs/24234526655) on the WordPress corpus, using `ubuntu-latest`, PHP `8.4`, `5` measured runs, and `1` warmup run. Tables below show the merged `main` head snapshot from that run.

Comparison tools:
- `rg`: ripgrep
- `grep`: GNU grep
- `sg`: ast-grep

### Scan Mode: Text And Traversal

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `Literal "function"` | `446.57ms` | `144.78ms` | `223.46ms` |
| `Literal case insensitive` | `450.35ms` | `156.00ms` | `288.99ms` |
| `Regex new instance` | `456.58ms` | `80.76ms` | `194.42ms` |
| `Regex array call` | `398.65ms` | `85.92ms` | `213.37ms` |
| `Full traversal` | `45.63ms` | `20.18ms` | `55.40ms` |

### Scan Mode: Parallel Text

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `1 worker` | `456.99ms` | `143.65ms` | `223.35ms` |
| `2 workers` | `446.09ms` | `147.18ms` | `223.39ms` |
| `4 workers` | `448.29ms` | `144.22ms` | `223.62ms` |

### Scan Mode: AST

| Operation | phgrep | sg |
| --- | ---: | ---: |
| `new $CLASS()` | `3436.26ms` | `8564.72ms` |
| `array($$$ITEMS)` | `6669.04ms` | `8652.00ms` |

### Indexed Text Mode

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed literal "function"` | `282.41ms` | `146.33ms` | `223.19ms` |
| `Indexed literal case insensitive` | `299.89ms` | `155.58ms` | `289.34ms` |
| `Indexed regex new instance` | `179.69ms` | `80.44ms` | `194.05ms` |
| `Indexed regex array call` | `163.27ms` | `85.78ms` | `213.71ms` |

The CLI-exposed indexed mode is text-first today. CI also tracks separate indexed/cached AST search modes below.

### Indexed Summary Queries

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed count "function"` | `277.81ms` | `109.83ms` | `181.37ms` |
| `Indexed files with "function"` | `81.44ms` | `100.21ms` | `121.04ms` |
| `Indexed files without "function"` | `81.91ms` | `139.90ms` | `107.87ms` |

### Indexed / Cached AST

| Operation | phgrep | sg |
| --- | ---: | ---: |
| `Indexed new $CLASS()` | `2724.44ms` | `8554.87ms` |
| `Indexed array($$$ITEMS)` | `6178.27ms` | `8642.14ms` |
| `Cached new $CLASS()` | `1688.84ms` | `8572.19ms` |
| `Cached array($$$ITEMS)` | `3861.60ms` | `8668.03ms` |

### Build Costs

| Operation | phgrep |
| --- | ---: |
| `Build trigram index` | `10403.94ms` |
| `Build AST fact index` | `1418.25ms` |
| `Build cached AST store` | `10381.02ms` |
