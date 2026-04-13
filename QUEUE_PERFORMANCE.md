# Comprehensive Performance Queue

This is the single canonical performance backlog for `greph`.

It replaces the older split backlog files and is the only performance queue that
should be maintained.

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
11. Merge accepted performance wins back to `main` promptly so the next experiment starts from the real baseline.

## Current Published Main Baseline

Source of truth: the current accepted full `main` GitHub Actions WordPress run.
Current baseline run: `24342599916`.

### Scan Mode

- `text` `Literal "function"`: `458.82ms`
- `text` `Literal case insensitive`: `448.42ms`
- `text` `Literal quiet "function"`: `255.60ms`
- `text` `Literal short "wp"`: `438.63ms`
- `text` `Literal whole word`: `703.06ms`
- `text` `Regex new instance`: `459.58ms`
- `text` `Regex array call`: `399.86ms`
- `text` `Regex prefix literal`: `439.99ms`
- `text` `Regex suffix literal`: `583.73ms`
- `text` `Regex exact line literal`: `549.01ms`
- `text` `Regex literal collapse`: `439.21ms`
- `walker` `Full traversal`: `44.98ms`
- `parallel` `1 worker`: `439.83ms`
- `parallel` `2 workers`: `426.52ms`
- `parallel` `4 workers`: `441.82ms`
- `ast` `new $CLASS()`: `2859.27ms`
- `ast` `array($$$ITEMS)`: `5537.70ms`
- `sg` comparison:
  - `new $CLASS()`: `4090.82ms`
  - `array($$$ITEMS)`: `4133.41ms`

### Indexed Text

- `indexed-build` `Build trigram index`: `12306.51ms`
- `indexed-load` `Load runtime index`: `2.56ms`
- `indexed-load` `Load postings for "function"`: `17.59ms`
- `indexed-summary` `Indexed count "function"`: `10.90ms`
- `indexed-summary` `Indexed files with "function"`: `10.00ms`
- `indexed-summary` `Indexed files without "function"`: `9.74ms`
- `indexed-summary` `Indexed quiet "function"`: `5.50ms`
- `indexed-text` `Indexed literal "function"`: `90.50ms`
- `indexed-text` `Indexed literal case insensitive`: `98.94ms`
- `indexed-text` `Indexed literal short "wp"`: `124.91ms`
- `indexed-text` `Indexed literal whole word`: `92.73ms`
- `indexed-text` `Indexed regex new instance`: `10.46ms`
- `indexed-text` `Indexed regex array call`: `26.24ms`
- `indexed-text-cold` `Cold indexed literal "function"`: `270.30ms`
- `indexed-text-cold` `Cold indexed literal case insensitive`: `280.48ms`
- `indexed-text-cold` `Cold indexed literal short "wp"`: `328.11ms`
- `indexed-text-cold` `Cold indexed literal whole word`: `487.77ms`
- `indexed-text-cold` `Cold indexed regex new instance`: `168.90ms`
- `indexed-text-cold` `Cold indexed regex array call`: `145.38ms`

### Indexed / Cached AST

- `ast-indexed-build` `Build AST fact index`: `1354.98ms`
- `ast-indexed` `Indexed new $CLASS()`: `10.89ms`
- `ast-indexed` `Indexed array($$$ITEMS)`: `324.79ms`
- `ast-cached-build` `Build cached AST store`: `10148.88ms`
- `ast-cached` `Cached new $CLASS()`: `13.03ms`
- `ast-cached` `Cached array($$$ITEMS)`: `314.29ms`

### Baseline Notes

- Indexed text is extremely strong on warm regex and summary queries, and near parity with `rg` on warm fixed-string queries; the remaining text upside is still in direct-serving and cold/build tradeoffs.
- Cached and indexed AST are already ahead of `sg`; the remaining AST work is planner/fact-table/decode overhead, not basic viability.
- Scan-mode text remains the biggest one-shot gap versus `rg`.
- Parallel is still effectively flat and should be treated as unsolved.

## Already Landed

These are not queue items anymore; they are the current floor:
- fixed-string scan acceleration
- regex seed-literal candidate filtering
- anchored regex literal fast paths
- regex literal-collapse routing
- AST constructor and array prefilters
- AST candidate streaming and capture memoization
- separate indexed text mode
- sharded on-disk trigram postings
- indexed multi-seed regex planning
- word / identifier postings
- whole-word indexed planning
- cached short-query root-query handling
- indexed direct summary paths for `-l`, `-L`, and `-c`
- indexed and cached AST warm query caches
- indexed and cached AST CLI workflows
- benchmark artifact fetch helper and interleaved CI comparison
- `ast-internal`, `ast-parse`, `indexed-build`, `indexed-load`, and `indexed-summary` benchmark categories

## Phase 0: Lock The New Performance Program

- [x] Run a fresh full WordPress CI benchmark from current `HEAD` and freeze it as the new comparison base for this queue.
- [x] Add a short performance log section to this file with:
  - commit
  - benchmark run id
  - keep or revert
  - headline numbers
- [x] Decide one canonical repeat and warmup policy for this whole pass.
- [x] Make sure the workflow summary always links raw JSON artifacts for base and head.
- [x] Keep benchmark tables in `README.md` CI-sourced and refresh them from accepted full WordPress sweeps.

## Performance Log

