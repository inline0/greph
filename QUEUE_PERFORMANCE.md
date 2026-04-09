# Performance Queue

This file is the working backlog for performance work on `phgrep`.

The goal is simple:
- improve real-world performance on WordPress-sized corpora
- keep correctness green at all times
- accept changes only when GitHub Actions benchmarks confirm the win

## Benchmark Rules

1. Make one isolated performance change per commit.
2. Run `composer verify` before every benchmark push.
3. Use GitHub Actions `Benchmark` as the source of truth.
4. Use category-specific runs while iterating:
   - `ast` for AST matcher work
   - `text` for text search work
   - `parallel` only after scan work stabilizes
5. Use the WordPress corpus for real-world gating.
6. Compare against the exact previous commit SHA, not a short hash guess.
7. Keep only changes that beat runner noise.
   - Same-commit self-compare on the interleaved workflow shows normal noise of roughly `0%` to `3%`
   - Single-op changes under about `2%` are suspicious unless both benchmark ops move the same way
   - Regressions above noise get reverted quickly
8. After 2 to 3 accepted wins in one category, run a broader suite again.
9. Do not trust noisy local numbers over CI.
   - Local runs are only for smoke checks and direction

## Benchmark Workflow

Use this loop for every item below:

1. Implement one change.
2. Run `composer verify`.
3. Push the change.
4. Run GitHub benchmark workflow against the previous commit.
5. Keep or revert based on CI results.

Example:

```bash
gh workflow run Benchmark \
  --repo inline0/phgrep \
  -f corpus=wordpress \
  -f category=ast \
  -f repeat=5 \
  -f warmup=1 \
  -f compare_ref=$(git rev-parse <base-commit>)
```

## Current State

Current benchmark baseline commit: `e542ee4`

### Accepted Wins

- `7ca4f27` `Accelerate fixed-string text scans`
  - `Literal "function"`: `-42.15%`
  - `Literal case insensitive`: `-34.96%`
  - `Parallel 1 worker`: `-41.78%`
- `fd8a916` `Stream AST candidates through matching`
  - `new $CLASS()`: `-2.24%`
  - `array($$$ITEMS)`: `-3.63%`
- `02ba1ba` `Prune AST array syntax mismatches early`
  - `new $CLASS()`: `-1.53%`
  - `array($$$ITEMS)`: `-1.91%`
- `59f15d9` `Scan regex candidate lines by seed literal`
  - `Regex array call`: `-22.01%`
  - `Regex new instance`: `-23.13%`
  - literal text ops stayed within runner noise
- `693f5ec` `Prune zero-argument constructor candidates`
  - `new $CLASS()`: `-6.47%`
  - `array($$$ITEMS)`: `+0.53%`
  - kept because the target operation moved materially and the sibling op stayed within noise
- `6057d44` `Prefilter zero-argument constructor searches`
  - `new $CLASS()`: `-39.44%`
  - `array($$$ITEMS)`: `+1.37%`
  - kept because the target operation moved massively and the sibling op stayed within noise
- `c94da50` `Prefilter long array syntax searches`
  - `array($$$ITEMS)`: `-1.41%`
  - `new $CLASS()`: `-2.20%`
  - kept because both benchmark ops moved in the same direction with no regression signal
- `e542ee4` `Memoize AST capture fingerprints`
  - `array($$$ITEMS)`: `-2.69%`
  - `new $CLASS()`: `-1.41%`
  - kept because both AST ops moved in the right direction with no regression signal

### Accepted Benchmark Infrastructure

- `b94070d` `Interleave CI benchmark comparisons`
  - Head/base runs are interleaved instead of measured in two large blocks
  - Same-commit self-compare now stays near runner noise instead of inventing fake regressions
- `4a57c04` `Add benchmark artifact fetch helper`
  - `bin/bench-run <run-id>` downloads the workflow artifact and prints the markdown comparison immediately
  - CI result inspection is now one command instead of a manual artifact download plus path hunt
- `a8b84e8` `Add AST count-only benchmark path`
  - `ast-internal` measures parse plus match cost without `AstMatch` construction or sorting
  - the new category runs on both local and GitHub benchmark workflows
- `809671f` `Add AST parse-only benchmark path`
  - `ast-parse` measures parser cost after the existing AST prefilters, without candidate traversal or matching
  - combined with `ast` and `ast-internal`, it shows parser cost dominates current AST runs on WordPress

