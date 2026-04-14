# Changelog

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