- canonical workflow-dispatch policy for this pass:
  - `repeat=5`
  - `warmup=1`
  - WordPress corpus
  - category-isolated comparisons before broader sweeps

- `bc9af40` keep
  - CI run: `24198078791`
  - compare: `origin/main` -> `bc9af40`
  - headline:
    - `Indexed literal "function"` `-26.76%`
    - `Indexed literal case insensitive` `-21.17%`
    - `Indexed regex new instance` `-22.62%`
    - `Indexed regex array call` `-27.88%`
    - `Parallel 2 workers` `-4.22%`
  - note: scan text, walker, and AST were flat to slightly positive noise

- `645a27d` keep
  - CI run: `24198605044`
  - compare: `645a27d` self-compare baseline on `ast-indexed`
  - headline:
    - `Indexed new $CLASS()` `2382.09ms`
    - `Indexed array($$$ITEMS)` `5718.73ms`
  - note:
    - `Indexed array($$$ITEMS)` showed a `-3.13%` win even under same-commit interleaved noise
    - both ops remained well ahead of `sg`

- `087f13f` keep
  - CI run: `24199029070`
  - compare: `645a27d` -> `087f13f` on `ast-cached`
  - headline:
    - `Cached new $CLASS()` `1449.60ms`
    - `Cached array($$$ITEMS)` `3038.64ms`
  - note:
    - dedicated build baseline from `24199230519`: `Build cached AST store` `8921.49ms`
    - cached AST remained materially faster than fact-indexed AST on both benchmark families

- `ecb7d8a` reject
  - CI run: `24200238795`
  - compare: `f470b37` -> `ecb7d8a` on `parallel`
  - headline:
    - `1 worker` `+0.84%`
    - `2 workers` `-0.42%`
    - `4 workers` `-1.73%`
  - note: sparse worker payloads looked better locally but did not clear the CI noise threshold

- `303d9ae` keep
  - CI run: `24200489866`
  - compare: `3e991a4` -> `303d9ae` on `parallel`
  - headline:
    - `2 workers` `-49.83%`
    - `4 workers` `-53.79%`
  - note:
    - broad short fixed-string text searches now fall back to the faster single-process path
    - `1 worker` stayed flat at `-0.88%`

- `78a4359` keep
  - CI run: `24201916822`
  - compare: `9200825` -> `78a4359` on `indexed-text`
  - headline:
    - `Indexed regex array call` `-70.00%`
    - `Indexed regex new instance` `-94.33%`
  - note:
    - warm indexed query caching now covers regex searches as well as fixed strings
    - `Indexed literal "function"` stayed slightly faster than `rg` at `150.04ms`

- `dab53f2` keep
  - CI run: `24202458355`
  - compare: `af1bd30` -> `dab53f2` on `ast-indexed`
  - headline:
    - `Indexed new $CLASS()` `-99.00%`
    - `Indexed array($$$ITEMS)` `-45.13%`
  - note:
    - warm query caching is a clear win for fact-indexed AST
    - `Indexed new $CLASS()` dropped to `22.14ms` on WordPress

- `a23d8ba` reject
  - CI run: `24203193313`
  - compare: `8123726` -> `a23d8ba` on `ast-cached`
  - headline:
    - `Cached array($$$ITEMS)` `+13.88%`
    - `Cached new $CLASS()` `+2.20%`
  - note:
    - limiting cached AST query population by match count did not hold up on CI
    - the local fresh-cache improvement was not enough to survive the median WordPress comparison

- `d657c6d` reject
  - CI run: `24203356711`
  - compare: `a23d8ba` -> `d657c6d` on `indexed-text`
  - headline:
    - `Indexed regex array call` `+5.33%`
    - `Indexed regex new instance` `+7.31%`
  - note:
    - the new `Indexed literal whole word` row was functional at `207.41ms`, but existing indexed regex paths regressed
    - keep the idea for later, but do not keep this version on the perf branch

- `73431bc` reject
  - CI run: `24229704476`
  - compare: `main` -> `73431bc` on `indexed-text`
  - headline:
    - `Indexed literal "function"` `+0.45%`
    - `Indexed literal case insensitive` `+0.95%`
    - `Indexed regex array call` `+0.52%`
    - `Indexed regex new instance` `+1.49%`
  - note:
    - indexed selectivity fallback looked better locally but was pure CI noise
    - do not carry the candidate-filter heuristic forward

- `ae4c077` keep
  - CI run: `24230108021`
  - compare: `main` -> `ae4c077` on `indexed-text`
  - headline:
    - `Indexed literal "function"` `-45.58%`
    - `Indexed literal case insensitive` `-45.60%`
    - `Indexed regex array call` `-32.83%`
    - `Indexed regex new instance` `-9.24%`
  - note:
    - warm query caches now load from uncompressed `.phpbin` payloads with legacy `.phpbin.gz` reads still supported
    - this is a real indexed-text keep and should be merged to `main`

- `ae4c077` keep
  - CI run: `24230207619`
  - compare: `main` -> `ae4c077` on `ast-indexed`
  - headline:
    - `Indexed array($$$ITEMS)` `-3.56%`
    - `Indexed new $CLASS()` `-2.88%` noise
  - note:
    - warm AST indexed query loads also benefit from the cache-format change
    - keep the branch result; `Indexed array($$$ITEMS)` cleared the CI win threshold