### Rejected Or Reverted

- `e55d073` `Prune AST fixed-arity mismatches early`
  - `array($$$ITEMS)`: roughly flat
  - `new $CLASS()`: regressed about `+4.00%`
  - reverted by `bbec078`
- `edecdf7` `Fast-path trailing variadic AST matches`
  - `array($$$ITEMS)`: small win
  - `new $CLASS()`: regressed about `+2.42%`
  - reverted by `ae1447d`
- `49180e9` `Strengthen regex literal prefilters`
  - `Literal "function"`: `+0.08%`
  - `Literal case insensitive`: `-0.60%`
  - `Regex array call`: `+0.56%`
  - `Regex new instance`: `+1.20%`
  - reverted by `2fa6111`
- `baf0a5d` `Defer AST code materialization`
  - `array($$$ITEMS)`: `+1.75%`
  - `new $CLASS()`: `+3.22%`
  - reverted by `c259b2f`
- `3580593` `Add regex line literal prefilters`
  - `Literal "function"`: `-0.11%`
  - `Literal case insensitive`: `-0.30%`
  - `Regex array call`: `-0.14%`
  - `Regex new instance`: `+0.59%`
  - reverted by `30bfd2f`
- `81b72e2` `Short-circuit identical AST captures`
  - `array($$$ITEMS)`: `-1.43%`
  - `new $CLASS()`: `+1.28%`
  - reverted by `e2c561d`

### In Flight

- Refresh full WordPress CI benchmark baseline after accepted AST prefilter work
  - run all categories, not just focused AST slices
  - keep `2b9569c` only because the global run stayed flat to slightly positive
  - use that run as the comparison point before starting indexed-mode work

## Ordered Queue

### Indexed Mode And Trigram Search

- [ ] Define the indexed-mode product shape.
  - Decide whether this is `phgrep index build`, `phgrep search --index`, or both.
- [ ] Decide the compatibility contract.
  - Indexed mode must preserve normal output, exit codes, ignore behavior, and match semantics.
- [ ] Define the on-disk index layout.
  - Versioned directory, metadata file, postings store, file table, and temporary build area.
- [ ] Add index versioning and invalidation rules.
  - Any schema change, matcher semantic change, or incompatible CLI flag must force rebuild.
- [ ] Add repository identity metadata.
  - Root path, file count, file size totals, build timestamp, and source revision when available.
- [ ] Add per-file metadata records.
  - Stable file id, relative path, size, mtime, hash strategy, and file type.
- [ ] Implement trigram extraction for text contents.
  - Define normalization rules, minimum query length behavior, and binary-file exclusions.
- [ ] Implement a file-id dictionary.
  - Keep postings compact by storing integer ids instead of repeated paths.
- [ ] Implement the trigram postings writer.
  - Sort postings, deduplicate file ids, and keep build memory under control.
- [ ] Choose a storage format for postings.
  - Start simple, then measure whether packed binary or varint encoding is needed.
- [ ] Add a literal-query planner that can use the trigram index.
  - Break the literal into trigrams, intersect candidate file sets, then verify exact matches.
- [ ] Add a short-query fallback path.
  - Queries shorter than 3 bytes should bypass the trigram index and use the current scanner.
- [ ] Add case-insensitive indexed search semantics.
  - Decide whether to index normalized lowercased trigrams separately or normalize at query time.
- [ ] Add regex planning for indexed mode.
  - Extract required literal seeds from regexes and use trigrams only as a candidate reducer.
- [ ] Detect regexes that cannot benefit from the index.
  - If no safe literal seed exists, fall back to the current scan pipeline.
- [ ] Add candidate verification after indexed filtering.
  - The index must never be treated as proof of a match, only as a prefilter.
- [ ] Add walker/ignore integration to the index builder.
  - Respect `.gitignore`, `.phgrepignore`, hidden-file rules, type filters, and glob rules consistently.
- [ ] Decide how explicit file inputs interact with the index.
  - Direct file targets should bypass index surprises and still honor current CLI semantics.
- [ ] Add incremental rebuild support.
  - Detect changed, added, removed, and renamed files and update postings without full rebuild.
