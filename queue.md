# Indexed Performance Queue

This queue is only for the next indexed-mode performance tier.

The current indexed path is useful, but it is still mostly a candidate filter:
- use trigrams to narrow files
- reopen candidate files
- rescan them with the normal matcher
- format grep-compatible output afterward

That is why warm indexed queries are better than scan mode, but not dramatically better yet.

## Current Baseline

Source of truth: GitHub Actions run `24194937014`

WordPress median timings:
- `text` `Literal "function"`: `446.43ms`
- `text` `Literal case insensitive`: `455.47ms`
- `text` `Regex new instance`: `459.13ms`
- `text` `Regex array call`: `404.85ms`
- `indexed-build` `Build trigram index`: `10122.60ms`
- `indexed-text` `Indexed literal "function"`: `261.85ms`
- `indexed-text` `Indexed literal case insensitive`: `259.93ms`
- `indexed-text` `Indexed regex new instance`: `223.73ms`
- `indexed-text` `Indexed regex array call`: `187.97ms`

Real gap:
- indexed mode is only about `41%` to `54%` faster than scan mode on the current WordPress text queries
- that means the next wins must come from serving more of the query directly from index structures instead of just filtering candidate files

## Guardrails

1. Keep scan mode and indexed mode separate.
2. Keep indexed benchmark tables separate from scan-mode tables.
3. Use GitHub Actions `Benchmark` on WordPress as the gate.
4. Benchmark one indexed-mode change at a time.
5. Run `composer verify` before every benchmark push.
6. Do not keep an indexed perf change unless CI confirms the win.
7. Preserve current grep-compatible output and exit codes.

## Main Objective

Move indexed mode from:
- `candidate filtering + file rescanning`

Toward:
- `index-assisted answering with minimal file rereads`

## Priority Queue

### Phase 1: Make The Current Index Worth Loading

- [ ] Add a compact file-record format for indexed queries only.
  - Keep runtime metadata minimal: file id, relative path, size, flags, order.
- [ ] Split refresh-only data from query-time data completely.
  - Runtime queries should not pay to load anything only needed for refresh.
- [ ] Add a lightweight index stats command to inspect:
  - indexed file count
  - postings count
  - on-disk size
  - build time
  - last refresh time
- [ ] Add query-time memory profiling for indexed mode in benchmarks.
  - We need size and latency together, not latency alone.
- [ ] Add a benchmark row for index load time by itself.
  - This isolates storage/layout cost from actual matching cost.

### Phase 2: Improve Candidate Precision

- [ ] Add multi-seed regex planning.
  - Use more than one required literal when the regex safely exposes multiple anchors.
- [ ] Add best-seed selection for regex.
  - Prefer the rarest or longest stable literal instead of the first extracted one.
- [ ] Add query trigram selectivity heuristics.
  - Skip weak trigrams if they explode candidate sets.
- [ ] Add short-query special handling.
  - For 1-2 byte literals, use a different fast path instead of pretending trigrams help.
- [ ] Add whole-word literal planning.
  - Combine word-boundary semantics with index candidate narrowing more precisely.

### Phase 3: Stop Reopening So Many Files

- [ ] Add per-file line offset tables as an optional index payload.
  - This is the first step toward fast grep-style output without rescanning line boundaries every time.
- [ ] Add exact literal occurrence blocks to the index for long literals.
  - Start with fixed-string mode only.
- [ ] Add a direct count-only indexed path.
  - For `-c`, answer counts from indexed occurrence data where possible.
- [ ] Add a direct files-with-matches indexed path.
  - For `-l`, answer from postings/occurrence metadata without reopening files when safe.
- [ ] Add a direct files-without-matches indexed path.
  - For `-L`, derive from selected file set minus matched file ids.
- [ ] Add an exists fast path.
  - If the caller only needs a success/no-success exit code, stop after the first indexed proof.

### Phase 4: Build A Real Literal Index

- [ ] Add a word/identifier inverted index alongside trigrams.
  - Trigrams are broad; exact identifier and word postings are much sharper.
- [ ] Add a literal-query planner that chooses between:
  - word index
  - trigram index
  - direct scan fallback
- [ ] Add case-folded word postings for case-insensitive literal search.
- [ ] Add index entries for common PHP token kinds.
  - function names
  - class names
  - method names
  - variable names
- [ ] Benchmark exact-word queries separately from substring queries.
  - They should not be treated as one category anymore.

### Phase 5: Serve More Results Directly From The Index

- [ ] Add stored line snippets for literal occurrences.
  - Enough to print normal grep lines without rereading files for exact literal hits.
- [ ] Add stored line/column positions for exact literal occurrences.
- [ ] Add a direct indexed formatter path for fixed-string matches.
  - If output is already known, do not rescan the file.
- [ ] Add a direct JSON indexed formatter path for fixed-string matches.
- [ ] Add a fallback boundary.
  - If the query needs context lines, complex regex verification, or uncommon formatting, fall back cleanly to file reads.

### Phase 6: Make Refresh Cheap Enough For Real Use

- [ ] Replace size+mtime-only reuse with optional content hash verification for changed files.
- [ ] Add dirty-file refresh timing benchmarks.
  - single file changed
  - ten files changed
  - one file deleted
  - one file added
- [ ] Add postings compaction rules.
  - Incremental refresh should not slowly fragment index layout.
- [ ] Add lock files and stale-lock cleanup for build/refresh.
- [ ] Add crash-safe temp index swap behavior tests.

### Phase 7: Optional Warm Service Layer

- [ ] Design a daemon mode only after static indexed mode flattens.
  - Do not build this first.
- [ ] If needed, add a long-lived process that keeps:
  - file table
  - postings buckets
  - hot query planner state
  in memory.
- [ ] Add a filesystem watcher only after daemon mode proves useful.
- [ ] Keep daemon benchmarks completely separate from one-shot indexed CLI numbers.

## Explicit Experiment List

These are the next concrete experiments to run one by one on CI:

1. Add benchmark instrumentation for indexed load time and indexed memory use.
2. Add multi-seed regex planning and benchmark only `indexed-text`.
3. Add a direct `-l` indexed path and benchmark `indexed-text` plus correctness tests.
4. Add a direct `-L` indexed path and benchmark it separately.
5. Add a direct `-c` indexed path and benchmark it separately.
6. Add an exact word index for identifiers and benchmark literal whole-word queries.
7. Add stored line offsets and test whether literal output can avoid reopening files.
8. Add stored exact literal occurrences for long literals and benchmark fixed-string mode.
9. Re-run full WordPress scan vs indexed benchmarks after every accepted indexed win.

## Success Criteria

- [ ] Indexed literal queries are materially faster than the current `~260ms` WordPress baseline.
- [ ] Indexed regex queries are materially faster than the current `~188ms` to `~224ms` WordPress baseline.
- [ ] Count-only and file-only indexed queries avoid reopening most files.
- [ ] Index load cost is measured explicitly and kept under control.
- [ ] Refresh remains correct and incremental after storage changes.
- [ ] README benchmark tables stay split between scan mode and indexed mode.

## First Three Next Steps

1. Add explicit indexed load-time and memory benchmark rows.
2. Implement a direct indexed `-l` / `-L` / `-c` fast path.
3. Implement multi-seed regex planning and benchmark it on CI.