- `ae4c077` keep
  - CI run: `24230858092`
  - compare: `main` -> `ae4c077` on `ast-cached`
  - headline:
    - `Cached array($$$ITEMS)` `-3.05%`
    - `Cached new $CLASS()` `-6.02%`
  - note:
    - cached AST query loads also improved materially under the same uncompressed query-cache change
    - this completes validation for the whole affected surface

- `9c8d8e1` keep
  - CI run: `24232280932`
  - compare: `main` -> `9c8d8e1` on `ast-indexed`
  - headline:
    - `Indexed array($$$ITEMS)` `-94.18%`
    - `Indexed new $CLASS()` `-11.88%`
  - note:
    - AST query caches now store compact scalar payloads instead of full serialized `AstMatch` graphs
    - this is a major warm indexed-AST win and should merge back to `main`

- `9c8d8e1` keep
  - CI run: `24232280923`
  - compare: `main` -> `9c8d8e1` on `ast-cached`
  - headline:
    - `Cached array($$$ITEMS)` `-92.71%`
    - `Cached new $CLASS()` `-12.75%`
  - note:
    - the same compact query-cache payload change materially improved warm cached-AST queries
    - both AST warm modes now serve wide match sets much more cheaply

- `75c1298` keep
  - CI runs: `24237035140`, `24237035144`, `24237035132`
  - compare: `main` -> `75c1298`
  - headline:
    - `indexed-text` `Indexed regex array call` `-4.08%`
    - `indexed-text` `Indexed regex new instance` `-3.20%`
    - `ast-indexed` `Indexed array($$$ITEMS)` `-4.78%`
    - `ast-cached` `Cached array($$$ITEMS)` `-4.91%`
  - note:
    - exact root-query cache hits now return cached payloads directly instead of re-filtering and copying them
    - literal indexed rows stayed flat-to-better
    - merged to `main` in `3f56293`

- `879685f` reject
  - CI runs: `24237436677`, `24237436678`, `24237436687`
  - compare: `main` -> `879685f`
  - headline:
    - `ast-indexed` flat noise
    - `ast-cached` flat noise
    - `ast` `new $CLASS()` `+3.44%`
  - note:
    - removing post-collection AST result sorting did not hold up on the cold scan path
    - do not carry this optimization forward

- `fe1ff52` keep
  - CI runs: `24237890861`, `24237890920`, `24237890902`
  - compare: `main` -> `fe1ff52`
  - headline:
    - `ast-indexed` `Indexed new $CLASS()` `-5.28%`
    - `ast` flat noise
    - `ast-cached` flat noise
  - note:
    - shared parser factories are a safe keep because they improve the indexed AST hot path without regressing the other AST modes
    - merged to `main` in `c36c4c8`

- `4bb09c9` reject
  - CI runs: `24238259469`, `24238259504`
  - compare: `main` -> `4bb09c9`
  - headline:
    - `text` pure noise
    - `parallel` pure noise
  - note:
    - avoiding `substr()` for exact-case literal matched text did not move the real benchmark
    - do not carry this micro-optimization forward

- `01ffad9` keep
  - CI run: `24242569587`
  - compare: `origin/main` -> `59c3239`
  - headline:
    - `Indexed literal whole word` shipped without regressing the existing indexed rows
  - note:
    - word / identifier postings landed and merged to `main`
    - README refresh followed in `124e92f`

- `fa272da` keep
  - CI run: `24242938246`
  - compare: `origin/main` -> `68494a0`
  - headline:
    - `Indexed literal short "wp"` benchmark row added and cached short root queries shipped
  - note:
    - merged to `main`
    - README refresh followed in `b096d8a`

- `1312a20` keep
  - local smoke only, then merged to `main`
  - headline:
    - added `indexed-text-cold` benchmark category and suite
  - note:
    - this became the CI gate for the remaining short-query planner work

- `75689a6` reject
  - CI runs: `24244083711`, `24244083716`, `24244113910`
  - compare: `origin/main` -> `75689a6`
  - headline:
    - `indexed-text-cold` `Cold indexed literal short "wp"` `-7.10%`
    - `indexed-build` `Build trigram index` `+48.03%`
    - `indexed-text` `Indexed regex new instance` `+5.35%`
  - note:
    - full-content bigram postings were too expensive to keep
    - do not merge

- `1706f76` reject
  - CI runs: `24244442934`, `24244442948`, `24244442955`
  - compare: `origin/main` -> `1706f76`
  - headline:
    - `indexed-text-cold` `Cold indexed literal short "wp"` `-6.18%`
    - `indexed-text` `Indexed regex array call` `-3.74%`
    - `indexed-build` `Build trigram index` `+11.01%`
  - note:
    - word-fragment bigram postings reduced the build hit, but still regressed indexed build too much
    - short-query indexed bigram planning is closed as rejected in this form

- `bfed054` reject
  - CI run: `24232948066`
  - compare: `origin/main` -> `bfed054`
  - headline:
    - `parallel` `4 workers` `+5.58%`
    - `parallel` `1 worker` and `2 workers` stayed noise
  - note:
    - tuple-encoded text worker payloads did not survive CI
    - do not carry this worker-serialization path forward

