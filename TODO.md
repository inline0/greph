# TODO

This file tracks the next Greph roadmap after the current performance program
was exhausted.

The focus here is not "more micro-optimizations at any cost". The focus is:

- keep Greph Composer-first
- keep Greph daemon-free
- improve warmed indexed search behavior
- add multi-index workflows that fit real agent and WordPress usage

## Current State

These parts already exist and should not be re-planned as new work:

- text index build and incremental refresh
- AST fact index build and incremental refresh
- AST cache build and incremental refresh
- custom index locations via `--index-dir`
- warmed indexed text search
- warmed indexed AST fact search
- warmed cached AST search

So the missing work is not "add indexes". The missing work is:

- better lifecycle policy around indexes
- better composition across multiple indexes
- better direct serving from warmed indexes
- better warm-mode ergonomics for real projects

## Design Constraints

- No daemon
- No required background service
- Must remain usable after `composer require`
- Warm indexes may exist on disk and be reused across runs
- Refresh must remain explicit or cheap/opportunistic, not always-on
- Greph must still support both static indexes and mutable indexes

## Goal 1: Index Lifecycle Profiles

We need a first-class lifecycle model for indexes instead of treating every
index the same.

- [ ] Add explicit index lifecycle profiles:
  - `static`
  - `manual-refresh`
  - `opportunistic-refresh`
  - `strict-stale-check`
- [ ] Define exact semantics for each profile:
  - `static`: never mutate automatically, even if stale
  - `manual-refresh`: fail or warn when stale, require explicit refresh
  - `opportunistic-refresh`: refresh only when the changed-file set is cheap
  - `strict-stale-check`: verify freshness and refuse stale results
- [ ] Add stored lifecycle metadata to text index state
- [ ] Add stored lifecycle metadata to AST fact index state
- [ ] Add stored lifecycle metadata to AST cache state
- [ ] Add CLI support to choose lifecycle at build time
- [ ] Add CLI support to inspect lifecycle in `stats`
- [ ] Add API support to choose lifecycle from PHP
- [ ] Decide how lifecycle interacts with query caches
- [ ] Decide how lifecycle interacts with custom `--index-dir`

## Goal 2: Opportunistic Refresh Without A Daemon

This is the closest daemon-free equivalent to "always warm".

- [ ] Add cheap stale detection before indexed text search
- [ ] Add cheap stale detection before indexed AST fact search
- [ ] Add cheap stale detection before cached AST search
- [ ] Reuse existing refresh builders when the changed-file set is small
- [ ] Add a configurable refresh threshold:
  - max changed files
  - max changed bytes
  - max refresh time budget
- [ ] Fall back cleanly when opportunistic refresh is too expensive
- [ ] Expose refresh decision details in debug output
- [ ] Expose refresh decision details in `stats`
- [ ] Add tests for:
  - unchanged index
  - one-file update
  - one-file add
  - one-file delete
  - too-many-files-changed fallback
  - stale static index

## Goal 3: Multi-Index Search

This is the biggest missing product feature for real project layouts.

Example use cases:

- WordPress core index + plugin index + theme index
- monorepo package indexes
- vendor snapshot index + actively developed app index
- static baseline index + mutable overlay index

### Product Shape

- [ ] Add a first-class "search across multiple indexes" concept
- [ ] Define index set semantics:
  - ordered list
  - merge results
  - stable dedupe
  - per-index stats
- [ ] Support text multi-index search
- [ ] Support AST fact multi-index search
- [ ] Support AST cached multi-index search
- [ ] Decide whether mixed AST modes in one request are allowed:
  - fact + cached
  - cached + scan fallback
  - text + AST is out of scope

### CLI

- [ ] Add repeated `--index-dir` support for multi-index search
- [ ] Or add `--index-set FILE` support if repeated flags become awkward
- [ ] Add examples for WordPress core + plugin indexes
- [ ] Add `stats` for multiple indexes in one command
- [ ] Add clear output showing which index a hit came from when requested
- [ ] Add an option to suppress index-origin labeling for grep-compatible output

### PHP API

- [ ] Add `Greph::searchTextIndexedMany(...)`
- [ ] Add `Greph::searchAstIndexedMany(...)`
- [ ] Add `Greph::searchAstCachedMany(...)`
- [ ] Accept `list<string>` index paths in a structured options object instead of overloading one string argument
- [ ] Keep existing single-index APIs stable

### Merge Semantics

- [ ] Define deterministic ordering across indexes
- [ ] Define how duplicate file paths are handled
- [ ] Define how identical roots mounted under different index dirs are handled
- [ ] Define how query caches behave for multi-index requests
- [ ] Define how stale one-index-in-the-set behaves:
  - fail whole request
  - skip stale index
  - opportunistically refresh stale subset

### WordPress Workflow

- [ ] Document "core + plugins + themes" indexing strategy
- [ ] Add a worked example:
  - build core index
  - build plugin index
  - search both
- [ ] Decide whether a plugin-local index should be rooted at plugin path or workspace root with path filtering

## Goal 4: Direct Serving From Warmed Text Indexes

Current warmed text mode is fast because candidate reduction is good.
The next jump is reducing file reopen and re-scan work for full output.

