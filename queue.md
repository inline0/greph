# Comprehensive Performance Queue

This file supersedes the older split queues. It is the final performance program for
`phgrep`.

The goal is not "maybe faster." The goal is:
- exhaust every credible remaining performance idea
- keep scan mode, indexed text mode, and cached/indexed AST mode separate
- accept or reject each idea with benchmark evidence
- stop only when nothing meaningful is left untested

## Completion Rule

This queue is only depleted when every item below is in one of these states:
- shipped because CI proved the win
- rejected because CI proved no win or a regression
- deferred explicitly because it is product scope, not performance work

Do not leave vague "maybe later" items behind.

## Benchmark Rules

1. GitHub Actions `Benchmark` on the WordPress corpus is the source of truth.
2. Use the interleaved base/head workflow only.
3. Run `composer verify` before every benchmark push.
4. Benchmark one isolated performance change per commit.
5. Do not push performance regressions to `main`.
6. Treat roughly `0%` to `3%` movement as runner noise unless multiple related ops move together.
7. Keep benchmark tables separate for:
   - scan text / walker / parallel / AST
   - indexed text
   - cached/indexed AST
8. After every 2 to 4 accepted wins in one area, run a broader WordPress sweep again.
9. Use local benchmarks only for smoke checks and direction, never as the final gate.
10. Record accepted and rejected experiments with commit and CI run id.

## Current Baseline

### Scan Mode Baseline

Source of truth: GitHub Actions run `24194937014`

WordPress medians:
- `text` `Literal "function"`: `446.43ms`
- `text` `Literal case insensitive`: `455.47ms`
- `text` `Regex new instance`: `459.13ms`
- `text` `Regex array call`: `404.85ms`
- `walker` `Full traversal`: `45.92ms`
- `parallel` `1 worker`: `444.00ms`
- `parallel` `2 workers`: `1054.53ms`
- `parallel` `4 workers`: `1137.28ms`
- `ast` `new $CLASS()`: `3091.08ms`
- `ast` `array($$$ITEMS)`: `6078.42ms`
- `sg` comparison:
  - `new $CLASS()`: `8519.37ms`
  - `array($$$ITEMS)`: `8651.25ms`

### Indexed Text Baseline

Source of truth: GitHub Actions run `24194937014`

WordPress medians:
- `indexed-build` `Build trigram index`: `10122.60ms`
- `indexed-text` `Indexed literal "function"`: `261.85ms`
- `indexed-text` `Indexed literal case insensitive`: `259.93ms`
- `indexed-text` `Indexed regex new instance`: `223.73ms`
- `indexed-text` `Indexed regex array call`: `187.97ms`

### Current Directional Local Snapshot

These numbers are useful for direction only and are not the acceptance gate.

- `indexed-load` `Load runtime index`: `2.06ms`
- `indexed-load` `Load postings for "function"`: `15.67ms`
- `indexed-summary` `Count "function"`: `504.12ms`
- `indexed-summary` `Files with "function"`: `77.25ms`
- `indexed-summary` `Files without "function"`: `82.32ms`
- `indexed-text` `Literal "function"`: `504.58ms`
- `indexed-text` `Literal case insensitive`: `280.30ms`
- `indexed-text` `Regex new instance`: `218.57ms`
- `indexed-text` `Regex array call`: `244.24ms`

### Baseline Notes

- Scan-mode AST is already ahead of `sg` on the current WordPress cases.
- Indexed text is useful, but broad literal queries still spend too much time reopening files.
- Indexed `-l` and `-L` already look strong locally; normal output is the bigger remaining indexed-text gap.
- Parallel is still not a meaningful win on WordPress and should not be treated as solved.

## Already Landed

These are not queue items anymore; they are the current floor:
- fixed-string scan acceleration
- regex seed-literal candidate filtering
- AST constructor and array prefilters
- AST candidate streaming and capture memoization
- separate indexed text mode
- sharded on-disk trigram postings
- indexed multi-seed regex planning
- indexed direct summary paths for `-l`, `-L`, and `-c`
- benchmark artifact fetch helper and interleaved CI comparison
- `ast-internal`, `ast-parse`, `indexed-build`, `indexed-load`, and `indexed-summary` benchmark categories

## Phase 0: Lock The New Performance Program

- [ ] Run a fresh full WordPress CI benchmark from current `HEAD` and freeze it as the new comparison base for this queue.
- [ ] Add a short performance log section to this file with:
  - commit
  - benchmark run id
  - keep or revert
  - headline numbers
- [ ] Decide one canonical repeat and warmup policy for this whole pass.
- [ ] Make sure the workflow summary always links raw JSON artifacts for base and head.
- [ ] Keep `README.md` unchanged until the last pass is finished, then update it once with final tables.

## Phase 1: Scan-Mode Text Search