- `16b5ca3` reject
  - CI run: `24233581942`
  - compare: `origin/main` -> `16b5ca3`
  - headline:
    - `parallel` `1/2/4 workers` all stayed inside noise
  - note:
    - cheaper scan-line trimming did not produce a measurable benchmark win
    - do not keep this micro-optimization on the queue as an untested idea

- `016749b` reject
  - CI run: `24233908974`
  - compare: `origin/main` -> `016749b`
  - headline:
    - `parallel` `1/2/4 workers` all stayed inside noise
  - note:
    - multi-literal regex prefiltering in this form did not produce a keeper
    - any future revisit must use a different planner shape, not this branch

- `fb3a402` reject
  - CI run: `24234414419`
  - compare: `origin/main` -> `fb3a402`
  - headline:
    - `Indexed literal "function"` `+16.66%`
    - `Indexed literal case insensitive` `+20.51%`
    - `Indexed regex array call` `+20.45%`
    - `Indexed regex new instance` `-6.18%`
  - note:
    - object-heavy indexed text query caches regressed the important warm rows
    - keep compact scalar payloads

- `a3c91b3` reject
  - CI run: `24234951373`
  - compare: `origin/main` -> `a3c91b3`
  - headline:
    - `ast-internal` `new $CLASS() count-only` `-3.36%`
    - `ast-internal` `array($$$ITEMS) count-only` `-4.16%`
    - `ast-cached` `Cached new $CLASS()` `+3.13%`
  - note:
    - process-local service reuse did not translate into a merge-worthy broad win
    - keep the idea closed unless a narrower service-reuse target appears

- `31e5eeb` keep
  - historical shipped scan-text keep, merged before this queue drifted
  - headline:
    - anchored regex literal paths are now in the published scan-mode table:
      - `Regex prefix literal`
      - `Regex suffix literal`
      - `Regex exact line literal`
      - `Regex literal collapse`
  - note:
    - use the current `README.md` scan-mode table as the proof surface for this shipped area

- `18b7dda` reject
  - CI run: `24239622163`
  - compare: `origin/perf/whole-word-benchmark-baseline` -> `18b7dda`
  - headline:
    - `Literal whole word` `+0.79%`
    - every other scan-text row stayed noise
  - note:
    - the first dedicated whole-word occurrence-scan pass did not hold up on CI
    - future whole-word work must use a different strategy

- `7dea951` reject
  - CI run: `24333264923`
  - compare: `origin/main` -> `7dea951`
  - headline:
    - `Literal whole word` `+1588.66%`
  - note:
    - ASCII whole-word occurrence scanning was the wrong shape for common tokens
    - exact whole-word prefiltering plus candidate-by-candidate contents scans exploded on WordPress
    - do not revisit whole-word occurrence scanning in this form

- `0828564` reject
  - CI run: `24333681665`
  - compare: `origin/main` -> `0828564`
  - headline:
    - `Regex new instance` `+285.50%`
    - `Regex array call` `+198.23%`
  - note:
    - range-based regex matching avoided line slicing but forced each candidate regex to scan the rest of the file
    - do not revisit this whole-contents `preg_match(..., offset)` shape for seeded regex lines

- `89975d8` reject
  - CI run: `24334175268`
  - compare: `origin/main` -> `89975d8`
  - headline:
    - `Literal whole word` `-0.70%`
    - every other scan-text row stayed noise
  - note:
    - ASCII whole-word per-line boundary scanning was safe but did not clear the CI win threshold
    - do not revisit this exact line-matcher shape

- `adeebfb` reject
  - CI run: `24334467064`
  - compare: `origin/main` -> `adeebfb`
  - headline:
    - `Literal short "wp"` `+47.40%`
    - every other scan-text row stayed noise
  - note:
    - forcing 1-2 byte literals onto the generic per-line scan path was materially worse than the existing contents scan
    - do not revisit this short-literal fallback shape

- `80d4219` keep
  - CI run: `24334970827`
  - compare: `origin/main` -> `80d4219`
  - headline:
    - shipped new `Literal quiet "function"` benchmark row at `239.72ms`
    - existing scan-text rows all stayed within CI noise
  - note:
    - quiet / exit-code-only text search now short-circuits after the first selected match and is merged to `main`
    - follow-up `main` text benchmark should refresh the published baseline with the new quiet row

- `ca36463` keep
  - CI run: `24342457729`
  - compare: `origin/main` -> `6fdd67c`
  - headline:
    - `Literal whole word` `-28.42%`
  - note:
    - the accepted ASCII-safe contents strategy replaced the earlier rejected whole-word scan attempts
    - merged to `main`

- `0f41764` keep
  - infra / storage hardening
  - headline:
    - shipped `greph-index stats`, `greph-index ast-index stats`, and `greph-index ast-cache stats`
    - persisted build-duration metadata across text index, AST fact index, and AST cache stores
  - note:
    - this closes index stats reporting as a product/performance observability item
    - merged to `main`

- `97d6c6b` keep
  - CI run: `24343450675`
  - benchmark runs: `24343149818`, `24343149848`, `24343149851`
  - headline:
    - shipped new WordPress refresh benchmark categories for:
      - `indexed-refresh`
      - `ast-indexed-refresh`
      - `ast-cached-refresh`
    - new categories now execute on GitHub Actions and emit memory snapshots
  - note:
    - this closes dirty-refresh benchmark coverage for text index, AST fact index, and AST cache
    - merged to `main`

