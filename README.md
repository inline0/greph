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

Benchmarks below come from GitHub Actions run [`24194937014`](https://github.com/inline0/phgrep/actions/runs/24194937014) on the WordPress corpus, using `ubuntu-latest`, PHP `8.4`, `5` measured runs, and `1` warmup run. Indexed query timings exclude index build time; build is listed separately.

### Scan Mode

| Category | Operation | phgrep | Fastest external | Gap |
| --- | --- | ---: | ---: | ---: |
| `text` | `Literal "function"` | `446.43ms` | `161.87ms (rg)` | `+175.80%` |
| `text` | `Literal case insensitive` | `455.47ms` | `171.04ms (rg)` | `+166.30%` |
| `text` | `Regex new instance` | `459.13ms` | `94.98ms (rg)` | `+383.42%` |
| `text` | `Regex array call` | `404.85ms` | `88.97ms (rg)` | `+355.07%` |
| `walker` | `Full traversal` | `45.92ms` | `20.25ms (rg)` | `+126.73%` |
| `parallel` | `1 worker` | `444.00ms` | `161.95ms (rg)` | `+174.16%` |
| `parallel` | `2 workers` | `1054.53ms` | `163.60ms (rg)` | `+544.56%` |
| `parallel` | `4 workers` | `1137.28ms` | `159.81ms (rg)` | `+611.63%` |
| `ast` | `new $CLASS()` | `3091.08ms` | `8519.37ms (sg)` | `-63.72%` |
| `ast` | `array($$$ITEMS)` | `6078.42ms` | `8651.25ms (sg)` | `-29.74%` |

### Indexed Mode

Indexed mode currently targets repeated text queries. AST search and rewrite remain scan-mode operations.

| Category | Operation | phgrep | Fastest external | Gap |
| --- | --- | ---: | ---: | ---: |
| `indexed-build` | `Build trigram index` | `10122.60ms` | `n/a` | `n/a` |
| `indexed-text` | `Indexed literal "function"` | `261.85ms` | `146.95ms (rg)` | `+78.19%` |
| `indexed-text` | `Indexed literal case insensitive` | `259.93ms` | `172.58ms (rg)` | `+50.61%` |
| `indexed-text` | `Indexed regex new instance` | `223.73ms` | `95.33ms (rg)` | `+134.70%` |
| `indexed-text` | `Indexed regex array call` | `187.97ms` | `89.29ms (rg)` | `+110.51%` |

On the same CI run, indexed text queries reduced `phgrep`'s own warm-query time versus scan mode by about `41%` to `54%`, depending on the pattern.