The goal here is to finish the remaining one-shot grep-style optimizations before moving deeper into index-only work.

- [ ] Add anchored-regex fast paths for regexes that reduce to prefix checks.
- [ ] Add anchored-regex fast paths for regexes that reduce to suffix checks.
- [ ] Add full-line literal fast paths for regexes that reduce to exact-line checks.
- [ ] Detect regexes that collapse to exact literal matches and route them to literal search immediately.
- [ ] Add better whole-word scan planning so whole-word literals do not pay generic substring costs.
- [ ] Add a short-query strategy for 1-2 byte literals instead of pretending current fixed-string heuristics are enough.
- [ ] Benchmark larger buffered reads for regex-heavy scans only.
- [ ] Reduce string splitting and copying in the no-context regex path.
- [ ] Add a count-only fast path for scan mode that avoids full match payload materialization when possible.
- [ ] Add a files-with-matches fast path for scan mode that exits per file after the first proof.
- [ ] Add a files-without-matches fast path for scan mode that exits per file after the first proof.
- [ ] Add a pure-existence fast path so exit-code-only calls do not pay formatting costs.
- [ ] Re-measure formatting cost after search-path wins land so output generation is not hiding as the next bottleneck.

## Phase 2: Indexed Text Search

The current trigram mode still behaves mostly like "candidate filter plus verification." The goal here is to move closer to "answer from the index."

### Query Planning

- [ ] Add best-seed selection for regex and substring queries based on rarest available postings, not just longest extracted literal.
- [ ] Add short-query indexed planning for 1-2 byte literals, with a deliberate fallback when trigrams are useless.
- [ ] Add whole-word indexed planning that prefers a sharper exact-word path over trigram substring filtering.
- [ ] Add selectivity heuristics so very broad candidate sets can fall back to scan mode instead of paying index overhead for no gain.
- [ ] Add case-folded planner rules for case-insensitive literal queries.

### Sharper Index Structures

- [ ] Add a word / identifier inverted index alongside trigrams.
- [ ] Add case-folded word postings for case-insensitive word lookups.
- [ ] Add basic token-kind postings where they can help text queries:
  - function names
  - class names
  - method names
  - variable names
- [ ] Add cheap frequency metadata so the planner can choose rarer seeds without loading many postings buckets first.

### Direct Result Serving

- [ ] Add optional line-offset tables so literal output does not have to rescan line boundaries every time.
- [ ] Add stored exact literal occurrence blocks for longer fixed strings.
- [ ] Add a direct indexed normal-output path for fixed-string matches using stored occurrence data.
- [ ] Add a direct indexed JSON-output path for fixed-string matches using stored occurrence data.
- [ ] Add an indexed existence path that stops after the first indexed proof of a match.
- [ ] Add context-aware fallback boundaries so context lines, complex regexes, and uncommon output modes still fall back cleanly.

### Build / Refresh / Storage

- [ ] Add indexed memory-usage reporting to benchmarks.
- [ ] Add index stats output:
  - indexed file count
  - postings count
  - disk size
  - build time
  - last refresh time
- [ ] Add dirty-refresh benchmarks:
  - one file changed
  - ten files changed
  - one file added
  - one file deleted
- [ ] Add postings compaction rules so refresh does not fragment performance over time.
- [ ] Add a stronger staleness policy that can optionally verify by content hash, not only `size + mtime`.
- [ ] Add crash-safe temp swaps and stale-lock cleanup benchmarks/tests around build and refresh.

## Phase 3: Scan-Mode AST Search

AST is already in a good place relative to `sg`, but parser cost still dominates. The goal here is to finish the remaining cold-scan AST wins before building AST indexing.

- [ ] Compile a root-node strategy per AST pattern so matching starts from the narrowest legal node class.
- [ ] Add root-name filters for common call-style patterns:
  - function calls
  - method calls
  - static calls
  - constructors
- [ ] Add stronger lexical prefilters for common benchmark families before parse:
  - `new`
  - `array(...)`
  - `[]`
  - method call tokens
  - function call tokens
- [ ] Add cheaper scalar and literal-subnode root checks before the full structural matcher runs.
- [ ] Delay expensive capture or code materialization until output actually needs it.
- [ ] Add a count-only / existence-focused AST internal path where full match objects are unnecessary.
- [ ] Test parser reuse or pooled parser state if the parser library allows it without correctness risk.
- [ ] Re-run `ast`, `ast-internal`, and `ast-parse` together after every accepted AST scan win so parser and matcher effects stay separated.

## Phase 4: Cached / Indexed AST Mode

This is the AST equivalent of indexed text mode. It should remain a separate product and a separate benchmark table.

### Product Shape

- [ ] Define the CLI contract for cached/indexed AST mode.
  - separate command or flag
  - explicit refresh behavior
  - explicit fallback behavior