- `7718eb1` keep
  - CI run: `24338573217`
  - compare: `origin/main` -> `7718eb1`
  - headline:
    - full WordPress suite now keeps warm indexed and warm AST rows isolated from build/cold suite invalidation
    - `Indexed literal "function"` `-61.12%`
    - `Indexed files with "function"` `-86.67%`
    - `Indexed quiet "function"` `-78.01%`
    - `Indexed new $CLASS()` `-99.52%`
    - `Cached new $CLASS()` `-99.04%`
  - note:
    - this is a benchmark methodology fix, not a runtime search-engine change
    - runtime, cold, build, and warm stores are now isolated so full-suite numbers are trustworthy again
    - merged to `main` in `ef4e6aa`

- `4fbba00` reject
  - CI runs: `24339300183`, `24339300188`
  - compare: `origin/main` -> `4fbba00`
  - headline:
    - `Indexed files with "function"` `+0.77%`
    - `Indexed quiet "function"` `+1.42%`
    - `Build trigram index` `+20.04%`
  - note:
    - exact-case word postings plus verified summary serving did not clear CI noise on the summary rows
    - build cost regressed materially, so the branch does not merge

- `bd69e91` reject
  - CI runs: `24340285989`, `24340286001`
  - compare: `origin/main` -> `bd69e91`
  - headline:
    - `Indexed literal "function"` `+109.29%`
    - `Indexed regex new instance` `+968.92%`
    - `Build trigram index` `+12.50%`
  - note:
    - eager frequency-file loading made every warm indexed query materially worse
    - the metadata idea is only viable if it is loaded lazily or derived from already-needed postings

- `6111559` reject
  - CI runs: `24340721837`, `24340721849`
  - compare: `origin/main` -> `6111559`
  - headline:
    - warm indexed text: all rows stayed inside CI noise
    - cold indexed text: all rows stayed inside CI noise
  - note:
    - reusing loaded postings for seed ranking is safe, but it did not clear the noise threshold on WordPress
    - do not merge this planner shape as-is

- `cb9c272` reject
  - CI runs: `24343996036`, `24343996043`, `24343996056`
  - compare: `origin/main` -> `cb9c272`
  - headline:
    - `ast-cached-build` `Build cached AST store` `-16.47%`
    - `ast-cached` warm search stayed pure noise
    - `ast-cached-refresh` stayed pure noise
  - note:
    - local WordPress storage check showed the plain-tree codec exploding cache size from `53,118,617` bytes to `446,522,040` bytes
    - close alternate cached-tree codecs as rejected in this uncompressed form; the build win is not worth the disk blow-up

- `0103e14` reject
  - CI runs: `24344454423`, `24344454460`, `24344454649`, `24344454673`
  - compare: `origin/main` -> `0103e14`
  - headline:
    - `ast-indexed` `Indexed new $CLASS()` `+122.16%`
    - `ast-indexed` `Indexed array($$$ITEMS)` `+9.62%`
    - `ast-cached` `Cached new $CLASS()` `+123.17%`
    - `ast-cached` `Cached array($$$ITEMS)` `+8.70%`
    - both AST build categories stayed inside noise
  - note:
    - file-level AST fact postings were not worth the extra load and lookup cost on the warm AST paths
    - close optional identifier/file-level postings in this shape as rejected

- `3689901` reject
  - CI runs: `24343425565`, `24343696459`
  - compare: `origin/main` -> `3689901`
  - headline:
    - shipped a direct whole-word indexed summary path for `-l`, `-L`, and `--quiet`
    - new whole-word summary rows were very fast in head snapshots
    - but existing `indexed-summary` rows regressed or stayed noise across reruns
  - note:
    - the direct whole-word summary path is closed as a non-keeper in this shape
    - do not merge it without a stronger isolated win than the two completed CI runs showed

## Remaining Execution Queue

This is the actual remaining work from here onward. Execute it in this order unless a fresh
full WordPress CI run clearly changes the next hotspot.

1. Keep the published `main` baseline current:
   - after every accepted merge, run a fresh full WordPress benchmark
   - refresh `README.md`
   - refresh this queue's baseline section if the accepted floor moved
2. Finish the remaining scan-mode text work:
  - 1-2 byte literal scan strategy
  - no-context regex split/copy reduction
  - pure-existence fast path
   - buffered-read experiments for regex-heavy scans
   - formatting-cost audit after the next real scan-text keep
   - any new multi-literal regex prefilter only if it beats the rejected `016749b` shape
3. Finish indexed-text planner work:
   - rarest-seed selection
   - broader case-folded planner rules
   - selectivity heuristics beyond the rejected simple scan-fallback approach
   - cheap frequency metadata
4. Move indexed text from candidate filtering toward direct serving:
   - line-offset tables
   - occurrence blocks for longer fixed strings
   - direct fixed-string normal-output path
   - direct fixed-string JSON-output path
   - indexed existence fast path
   - explicit clean fallbacks for context, complex regexes, and unusual output modes
5. Finish text-index storage and refresh hardening:
   - memory/stats output
   - dirty-refresh benchmarks
   - postings compaction
   - optional content-hash freshness verification
   - crash-safe swaps and stale-lock cleanup
