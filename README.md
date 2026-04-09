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

Benchmark tables below are always sourced from GitHub Actions CI, never from local runs. Current baseline: run [`24212668592`](https://github.com/inline0/phgrep/actions/runs/24212668592) on the WordPress corpus, using `ubuntu-latest`, PHP `8.4`, `5` measured runs, and `1` warmup run. Tables below show the merged `main` head snapshot from that run.

Comparison tools:
- `rg`: ripgrep
- `grep`: GNU grep
- `sg`: ast-grep

### Scan Mode: Text And Traversal

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `Literal "function"` | `437.44ms` | `138.28ms` | `223.46ms` |
| `Literal case insensitive` | `456.33ms` | `145.66ms` | `288.99ms` |
| `Regex new instance` | `455.91ms` | `68.09ms` | `194.42ms` |
| `Regex array call` | `404.52ms` | `80.51ms` | `213.37ms` |
| `Full traversal` | `45.99ms` | `20.28ms` | `55.40ms` |

### Scan Mode: Parallel Text

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `1 worker` | `463.83ms` | `137.08ms` | `223.35ms` |
| `2 workers` | `448.87ms` | `137.73ms` | `223.39ms` |
| `4 workers` | `451.85ms` | `136.46ms` | `223.62ms` |

### Scan Mode: AST

| Operation | phgrep | sg |
| --- | ---: | ---: |
| `new $CLASS()` | `3064.48ms` | `8141.68ms` |
| `array($$$ITEMS)` | `6024.89ms` | `8226.80ms` |

### Indexed Text Mode

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed literal "function"` | `485.46ms` | `137.97ms` | `223.19ms` |
| `Indexed literal case insensitive` | `498.68ms` | `144.80ms` | `289.34ms` |
| `Indexed regex new instance` | `171.54ms` | `68.50ms` | `194.05ms` |
| `Indexed regex array call` | `190.82ms` | `80.30ms` | `213.71ms` |

The CLI-exposed indexed mode is text-first today. CI also tracks separate indexed/cached AST search modes below.

### Indexed Summary Queries

| Operation | phgrep | rg | grep |
| --- | ---: | ---: | ---: |
| `Indexed count "function"` | `272.46ms` | `99.41ms` | `181.37ms` |
| `Indexed files with "function"` | `68.63ms` | `91.33ms` | `121.04ms` |
| `Indexed files without "function"` | `68.66ms` | `139.90ms` | `121.59ms` |

### Indexed / Cached AST

| Operation | phgrep | sg |
| --- | ---: | ---: |
| `Indexed new $CLASS()` | `2415.77ms` | `8163.51ms` |
| `Indexed array($$$ITEMS)` | `6383.69ms` | `8237.89ms` |
| `Cached new $CLASS()` | `1627.95ms` | `8149.28ms` |
| `Cached array($$$ITEMS)` | `4466.96ms` | `8211.55ms` |

### Build Costs

| Operation | phgrep |
| --- | ---: |
| `Build trigram index` | `9552.51ms` |
| `Build AST fact index` | `1323.92ms` |
| `Build cached AST store` | `9463.32ms` |
