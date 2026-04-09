# Feature Matrix

Generated from live command probes, not hand-maintained guesses.

Generated at `2026-04-09T21:43:04+00:00` from real fixture workspaces. Raw evidence is stored in [FEATURE_MATRIX.json](FEATURE_MATRIX.json).

Status legend:
- `Pass`: the command probe succeeded.
- `Fail`: the command probe ran but did not satisfy the expected behavior.
- `Unavailable`: the provider command was not available in this environment.

## rg Compatibility Surface

| Feature | rg | bin/rg | Notes |
| --- | --- | --- | --- |
| Fixed-string search | Pass | Fail<br><sub>Expected output lines `needle`, got `2:needle`.</sub> | Probe: `-F needle single.txt` |
| Case-insensitive fixed-string search | Pass | Fail<br><sub>Expected output lines `needle, NEEDLE`, got `2:needle, 3:NEEDLE`.</sub> | Probe: `-F -i needle single.txt` |
| Whole-word search | Pass | Fail<br><sub>Expected output lines `needle`, got `1:needle`.</sub> | Probe: `-F -w needle words.txt` |
| Invert match | Pass | Fail<br><sub>Expected output lines `hay`, got `2:hay`.</sub> | Probe: `-F -v needle invert.txt` |
| Count mode | Pass | Fail<br><sub>Expected count output `2`, got `0`.</sub> | Probe: `-F -c needle counts.txt` |
| Files with matches | Pass | Fail<br><sub>Expected stdout to contain `single.txt`.</sub> | Probe: `-F -l needle .` |
| Files without matches | Pass | Pass | Probe: `-F --files-without-match needle .` |
| Context lines | Pass | Pass | Probe: `-F -C 1 needle context.txt` |
| Max count | Pass | Pass | Probe: `-F -m 1 needle counts.txt` |
| Glob filter | Pass | Pass | Probe: `--glob *.php function .` |
| Type filter | Pass | Pass | Probe: `--type php function .` |
| Hidden files | Pass | Pass | Probe: `--hidden -F secret .` |
| No ignore | Pass | Pass | Probe: `--no-ignore -F ignored .` |
| --files mode | Pass | Pass | Probe: `--files .` |
| Structured JSON output | Pass | Fail<br><sub>Expected newline-delimited JSON events.</sub> | Probe: `--json -F needle single.txt` using ripgrep JSON-event semantics |

## sg Compatibility Surface

| Feature | sg | bin/sg | Notes |
| --- | --- | --- | --- |
| Pattern search with `run --pattern` | Pass | Pass | Probe: `run --pattern array($$$ITEMS) src/App.php` |
| Rewrite via `run --rewrite` | Pass | Fail<br><sub>Expected stdout to contain `[1, 2, 3]`.</sub> | Probe: `run --pattern array($$$ITEMS) --rewrite [$$$ITEMS] src/App.php` |
| Structured JSON output | Pass | Pass | Probe: `run --json --pattern dispatch($EVENT) src/App.php` |
| Rewrite dry-run | Pass | Fail<br><sub>Expected dry-run output to contain rewritten code.</sub> | Probe: `run --pattern array($$$ITEMS) --rewrite [$$$ITEMS] src/App.php` without `--update-all` |
| Interactive rewrite accept | Fail<br><sub>Expected exit 0, got 101.</sub> | Pass | Probe: `run --pattern array($$$ITEMS) --rewrite [$$$ITEMS] --interactive src/App.php` with `y` |
| Interactive rewrite decline | Fail<br><sub>Expected exit 0, got 101.</sub> | Pass | Probe: `run --pattern array($$$ITEMS) --rewrite [$$$ITEMS] --interactive src/App.php` with `n` |
| Explicit PHP language flag | Pass | Pass | Probe: `run --lang php --pattern $CLIENT->send($MESSAGE) src/App.php` |

## Native phgrep Surface

| Feature | bin/phgrep | Notes |
| --- | --- | --- |
| Native text JSON output | Pass | Probe: `-F --json needle .` |
| Native AST JSON output | Pass | Probe: `-p dispatch($EVENT) --json src/App.php` |
| Native AST rewrite dry-run | Pass | Probe: `-p array($$$ITEMS) -r [$$$ITEMS] --dry-run src/App.php` |

## Indexed phgrep Surface

| Feature | bin/phgrep-index | Notes |
| --- | --- | --- |
| Index build | Pass | Probe: `build .` |
| Index refresh | Pass | Probe: `refresh .` after editing a tracked file |
| Indexed fixed-string search | Pass | Probe: `search -F needle .` |
| Indexed regex search | Pass | Probe: `search new\s+instance .` |
| Indexed count mode | Pass | Probe: `search -F -c needle counts.txt` |
| Indexed files with matches | Pass | Probe: `search -F -l needle .` |
| Indexed files without matches | Pass | Probe: `search -F -L needle .` |
| Indexed JSON output | Pass | Probe: `search -F --json needle .` |