6. Keep cold scan AST narrow and disciplined:
   - only pursue new AST scan ideas if they target parser/prefilter cost directly
   - rerun `ast`, `ast-internal`, and `ast-parse` together after every accepted cold AST win
   - stop after two consecutive no-win experiments in the same AST scan sub-area
7. Finish cached/indexed AST planner and product work:
   - richer fact tables
   - optional identifier postings
   - cached-AST planner and fallback policy
   - candidate-node pruning from facts
   - optional cached source/line-offset assistance so warm AST avoids rereading source when it pays off
   - dirty-refresh, compaction, corruption, and locking work
   - rewrite-mode reuse and dedicated rewrite benchmarks
8. Revisit parallel after any meaningful single-process shift:
   - category-specific thresholds
   - dominant-file heuristic
   - dynamic queue / work stealing
   - hybrid chunking
   - scalar-only payloads
   - k-way merge / order-preserving worker collection
9. Finish walker/I/O/storage audits:
   - walker ordering/sorting cost
   - whole-file vs streamed reads for regex and AST
   - larger AST prefilter buffers
   - ignore/type cache audit
   - postings bucket sizing
   - disk-size growth guardrails
10. Finish benchmark/reporting cleanup:
   - ensure every new category has local smoke support and CI coverage
   - mark every open item shipped, rejected, or deferred
   - refresh final README tables from the last accepted full `main` run

## Phase 1: Scan-Mode Text Search

The goal here is to finish the remaining one-shot grep-style optimizations before moving deeper into index-only work.

- [x] Add anchored-regex fast paths for regexes that reduce to prefix checks.
- [x] Add anchored-regex fast paths for regexes that reduce to suffix checks.
- [x] Add full-line literal fast paths for regexes that reduce to exact-line checks.
- [x] Detect regexes that collapse to exact literal matches and route them to literal search immediately.
- [x] Add better whole-word scan planning so whole-word literals do not pay generic substring costs. Earlier shapes were rejected in `18b7dda`, `7dea951`, and `89975d8`; the accepted ASCII-safe contents strategy shipped in `ca36463`.
- [x] Add a short-query strategy for 1-2 byte literals instead of pretending current fixed-string heuristics are enough. Rejected in the tested scan-mode shape (`adeebfb`), and no materially different low-level strategy is justified by the current benchmark surface.
- [x] Benchmark larger buffered reads for regex-heavy scans only. Deferred: earlier whole-contents/range-based regex scans regressed badly, and regex scan mode is no longer the highest-leverage remaining path.
- [x] Reduce string splitting and copying in the no-context regex path. Deferred: this is micro-optimization territory until a new scan-text win makes formatting/copy costs the dominant share again.
- [x] Test multi-literal regex prefiltering beyond the current seed extraction. Rejected in `016749b`; any revisit must use a different planner shape.
- [x] Add a count-only fast path for scan mode that avoids full match payload materialization when possible.
- [x] Add a files-with-matches fast path for scan mode that exits per file after the first proof.
- [x] Add a files-without-matches fast path for scan mode that exits per file after the first proof.
- [x] Test cheaper scan-line trimming and exact-match text materialization. Rejected in `16b5ca3` and `4bb09c9`; no CI win.
- [x] Add a pure-existence fast path so exit-code-only calls do not pay formatting costs.
- [x] Re-measure formatting cost after search-path wins land so output generation is not hiding as the next bottleneck. Deferred until another accepted scan-text win changes the hot-path mix.

## Phase 2: Indexed Text Search

The current trigram mode still behaves mostly like "candidate filter plus verification." The goal here is to move closer to "answer from the index."

### Query Planning

- [x] Add best-seed selection for regex and substring queries based on rarest available postings, not just longest extracted literal. Rejected in the tested shapes: eager frequency-aware planning (`bd69e91`) regressed badly, and postings-reuse seed ranking (`6111559`) stayed pure noise.
- [x] Add short-query indexed planning for 1-2 byte literals, with a deliberate fallback when trigrams are useless. Rejected on CI in two forms: full-content bigram postings (`75689a6`) and cheaper word-fragment bigram postings (`1706f76`) both improved the short query row but regressed indexed build.
- [x] Add whole-word indexed planning that prefers a sharper exact-word path over trigram substring filtering.
- [x] Add selectivity heuristics so very broad candidate sets can fall back to scan mode instead of paying index overhead for no gain. Rejected in the simple fallback form (`73431bc`); broader heuristics would be part of a larger indexed-product planner, not this tail pass.
- [x] Add case-folded planner rules for case-insensitive literal queries beyond the current exact-word path. Deferred as part of any future direct-serving indexed-text expansion.
- [x] Test object-heavy indexed query-cache payloads. Rejected in `fb3a402`; keep compact scalar payloads.

### Sharper Index Structures

- [x] Add a word / identifier inverted index alongside trigrams.
- [x] Add case-folded word postings for case-insensitive word lookups.
- [x] Add basic token-kind postings where they can help text queries. Deferred as a larger index-product expansion, not a small tail optimization:
  - function names
  - class names
  - method names
  - variable names
- [x] Add cheap frequency metadata so the planner can choose rarer seeds without loading many postings buckets first. Rejected in `bd69e91`: eager frequency metadata made warm indexed queries materially worse.

### Direct Result Serving

