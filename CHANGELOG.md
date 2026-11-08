# Changelog

## [0.3.4] - 2026-11-08

### Fixed
- Regex `-l` and `-L` searches no longer stop at the first line that contains the prefilter literal. A file with `use App\Logger;` before `$x = new Logger();` was missing from `greph -l 'new\s+Logger'` and listed by `-L`. This affected plain text search, the `rg` wrapper, `Greph::searchText()` and indexed search. Correction to the 0.3.2 entry: its prefilter fix did not cover these file-list modes.
- Case-insensitive fixed-string search (`-F -i`) folds case with Unicode rules like ripgrep and greph's own regex `-i`, in plain, whole-word (`-w`), `rg` wrapper and indexed search. `-F -i café` now matches `CAFÉ`, and `-F -i kelvin` matches the Kelvin sign. ASCII patterns keep the `stripos()` path unless a file contains the Kelvin sign or the long s.
- Indexed case-insensitive fixed strings seed the trigram lookup only with the runs between non-ASCII bytes and the letters `k` and `s`, and `-F -i -w` skips word postings for patterns with `k` or `s`, so those candidates are no longer dropped.
- Indexed search neither reads nor writes the query cache while the freshness check reports the index stale, so a repeated query on a stale `manual-refresh` index no longer returns lines deleted from disk. Correction to the 0.3.1 docs, which said stale data never produces such a match: it did through the query cache. The docs now describe the `static` profile, which skips the check and keeps serving cached results until the next refresh or build.
- Query cache keys use a new result version, so cached `-l`, `-L` and `-F -i` results from earlier releases are not reused.
- Regex patterns that contain `#` are wrapped with a delimiter the pattern does not use, so `r\s\#f+` and `(?#comment)foo` search instead of failing with "Invalid regex pattern".
- `greph-index search` and `greph-index set search` accept `-q` and `--quiet`, which print nothing and answer with the exit code like native `greph -q`. The 0.3.1 docs and the 0.3.2 entry described indexed `-q`, but the CLI rejected it.
- An empty pattern argument (`greph -c '' .`, `greph-index search ''`) no longer prints an "Uninitialized string offset" warning before the results. With `-w`, an empty pattern matches only lines with a position between two non-word characters, like ripgrep, instead of every line.
- The oracle harness passes `--files-without-match` to ripgrep for `-L` scenarios; ripgrep reads `-L` as `--follow`.

### Added
- Oracle scenarios `text/regex-files-with-matches`, `text/regex-files-without-match`, `text/literal-unicode-case-insensitive`, `text/literal-kelvin-whole-word` and `text/regex-escaped-hash`, checked against ripgrep and, for the file-list modes, grep.

## [0.3.3] - 2026-11-07

### Added
- `composer test:upstream` runs the new PHPUnit `upstream` suite, which compares the `rg` wrapper's ignore handling with ripgrep 15.2.0 across multiple roots and a global excludes file. The test checks that ripgrep 15.2.0 is installed. CI runs it, and `composer verify` includes it. The test existed since 0.3.0 but was outside every configured suite, so nothing ran it.

### Changed
- CI checks the SHA-256 checksum of the ripgrep 15.2.0 archive before installing it.
- `composer test` keeps running only the unit and integration suites through `defaultTestSuite`.

### Fixed
- Correction to the 0.2.0 entry and the README: `src/` line coverage is not 100%. With Xdebug over the unit and integration suites it measures 91.25% (8169 of 8952 lines), excluding code that runs in forked workers and child processes. The README and the testing docs now state the measured figure and how to reproduce it.

## [0.3.2] - 2026-11-07

### Fixed
- Regex literal prefilters in text search, indexed text search, and the `rg` wrapper only use literals that every match must contain. Alternations (`alpha|nothing`), optional characters (`colou?r`), zero-minimum repeats (`x{0,2}`), and brace quantifiers (`\d{3}`) no longer drop matching lines. Inline flags, backreferences, `\Q...\E`, and other unmodelled constructs disable the prefilter, and case-insensitive searches skip literals that Unicode case folding can match differently.
- Indexed whole-word `-l`, `-L`, and `-q` searches verify each candidate file instead of answering from case-folded word postings, so case-sensitive queries and Unicode word boundaries are honoured. The `direct_word_summary` field is removed from `--trace-plan` output.
- Indexed query cache keys include a result version, so results cached by earlier releases are not reused.

### Added
- Oracle scenarios `text/regex-alternation` and `text/regex-optional-literal`, checked against ripgrep.

## [0.3.1] - 2026-11-07

### Fixed
- Compare filesystem-sensitive grep traversal order with native grep on the same temporary workspace, keeping exact output assertions and committed semantic oracles intact across macOS and Linux CI.

### Changed
- The docs introduction now gives an overview of every mode with its command and facade method, a minimal example with real output, and a map of the documentation sections.
- Indexed Text Search explains when the index beats plain text search, how queries are planned, the query cache, direct summaries, and how stale `manual-refresh` indexes miss new and edited files. It also corrects the refresh, build and identifier postings descriptions to match the implementation.

## [0.3.0] - 2026-10-15

### Added
- PHP outlines through `Greph::outline()` and `sg outline`, following ast-grep
  0.51.0: classes, interfaces, traits, enums, functions, imports, and members.
- Outline JSON/stream output, names/signatures/digest/expanded views, symbol and
  regex filters, public-member filtering, and standard input with `--stdin`.