- [ ] Add a cheap staleness check before indexed queries.
  - Detect when the on-disk index is out of date and choose rebuild, refresh, or fallback behavior.
- [ ] Add a cold-build benchmark category.
  - Measure full index creation cost on WordPress and synthetic corpora.
- [ ] Add a warm-query benchmark category.
  - Measure repeated literal and regex queries using the index with no rebuild.
- [ ] Add branch-vs-main CI benchmarks for indexed mode.
  - Compare cold build, warm literal, warm regex, and fallback queries.
- [ ] Add correctness tests for indexed text search.
  - Indexed results must match current non-indexed results byte-for-byte after normalization.
- [ ] Add oracle scenarios for indexed mode.
  - Reuse the existing scenario corpus and compare indexed and non-indexed outputs directly.
- [ ] Add corruption handling.
  - Broken index files must fail cleanly and never silently return incomplete results.
- [ ] Add locking and atomic index swaps.
  - Prevent partially-written index state from being used during queries.
- [ ] Add CLI surface for index maintenance.
  - Build, refresh, inspect, and remove commands with clear exit codes.
- [ ] Add index statistics output.
  - Number of indexed files, postings count, size on disk, and last refresh time.
- [ ] Document where indexed mode should and should not be used.
  - One-shot grep-like searches should still default to the current non-indexed path.
- [ ] Consider a future daemon or watcher only after the static index path proves its value.
  - Do not add always-on complexity before warm-query wins are confirmed on CI.

### AST Search

- [x] Memoize fingerprints during one match attempt so repeated captures do not reserialize the same subtree.
- [ ] Short-circuit repeated-capture equality when the candidate node/value is the same instance as the previously captured value.
- [ ] Fast-path pure variadic array captures so patterns like `array($$$ITEMS)` do not pay generic backtracking costs.
- [ ] Add cheaper root checks for literal scalar subnodes that are common in benchmark patterns.
- [ ] Add token-aware AST prefiltering for long-array vs short-array syntax before parsing.
- [ ] Add token-aware AST prefiltering for zero-argument `new` expressions before parsing.
- [ ] Delay `AstMatch::code` materialization until output actually needs it.
- [ ] Measure parser cost separately from matcher cost with an internal AST count-only benchmark path.
- [ ] Re-run the full AST suite after every 2 accepted AST matcher wins.

### Text Search

- [ ] Keep fixed-string work focused on literal and case-insensitive paths until they flatten on CI.
- [ ] Add anchored-regex fast paths where a regex reduces to prefix, suffix, or full-line literal checks.
- [ ] Detect regexes that collapse to exact literal matches and route them to literal search.
- [ ] Revisit `BufferedReader` and text chunk handling to reduce string copying for regex-heavy scans.
- [ ] Benchmark larger read buffers on WordPress for regex mode only.
- [ ] Re-check text-mode JSON and output formatting cost after search-path wins land.

### Parallel

- [ ] Revisit parallel only after text and AST scan costs stabilize.
- [ ] Add category-specific worker thresholds instead of sharing one cutoff across text, AST, and rewrite.
- [ ] Add a heuristic to avoid forking when the workload is dominated by a single large file.
- [ ] Consider scalar-only worker payloads for AST-only benchmark categories if result materialization becomes a bottleneck.
- [ ] Only pursue persistent workers if scan-path wins stop moving the needle.

### Walker And I/O

- [ ] Measure whether walker ordering and file-list sorting still costs enough to justify optimization.
- [ ] Check whether extension/type checks can be cached more aggressively without breaking parity.
- [ ] Revisit file read strategy for large PHP files after regex and AST work settles.

### Benchmark Infrastructure

- [ ] Keep the interleaved workflow as the benchmark standard.
- [ ] Add a small helper script to download a benchmark run and print the markdown comparison quickly by run id.
- [ ] Add a note or table here for every accepted win so the queue stays audit-friendly.
- [ ] Consider adding CI threshold annotations for obvious regressions once the main categories stabilize.

## Immediate Next Steps

1. Keep the current WordPress full-suite CI run as the fresh baseline for the indexed-mode branch.
2. Design the index layout and CLI contract before writing any storage code.
3. Implement the smallest useful slice first: literal search backed by a trigram candidate index.
4. Benchmark cold build cost and warm literal query speed on CI before expanding to regex support.