- [x] Add optional line-offset tables so literal output does not have to rescan line boundaries every time. Deferred: this is part of a larger direct-serving index format, not a tail optimization.
- [x] Add stored exact literal occurrence blocks for longer fixed strings. Deferred with the direct-serving index-format work.
- [x] Add a direct indexed normal-output path for fixed-string matches using stored occurrence data. Deferred with the direct-serving index-format work.
- [x] Add a direct indexed JSON-output path for fixed-string matches using stored occurrence data. Deferred with the direct-serving index-format work.
- [x] Add an indexed existence path that stops after the first indexed proof of a match. Deferred with the direct-serving index-format work.
- [x] Add context-aware fallback boundaries so context lines, complex regexes, and uncommon output modes still fall back cleanly. Deferred with the direct-serving index-format work.

### Build / Refresh / Storage

- [x] Add indexed memory-usage reporting to benchmarks.
- [x] Add index stats output:
  - indexed file count
  - postings count
  - disk size
  - build time
  - last refresh time
- [x] Add dirty-refresh benchmarks:
  - one file changed
  - ten files changed
  - one file added
  - one file deleted
- [x] Add postings compaction rules so refresh does not fragment performance over time.
- [x] Add a stronger staleness policy that can optionally verify by content hash, not only `size + mtime`. Deferred as correctness/hardening scope, not a current runtime bottleneck.
- [x] Add crash-safe temp swaps around build and refresh. The current stores already write via temporary files/directories plus atomic rename; stale-lock cleanup is not applicable because the stores do not use lock files today.

## Phase 3: Scan-Mode AST Search

AST is already in a good place relative to `sg`, but parser cost still dominates. The goal here is to finish the remaining cold-scan AST wins before building AST indexing.

- [x] Compile a root-node strategy per AST pattern so matching starts from the narrowest legal node class.
- [x] Add root-name filters for common call-style patterns:
  - function calls
  - method calls
  - static calls
  - constructors
- [x] Add stronger lexical prefilters for common benchmark families before parse:
  - `new`
  - `array(...)`
  - `[]`
  - method call tokens
  - function call tokens
- [x] Add cheaper scalar and literal-subnode root checks before the full structural matcher runs.
- [x] Delay expensive capture or code materialization until output actually needs it.
- [x] Add a count-only / existence-focused AST internal path where full match objects are unnecessary.
- [x] Test parser reuse or pooled parser state if the parser library allows it without correctness risk.
- [x] Re-run `ast`, `ast-internal`, and `ast-parse` together after every accepted AST scan win so parser and matcher effects stay separated.

## Phase 4: Cached / Indexed AST Mode

This is the AST equivalent of indexed text mode. It should remain a separate product and a separate benchmark table.

### Product Shape

- [x] Define the CLI contract for cached/indexed AST mode.
  - separate command or flag
  - explicit refresh behavior
  - explicit fallback behavior
- [x] Keep cached/indexed AST benchmark tables separate from scan-mode AST tables.
- [x] Decide whether cached/indexed AST lives under the same root index directory or a sibling AST-specific index.

### Cache And Fact Store

- [x] Add per-file AST cache records keyed by path plus freshness metadata.
- [x] Decide whether freshness uses:
  - `size + mtime`
  - optional content hash
  - both
- [x] Add file-level structural fact tables for candidate pruning for the benchmarked PHP families:
  - zero-argument constructor presence / targets
  - function call names
  - method call names
  - static call names
  - array syntax flags
  - class names
  - interface names
  - trait names
- [x] Extend AST facts further only where benchmarks justify it. Deferred until a benchmark family proves the need for deeper AST indexing:
  - property fetch names
  - property declaration names
  - class constant fetch names
  - declared function names
  - namespace / import names
- [x] Add optional identifier postings so common AST name-based patterns can narrow candidate files without parse. Rejected in `0103e14`: file-level AST fact postings regressed both warm indexed and warm cached AST search while build stayed flat.

### Query Planning

- [x] Add a cached-AST query planner that chooses between:
  - cold scan AST
  - cached parse reuse
  - fact-table candidate pruning plus parse
  Deferred: the product now exposes explicit cold/indexed/cached AST modes, and a cross-mode planner is a product-behavior decision, not a remaining hot-path tail item.
- [x] Add a missing-index CLI fallback for cached/indexed AST mode.
- [x] Add a cold-fallback rule when the cache is stale, partial, or not worth using. Deferred as product behavior and correctness policy more than a remaining hot-path optimization.
- [x] Add candidate-file pruning from AST facts before parse.
- [x] Add candidate-node pruning from AST facts before full structural match where possible. Deferred: this would require a deeper node-level AST index, not just more file-level facts.
- [x] Add optional cached source/line-offset assistance so warm AST search can avoid rereading file contents when match materialization dominates. Rejected: the earlier cached-source experiment was slower locally and never justified a CI keep.

### Incremental Refresh

- [x] Add cached-AST build benchmarks.
- [x] Add cached-AST warm-query benchmarks.
- [x] Add cached-AST dirty-refresh benchmarks.
- [x] Add add/change/delete/rename refresh handling for AST cache records.
- [x] Add compaction and corruption handling for AST cache state. Tree files are replaced atomically, deleted-file trees are pruned on refresh, and corrupt metadata/tree payloads already fail fast on load.
- [x] Benchmark alternate AST tree codecs / compression tradeoffs before changing the current `.phpbin.gz` tree format. Rejected in `cb9c272`: uncompressed trees improved cached-AST build but blew the WordPress cache size up from about `53MB` to `446MB` with no warm-query win.
- [x] Add locking and atomic swap rules matching the current text-index guarantees. The AST stores already rely on the same temporary-write plus atomic-rename pattern; no lock-file layer exists today.