- ripgrep 15.2 global ignore discovery through `GIT_CONFIG_GLOBAL` and
  `GIT_CONFIG_SYSTEM`, plus parent ignore rules for multiple search roots.
- Reproducible upstream outline fixtures and live differential ignore checks.

### Fixed
- Rewrites replace source atomically after the full replacement is written,
  preserve permissions and symlinks, and report failed writes without truncation.
- Explicitly named ignored directories remain searchable while file-level
  ignore rules still apply; repository excludes have lower priority than local rules.
- Linked worktrees resolve their common `info/exclude` file.
- Removed obsolete reflection calls in the test harness for PHP 8.5.

### Changed
- Oracle tooling prefers the current `ast-grep` executable over deprecated `sg`,
  with the npm fallback pinned to 0.51.0; CI pins ripgrep 15.2.0 and ast-grep 0.51.0.
- Added `upstream-versions.json` recording the GNU grep 3.12, ripgrep 15.2.0,
  and ast-grep 0.51.0 verification baseline.

## [0.2.5] - 2026-10-14

### Fixed
- Rewrites support empty constructor arguments when the source omits parentheses, and place arguments before captured anonymous-class bodies while preserving their original source text

## [0.2.4] - 2026-10-14

### Fixed
- AST rewrites splice original captured source text, preserving comments, layout, literal spelling, and heredoc indentation. Replacements are checked for syntax and expression structure, with parentheses added when needed
- Nested and equal-start rewrite matches consistently select the outermost match, matching ast-grep
- Unbound rewrite metavariables are rejected before files are written, with the missing name in the error
- Composer declares the ctype, tokenizer, and zlib extensions used at runtime
- Ignored vendor fixtures are committed and count-mode oracle inputs have deterministic order, so the corpus passes in fresh clones on different filesystems
- `test-regression --jobs N` runs scenarios concurrently when pcntl is available, preserves result order, and rejects invalid job counts
- Parallel benchmarks count a regex that starts workers on the WordPress corpus. The new workload uses a separate suite identity to avoid comparing it with old literal-search timings

### Changed
- CI runs the oracle corpus alongside PHPUnit, including regressions for captured literal spelling and outermost nested rewrites

## [0.2.3] - 2026-09-24

### Fixed
- `vendor/bin/greph`, `greph-index`, `rg` and `sg` work when greph is installed as a dependency. The binaries only looked for the autoloader in their own `vendor/`
- The Composer package no longer ships benchmarks, scenarios, tests, docs or development scripts, taking it from 416 KB to 130 KB

### Changed
- The `Greph\Benchmarks\` namespace is now dev-only (`autoload-dev`), matching the `benchmarks/` directory it maps, which is not part of the package
- Documentation rewritten as portable Markdown in `docs/`
- Development dependencies raised to the inline0 canonical minimums, including `squizlabs/php_codesniffer` `^3.13.6` for CVE-2026-67434. Dev only, no effect on consumers

## [0.2.2] - 2026-05-19

### Changed
- Raised PHPStan analysis to level 10 with tightened type shapes, generics, and narrowing helpers; added `phpstan/phpstan-phpunit` to surface PHPUnit assertion narrowing in reflection-based test helpers

## [0.2.1] - 2026-05-12

### Added
- Explicit process-disabled support helpers for `ToolResolver` and `CommandRunner`

## [0.2.0] - 2026-04-14

### Added
- Lifecycle-aware warmed index profiles: `static`, `manual-refresh`, `opportunistic-refresh`, and `strict-stale-check`
- Multi-index warmed search for text, AST fact indexes, and cached AST search via repeated `--index-dir`
- Manifest-backed warmed index sets with `greph-index set build|refresh|stats|search`
- Planner diagnostics with `--trace-plan` and index-origin labeling with `--show-index-origin`
- Public PHP API helpers for multi-index and set-based warmed search:
  - `searchTextIndexedMany(...)`
  - `searchTextIndexedSet(...)`
  - `searchAstIndexedMany(...)`
  - `searchAstIndexedSet(...)`
  - `searchAstCachedMany(...)`
  - `searchAstCachedSet(...)`

### Changed
- Improved warmed indexed text performance and README benchmark baselines
- Expanded `rg` / `sg` compatibility coverage and the generated feature matrix
- Reached `100%` classes, methods, and lines coverage across `src/`
- Promoted warmed AST fact and cached search into a documented CLI workflow
- Overhauled the docs site to cover lifecycle policies, multi-index search, index sets, and planner tracing

## [0.1.0] - 2026-04-11

### Added
- Native `greph` facade and CLI for text search, AST search, and AST rewrite in pure PHP
- `rg` and `sg` compatibility wrappers backed by the Greph engine
- Indexed text mode with warmed trigram, whole-word, and identifier postings
- Indexed AST fact search and cached AST search with CLI entrypoints
- Probe-driven feature matrix output in `FEATURE_MATRIX.md` and `FEATURE_MATRIX.json`
- Oracle regression corpus for text, AST, and rewrite behavior
- GitHub Actions CI and benchmark workflows with published README benchmark tables
- Composer-ready release metadata, changelog, and a dedicated docs app under `docs/`

### Changed
- Renamed the package and public surface from `phgrep` to `greph`
- Standardized default on-disk artifacts to `.grephignore`, `.greph-index`, `.greph-ast-index`, and `.greph-ast-cache`
