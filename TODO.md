# TODO

This file now tracks the warm-index work that shipped and the follow-up ideas
that were either implemented, benchmarked, or explicitly deferred.

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
- [x] Explicit warmed-text planner trace output via `--trace-plan`
- [x] Explicit warmed-AST planner trace output via `--trace-plan`
- [x] Candidate/pruning diagnostics in warmed text and AST plans
- [x] Benchmark suites for multi-index warmed text search
- [x] Benchmark suites for mixed static/mutable index sets
- [x] Benchmark suites for warmed AST set search
- [x] Direct warmed quiet/file-list serving where exact whole-word postings can answer without reopening files

## Current Recommended Workflow

- Build stable baseline indexes as `static`
- Build actively changing overlays as `opportunistic-refresh`
- Use repeated `--index-dir` for ad hoc warmed searches
- Use `.greph-index-set.json` for repeatable multi-index workflows
- Use `greph-index ... stats --dry-refresh` before relying on a warmed result set
- Use `--trace-plan` whenever you need to understand warmed candidate selection

## Benchmarked Commands

These are the warmed benchmark commands exercised in this pass:

```bash
php -d memory_limit=1G bin/bench --category indexed-text-many --corpus wordpress
php -d memory_limit=1G bin/bench --category indexed-set-text --corpus wordpress
php -d memory_limit=1G bin/bench --category ast-indexed-set --corpus wordpress
php -d memory_limit=1G bin/bench --category ast-cached-set --corpus wordpress
```

Observed local WordPress results from those warmed validation runs:

- `Multi-index literal "function"`: `1827.29ms`
- `Multi-index regex new instance`: `719.76ms`
- `Set literal "function"`: `745.03ms`
- `Set indexed new $CLASS()`: `2656.01ms`
- `Set cached array($$$ITEMS)`: `2926.59ms`

## Evaluated And Deferred

These ideas were left out intentionally after this pass. They are not open
blockers for the current warmed-index product.

- [x] Line-offset tables for warmed text indexes were evaluated and deferred.
  Reason: they materially increase index size, while the current daemon-free
  model already gets most repeated-query wins from warmed postings and query
  caches.
- [x] Exact occurrence blocks for arbitrary fixed-string queries were evaluated
  and deferred.
  Reason: the storage amplification is high unless Greph becomes a broader
  content-cache product.
- [x] Direct fixed-string normal/JSON serving from core warmed data was
  deferred.
  Reason: without storing much more per-file line data, the cost/size tradeoff
  is poor for a Composer-installed CLI/library.
- [x] Richer AST fact coverage was deferred until benchmarks show a concrete
  win for specific pattern families.
- [x] Warmed rewrite narrowing was deferred until rewrite is promoted as a
  first-class warmed path.
- [x] CI remains the source of truth for published performance claims.

## Terminal State

There are no open warm-index TODO items left in this file.