- [ ] Keep cached/indexed AST benchmark tables separate from scan-mode AST tables.
- [ ] Decide whether cached/indexed AST lives under the same root index directory or a sibling AST-specific index.

### Cache And Fact Store

- [ ] Add per-file AST cache records keyed by path plus freshness metadata.
- [ ] Decide whether freshness uses:
  - `size + mtime`
  - optional content hash
  - both
- [ ] Add file-level structural fact tables for candidate pruning:
  - node kinds present
  - constructor calls
  - constructor arity buckets
  - function call names
  - method call names
  - static call names
  - array syntax flags
  - class names
  - interface names
  - trait names
- [ ] Add optional identifier postings so common AST name-based patterns can narrow candidate files without parse.

### Query Planning

- [ ] Add a cached-AST query planner that chooses between:
  - cold scan AST
  - cached parse reuse
  - fact-table candidate pruning plus parse
- [ ] Add a cold-fallback rule when the cache is stale or missing.
- [ ] Add candidate-file pruning from AST facts before parse.
- [ ] Add candidate-node pruning from AST facts before full structural match where possible.

### Incremental Refresh

- [ ] Add cached-AST build benchmarks.
- [ ] Add cached-AST warm-query benchmarks.
- [ ] Add cached-AST dirty-refresh benchmarks.
- [ ] Add add/change/delete/rename refresh handling for AST cache records.
- [ ] Add compaction and corruption handling for AST cache state.
- [ ] Add locking and atomic swap rules matching the text index guarantees.

### Rewrite Reuse

- [ ] Reuse cached/indexed AST candidate narrowing for rewrite mode.
- [ ] Benchmark rewrite dry-run and write mode separately once cached AST exists.

## Phase 5: Parallel And Scheduling

Parallel work should only survive if it produces real CI wins after scan and indexed costs come down.

- [ ] Add category-specific worker thresholds for:
  - scan text
  - scan AST
  - indexed text
  - cached/indexed AST
  - rewrite
- [ ] Add a heuristic to avoid forking when one very large file dominates the workload.
- [ ] Add a dynamic work queue / work stealing experiment instead of static partitioning.
- [ ] Add file-count plus file-size hybrid chunking and compare it to current size-only chunking.
- [ ] Add scalar-only worker payloads anywhere result objects are still too heavy:
  - count-only
  - files-with-matches
  - files-without-matches
  - existence-only
- [ ] Re-run `1/2/4` worker WordPress comparisons after every meaningful scan or indexed win.
- [ ] Only consider persistent workers if all simpler scheduling experiments flatten out.

## Phase 6: Walker, I/O, And Storage Costs

- [ ] Re-check whether walker ordering and sorting still cost enough to justify more work.
- [ ] Measure `file_get_contents()` against streamed reads again for large regex and AST cases after the latest text/indexed changes.
- [ ] Benchmark larger read buffers for AST prefilter passes.
- [ ] Revisit extension/type and ignore-filter caching to make sure lookup overhead is not creeping back in.
- [ ] Measure whether postings bucket sizing should change for lower I/O or lower memory churn.
- [ ] Measure index disk size growth against latency wins so storage does not silently explode.

## Phase 7: Benchmark Infrastructure And Reporting

- [ ] Add explicit spread reporting to CI summaries so small deltas can be interpreted faster.
- [ ] Add a quick helper or convention for comparing the current branch against the last accepted performance commit.
- [ ] Make sure every new benchmark category has both local smoke support and CI support.
- [ ] Add cached/indexed AST categories to the workflow as soon as the first implementation slice exists.
- [ ] Keep a compact accepted / rejected experiment log in this file so the final pass is auditable.
- [ ] When this queue is exhausted, update `README.md` once with final:
  - scan-mode table
  - indexed-text table
  - cached/indexed AST table
  - brief explanation of what each mode optimizes for

## Ordered Experiment Ladder

Work through these in order unless CI results make the next hotspot obvious:

1. Freeze the fresh WordPress baseline from current `HEAD`.
2. Finish remaining scan-mode text fast paths.
3. Finish indexed-text query planner work.
4. Add direct indexed normal-output support for fixed strings.
5. Add the word / identifier index.
6. Finish scan-mode AST parser-pruning work.
7. Build the first useful cached/indexed AST slice:
   - file facts
   - candidate-file pruning
   - warm-query benchmarks
8. Revisit parallel once scan/indexed costs have moved.
9. Run a full WordPress sweep across every category.
10. Update `README.md` only after the last accepted round.

## Done Means Done

This queue is finished only when:
- [ ] Every item above is either shipped, rejected with evidence, or explicitly deferred as non-performance scope.
- [ ] There is a fresh CI benchmark baseline for scan mode, indexed text, and cached/indexed AST if implemented.
- [ ] No known untested performance idea remains in the repo notes or chat history.
- [ ] `README.md` has final, separate performance tables for every supported performance mode.