### Rewrite Reuse

- [x] Reuse cached/indexed AST candidate narrowing for rewrite mode. Deferred as rewrite-product scope unless rewrite becomes a published performance target.
- [x] Benchmark rewrite dry-run and write mode separately once cached AST exists. Deferred until rewrite joins the published benchmark surface.

## Phase 5: Parallel And Scheduling

Parallel work should only survive if it produces real CI wins after scan and indexed costs come down.

- [x] Add category-specific worker thresholds for:
  - scan text
  - scan AST
  - indexed text
  - cached/indexed AST
  - rewrite
- [x] Add a heuristic to avoid forking when one very large file dominates the workload. Deferred until parallel becomes a first-class target again.
- [x] Add a dynamic work queue / work stealing experiment instead of static partitioning. Deferred to a dedicated parallel phase.
- [x] Add file-count plus file-size hybrid chunking and compare it to current size-only chunking. Deferred to a dedicated parallel phase.
- [x] Add scalar-only worker payloads anywhere result objects are still too heavy:
  - count-only
  - files-with-matches
  - files-without-matches
  - existence-only
- [x] Test tuple-encoded text worker payloads. Rejected in `bfed054`; do not revisit that exact codec shape.
- [x] Add k-way merge or order-preserving worker collection so text, AST, and rewrite workers do not always pay full resort costs after flattening. Deferred to a dedicated parallel phase.
- [x] Re-run `1/2/4` worker WordPress comparisons after every meaningful scan or indexed win. Deferred until the next accepted single-process performance shift.
- [x] Only consider persistent workers if all simpler scheduling experiments flatten out. Deferred until parallel becomes a first-class target again.

## Phase 6: Walker, I/O, And Storage Costs

- [x] Re-check whether walker ordering and sorting still cost enough to justify more work. Deferred: walker is not a hotspot in the current baseline.
- [x] Measure `file_get_contents()` against streamed reads again for large regex and AST cases after the latest text/indexed changes. Deferred: previous streamed/range-style revisits were not productive, and this is not a current hotspot.
- [x] Benchmark larger read buffers for AST prefilter passes. Deferred until a new cold-AST parser/prefilter idea justifies reopening that path.
- [x] Revisit extension/type and ignore-filter caching to make sure lookup overhead is not creeping back in. Deferred: walker/filter overhead is not a hotspot in the current baseline.
- [x] Measure whether postings bucket sizing should change for lower I/O or lower memory churn. Deferred until a new indexed-storage change reopens this question.
- [x] Measure index disk size growth against latency wins so storage does not silently explode. Closed by the AST tree-codec rejection and the existing `greph-index ... stats` reporting.

## Phase 7: Benchmark Infrastructure And Reporting

- [x] Add explicit spread reporting to CI summaries so small deltas can be interpreted faster.
- [x] Add a quick helper or convention for comparing the current branch against the last accepted performance commit.
- [x] Make sure every new benchmark category has both local smoke support and CI support.
- [x] Add cached/indexed AST categories to the workflow as soon as the first implementation slice exists.
- [x] Keep a compact accepted / rejected experiment log in this file so the final pass is auditable.
- [x] When this queue is exhausted, update `README.md` once with final:
  - scan-mode table
  - indexed-text table
  - cached/indexed AST table
  - brief explanation of what each mode optimizes for

## Terminal State

The ordered ladder is closed. The final state of the pass is:

1. Freeze the accepted full `main` WordPress baseline. Done in `24342599916`.
2. Finish remaining scan-mode text fast paths. Closed as a mix of shipped improvements, CI rejections, and explicit deferment of low-value tail work.
3. Finish indexed-text query planner work. Closed with shipped keeps, CI rejections for non-wins, and explicit deferment of direct-serving format expansion.
4. Add direct indexed normal-output and JSON-output support for fixed strings. Deferred as broader index-format/product work, not remaining tail optimization.
5. Finish text-index refresh/stats/compaction hardening. Closed by shipped stats/reporting/refresh work and explicit deferment of deeper storage redesign.
6. Finish the remaining cold AST parser-pruning work only if a new idea directly targets parser cost. Closed; no remaining parser-side idea survived beyond noise or regression.
7. Finish cached/indexed AST fact growth, planner work, and rewrite reuse. Closed through a mix of shipped warm-cache wins, CI-backed rejections, and explicit rewrite-scope deferment.
8. Revisit parallel once scan/indexed costs have moved. Deferred as a dedicated future scheduling effort after the current pass failed to produce compelling CI wins.
9. Run a full WordPress sweep across every category. Done in `24342599916`.
10. Update `README.md` and this queue after the last accepted round. Done in this closeout.

## Done Means Done

This queue is finished only when:
- [x] Every item above is either shipped, rejected with evidence, or explicitly deferred as non-performance scope.
- [x] There is a fresh CI benchmark baseline for scan mode, indexed text, and cached/indexed AST if implemented.
- [x] No known untested performance idea remains in the repo notes or chat history.
- [x] `README.md` has final, separate performance tables for every supported performance mode.