- [ ] Add optional line-offset tables for indexed text
- [ ] Add optional occurrence blocks for exact fixed-string matches
- [ ] Add direct count serving from occurrence data
- [ ] Add direct `-l` serving from occurrence data where cleaner than current path
- [ ] Add direct `-L` serving from occurrence data where cleaner than current path
- [ ] Add direct quiet/existence serving from occurrence data
- [ ] Add direct normal-output serving for fixed-string matches
- [ ] Add direct JSON-output serving for fixed-string matches
- [ ] Keep context queries on explicit fallback paths
- [ ] Keep regex queries on explicit fallback paths unless a safe direct plan exists
- [ ] Add benchmarks separating:
  - candidate filtering
  - verification
  - direct serving

## Goal 5: Better Warmed Text Planner

- [ ] Revisit seed planning only if backed by fresh benchmark rows
- [ ] Add planner/debug output that explains why a query took a given path
- [ ] Add explicit handling for:
  - broad literals
  - short literals
  - whole-word literals
  - case-insensitive literals
  - anchored regexes
- [ ] Make planner decisions observable in tests
- [ ] Add a benchmark-only trace mode to measure:
  - postings load cost
  - candidate count
  - verified file count
  - formatted result count

## Goal 6: Better Warmed AST Planner

AST already performs well warm. The missing work is better productization and
better composition, not basic viability.

- [ ] Add more explicit file-level AST fact coverage only where benchmarks justify it
- [ ] Add better planner/debug output for:
  - fact index hit path
  - cache hit path
  - cold fallback path
- [ ] Add optional candidate pruning diagnostics
- [ ] Add multi-index AST planning
- [ ] Add better stale handling for mixed fresh/stale AST index sets
- [ ] Reuse warmed AST narrowing for rewrite mode only if rewrite becomes a published performance surface

## Goal 7: Static vs Mutable Index Sets

This is directly tied to your idea.

We should support both:

- stable prebuilt indexes that remain intentionally frozen
- mutable indexes that can refresh cheaply when project files change

- [ ] Add explicit static index mode
- [ ] Add explicit mutable index mode
- [ ] Add "mixed set" support:
  - static core index
  - mutable plugin index
  - mutable app index
- [ ] Define how stale checks behave for static indexes:
  - likely "do not care, use as-is"
- [ ] Define how stale checks behave for mutable indexes:
  - likely "refresh if cheap or warn"
- [ ] Add examples for:
  - WordPress core static index
  - plugin mutable index
  - theme mutable index

## Goal 8: Index Set Manifests

For multi-index workflows, ad hoc CLI flags will get awkward quickly.

- [ ] Add an index manifest file format
- [ ] Decide file name:
  - `.greph-index-set.php`
  - `.greph-index-set.json`
  - `.greph-index-set.phpbin`
  - or similar
- [ ] Manifest should support:
  - named indexes
  - root path
  - index dir
  - mode (`text`, `ast-index`, `ast-cache`)
  - lifecycle (`static`, `manual-refresh`, `opportunistic-refresh`)
  - priority
  - enabled/disabled
- [ ] Add CLI support:
  - `greph-index set build`
  - `greph-index set refresh`
  - `greph-index set stats`
  - `greph-index set search`
- [ ] Add tests for manifest loading, invalid manifests, and mixed index sets

## Goal 9: Better Stats And Visibility

- [ ] Expand `stats` output for text indexes:
  - query cache count
  - query cache size
  - lifecycle policy
  - stale status
- [ ] Expand `stats` output for AST indexes and caches:
  - lifecycle policy
  - stale status
  - cached tree count
  - query cache count
- [ ] Add stats for index sets
- [ ] Add per-index and aggregate disk usage
- [ ] Add a dry-run refresh report:
  - what would refresh
  - how many files
  - estimated work

## Goal 10: Documentation

- [ ] Document index lifecycle profiles
- [ ] Document opportunistic refresh
- [ ] Document static indexes vs mutable indexes
- [ ] Document multi-index search
- [ ] Document index manifests
- [ ] Add WordPress examples
- [ ] Add agent-oriented examples:
  - "search just the plugin"
  - "search core + plugin"
  - "search all warmed indexes"

## Goal 11: Benchmark Coverage For The New Features

- [ ] Add benchmarks for opportunistic refresh
- [ ] Add benchmarks for multi-index warm search
- [ ] Add benchmarks for static + mutable mixed sets
- [ ] Add benchmarks for direct text serving if implemented
- [ ] Add benchmarks for warmed AST multi-index search if implemented
- [ ] Keep CI as source of truth for any performance claim

## Recommended Execution Order

1. Index lifecycle profiles
2. Opportunistic refresh for existing single-index workflows
3. Multi-index search API
4. Multi-index CLI
5. Index set manifests
6. Static + mutable mixed-set behavior
7. Direct warmed text serving
8. AST multi-index polish
9. Stats + docs + benchmarks

## Notes

- Multi-index search is not implemented today.
- Custom `--index-dir` already exists and is the current primitive we can build on.
- Incremental `refresh` already exists today for text, AST fact index, and AST cache.
- The new work is mainly orchestration, planner behavior, and multi-index product shape.
