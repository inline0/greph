# phgrep

Pure PHP code search and structural refactoring tool.

## Modes

- `bin/phgrep`: one-shot scan mode for text search, AST search, and AST rewrite.
- `bin/phgrep-index`: separate indexed text mode. Build or refresh an on-disk trigram index, then run text searches against that index.

Indexed mode is intentionally separate from scan mode, and the benchmark tables below keep those paths separate too.

## Quick Start

```bash
composer install
composer verify

bin/phgrep -F "function" src
bin/phgrep --ast 'new $CLASS()' src

bin/phgrep-index build .
bin/phgrep-index search -F "function" .
```

By default, `bin/phgrep-index` stores its index in `.phgrep-index` under the indexed root.

## Performance

Benchmark tables below are always sourced from GitHub Actions CI, never from local runs. Current baseline: run [`24203553976`](https://github.com/inline0/phgrep/actions/runs/24203553976) on the WordPress corpus, using `ubuntu-latest`, PHP `8.4`, `5` measured runs, and `1` warmup run. Tables below show the clean accepted head snapshot from that run.

Comparison tools:
- `rg`: ripgrep
- `grep`: GNU grep
- `sg`: ast-grep

### Scan Mode: Text And Traversal

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `Literal "function"` | `451.39ms` | `161.88ms` | `213.10ms` |
| `Literal case insensitive` | `450.35ms` | `171.54ms` | `277.65ms` |
| `Regex new instance` | `452.13ms` | `95.19ms` | `171.91ms` |
| `Regex array call` | `397.88ms` | `91.18ms` | `197.97ms` |
| `Full traversal` | `44.54ms` | `19.87ms` | `48.31ms` |

### Scan Mode: Parallel Text

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `1 worker` | `457.87ms` | `147.24ms` | `212.47ms` |
| `2 workers` | `442.46ms` | `157.97ms` | `213.76ms` |
| `4 workers` | `438.98ms` | `160.29ms` | `213.55ms` |

### Scan Mode: AST

| Operation | phgrep | sg |
| --- | ---: | ---: |
| `new $CLASS()` | `3700.54ms` | `8498.38ms` |
| `array($$$ITEMS)` | `6332.39ms` | `8639.29ms` |

### Indexed Text Mode

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed literal "function"` | `11.22ms` | `160.35ms` | `214.16ms` |
| `Indexed literal case insensitive` | `483.40ms` | `171.16ms` | `275.36ms` |
| `Indexed regex new instance` | `179.09ms` | `95.08ms` | `171.92ms` |
| `Indexed regex array call` | `193.01ms` | `90.34ms` | `197.99ms` |

The CLI-exposed indexed mode is text-first today. CI also tracks separate indexed/cached AST search modes below.

### Indexed Summary Queries

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed count "function"` | `284.43ms` | `125.86ms` | `161.31ms` |
| `Indexed files with "function"` | `10.35ms` | `101.02ms` | `107.55ms` |
| `Indexed files without "function"` | `10.87ms` | `161.96ms` | `108.77ms` |

### Indexed / Cached AST

| Operation | phgrep | sg |
| --- | ---: | ---: |
| `Indexed new $CLASS()` | `2869.62ms` | `8582.12ms` |
| `Indexed array($$$ITEMS)` | `6672.03ms` | `8630.45ms` |
| `Cached new $CLASS()` | `1677.33ms` | `8514.34ms` |
| `Cached array($$$ITEMS)` | `4871.01ms` | `8624.10ms` |

### Build Costs

| Operation | phgrep |
| --- | ---: |
| `Build trigram index` | `9931.40ms` |
| `Build AST fact index` | `1416.29ms` |
| `Build cached AST store` | `9770.72ms` |

One known caveat for run `24203553976`: the `Indexed literal "function"` row is artificially low because that branch revision had a query-cache key collision between summary and full-output indexed text searches. The table still records the CI result verbatim, and the next CI refresh will replace it with the corrected value after the cache-key fix is benchmarked.
