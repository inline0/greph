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

Benchmark tables below are always sourced from GitHub Actions CI, never from local runs.

Current text baseline on `main`:
- run [`24239964252`](https://github.com/inline0/phgrep/actions/runs/24239964252)
- WordPress corpus
- `ubuntu-latest`
- PHP `8.4`
- `5` measured runs
- `1` warmup run

Current broader non-text baseline on `main`:
- run [`24234526655`](https://github.com/inline0/phgrep/actions/runs/24234526655)
- used for traversal, parallel, AST, indexed text, indexed summary, and build tables until the next full sweep lands

Current indexed-text baseline on `main`:
- run [`24243036470`](https://github.com/inline0/phgrep/actions/runs/24243036470)
- used for the indexed-text table below after the short-query cache merge

Comparison tools:
- `rg`: ripgrep
- `grep`: GNU grep
- `sg`: ast-grep

### Scan Mode: Text

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `Literal "function"` | `446.80ms` | `145.47ms` | `202.24ms` |
| `Literal case insensitive` | `446.38ms` | `150.32ms` | `267.84ms` |
| `Literal whole word` | `928.37ms` | `148.87ms` | `203.22ms` |
| `Regex new instance` | `457.64ms` | `80.69ms` | `171.18ms` |
| `Regex array call` | `399.52ms` | `85.71ms` | `196.17ms` |
| `Regex prefix literal` | `435.73ms` | `78.35ms` | `159.11ms` |
| `Regex suffix literal` | `584.12ms` | `217.36ms` | `284.74ms` |
| `Regex exact line literal` | `547.26ms` | `142.76ms` | `175.57ms` |
| `Regex literal collapse` | `431.17ms` | `143.23ms` | `203.36ms` |

### Scan Mode: Traversal

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
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
| `Indexed literal "function"` | `62.93ms` | `138.89ms` | `203.94ms` |
| `Indexed literal case insensitive` | `71.35ms` | `145.51ms` | `267.65ms` |
| `Indexed literal short "wp"` | `100.82ms` | `174.77ms` | `2145.97ms` |
| `Indexed literal whole word` | `66.11ms` | `137.11ms` | `203.59ms` |
| `Indexed regex new instance` | `6.67ms` | `67.46ms` | `169.57ms` |
| `Indexed regex array call` | `18.82ms` | `78.25ms` | `192.38ms` |

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
