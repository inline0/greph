# TODO

This file now tracks the warm-index work that has already shipped and the
smaller follow-up ideas that remain.

## Shipped

The daemon-free warm-index roadmap is now in place.

- [x] Lifecycle-aware text, AST fact, and AST cache indexes
- [x] Stored lifecycle metadata in all index formats
- [x] Opportunistic refresh during warmed search when the stale set is cheap
- [x] Strict stale rejection for strict indexes
- [x] Multi-index warmed text search
- [x] Multi-index warmed AST fact search
- [x] Multi-index warmed AST cache search
- [x] Repeated `--index-dir` support for multi-index search and stats
- [x] Optional index-origin labeling for warmed multi-index CLI output
- [x] Named index-set manifest format via `.greph-index-set.json`
- [x] `greph-index set build`
- [x] `greph-index set refresh`
- [x] `greph-index set stats`
- [x] `greph-index set search`
- [x] PHP API helpers for manifest-backed warmed search:
  - `Greph::loadIndexSet(...)`
  - `Greph::searchTextIndexedSet(...)`
  - `Greph::searchAstIndexedSet(...)`
  - `Greph::searchAstCachedSet(...)`
- [x] Mixed static and mutable index-set workflows
- [x] Dry-run refresh behavior reporting in `stats`
- [x] Query-cache and cached-tree visibility in `stats`
- [x] README coverage for lifecycle, multi-index, manifests, and WordPress-style layouts

## Current Recommended Workflow

- Build stable baseline indexes as `static`
- Build actively changing overlays as `opportunistic-refresh`
- Use repeated `--index-dir` for ad hoc warmed searches
- Use `.greph-index-set.json` for repeatable multi-index workflows
- Use `greph-index ... stats --dry-refresh` before relying on a warmed result set

## Remaining Ideas

These are not blockers for the shipped warm-index product. They are future
improvement ideas.

### Direct Warmed Text Serving

- [ ] Store line-offset tables for warmed text indexes
- [ ] Store exact occurrence blocks for fixed-string queries
- [ ] Serve fixed-string normal output directly from warmed data
- [ ] Serve fixed-string JSON output directly from warmed data
- [ ] Add direct quiet/count/file-list serving where the index can answer without reopening files

### Planner Diagnostics

- [ ] Add explicit warmed-text planner trace output
- [ ] Add explicit warmed-AST planner trace output
- [ ] Add benchmark trace metrics for postings load, candidate count, and verified file count

### AST Warmed Planner Extensions

- [ ] Add richer AST fact coverage only where benchmarks justify it
- [ ] Add candidate-pruning diagnostics for warmed AST search
- [ ] Reuse warmed AST narrowing for rewrite mode if rewrite becomes a published warm path

### Benchmarks

- [ ] Add benchmark suites for multi-index warmed text search
- [ ] Add benchmark suites for mixed static/mutable index sets
- [ ] Add benchmark suites for warmed AST set search
- [ ] Keep CI as the source of truth for any new performance claim
