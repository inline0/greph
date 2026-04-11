# Changelog

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

