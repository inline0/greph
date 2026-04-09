# Feature Matrix

Generated from live command probes, not hand-maintained guesses.

Generated at `2026-04-09T22:05:42+00:00` from real fixture workspaces. Raw evidence is stored in [FEATURE_MATRIX.json](FEATURE_MATRIX.json).

Status legend:
- `Pass`: the command probe succeeded.
- `Fail`: the command probe ran but did not satisfy the expected behavior.
- `Unavailable`: the provider command was not available in this environment.

## rg Compatibility Surface

| Feature | rg | bin/rg | Notes |
| --- | --- | --- | --- |
| Fixed-string search | Pass | Pass | Probe: `-F needle single.txt` |
| Case-insensitive fixed-string search | Pass | Pass | Probe: `-F -i needle single.txt` |
| Whole-word search | Pass | Pass | Probe: `-F -w needle words.txt` |
| Invert match | Pass | Pass | Probe: `-F -v needle invert.txt` |
| Count mode | Pass | Pass | Probe: `-F -c needle counts.txt` |
| Regexp alias | Pass | Pass | Probe: `--regexp needle single.txt` |
| Files with matches | Pass | Pass | Probe: `-F -l needle .` |
| Files without matches | Pass | Pass | Probe: `-F --files-without-match needle .` |
| Context lines | Pass | Pass | Probe: `-F -C 1 needle context.txt` |
| Line number output | Pass | Pass | Probe: `-n -F needle single.txt` |
| Filename override | Pass | Pass | Probe: `-H -F needle single.txt` |
| No-filename override | Pass | Pass | Probe: `-I -F needle .` |
| Max count | Pass | Pass | Probe: `-F -m 1 needle counts.txt` |
| Glob filter | Pass | Pass | Probe: `--glob *.php function .` |
| Type filter | Pass | Pass | Probe: `--type php function .` |
| Type exclusion | Pass | Pass | Probe: `--type-not php function .` |
| Hidden files | Pass | Pass | Probe: `--hidden -F secret .` |
| No ignore | Pass | Pass | Probe: `--no-ignore -F ignored .` |
| --files mode | Pass | Pass | Probe: `--files .` |
| Structured JSON output | Pass | Pass | Probe: `--json -F needle single.txt` using ripgrep JSON-event semantics |

## sg Compatibility Surface

| Feature | sg | bin/sg | Notes |
| --- | --- | --- | --- |
| Pattern search with `run --pattern` | Pass | Pass | Probe: `run --pattern array($$$ITEMS) src/App.php` |
| Default one-shot search | Pass | Pass | Probe: `--pattern array($$$ITEMS) src/App.php` |
| Rewrite via `run --rewrite` | Pass | Pass | Probe: `run --pattern array($$$ITEMS) --rewrite [$$$ITEMS] src/App.php` |
| Structured JSON output | Pass | Pass | Probe: `run --json --pattern dispatch($EVENT) src/App.php` |
| Structured JSON stream output | Pass | Pass | Probe: `run --json=stream --pattern dispatch($EVENT) src/App.php` |
| Structured JSON compact output | Pass | Pass | Probe: `run --json=compact --pattern dispatch($EVENT) src/App.php` |
| Files with matches | Pass | Pass | Probe: `run --files-with-matches --pattern array($$$ITEMS) src/App.php` |
| Glob filtering | Pass | Pass | Probe: `run --globs src/*.php --pattern dispatch($EVENT) .` |
| No-ignore hidden traversal | Pass | Pass | Probe: `run --no-ignore hidden --pattern dispatch($EVENT) .` |
| Rewrite dry-run | Pass | Pass | Probe: `run --pattern array($$$ITEMS) --rewrite [$$$ITEMS] src/App.php` without `--update-all` |
| Update-all rewrite | Pass | Pass | Probe: `run --pattern array($$$ITEMS) --rewrite [$$$ITEMS] --update-all src/App.php` |
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

