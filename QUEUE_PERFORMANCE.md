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

Current benchmark baseline commit: `6057d44`

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

### Accepted Benchmark Infrastructure

- `b94070d` `Interleave CI benchmark comparisons`
  - Head/base runs are interleaved instead of measured in two large blocks
  - Same-commit self-compare now stays near runner noise instead of inventing fake regressions

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

### In Flight

- AST token-aware long-array prefilter
  - scan PHP tokens for long `array(...)` syntax before the full AST parse runs
  - only apply this prefilter when the pattern root is long-form `array(...)`
  - validate on CI against `6057d44` with the `ast` category before keeping

## Ordered Queue

### AST Search

- [ ] Memoize fingerprints during one match attempt so repeated captures do not reserialize the same subtree.
- [ ] Short-circuit repeated-capture equality when the candidate node/value is the same instance as the previously captured value.
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

1. Finish the AST token-aware long-array prefilter pass and benchmark it against `6057d44`.
2. If the AST pass wins, stay in AST pruning and prefilter work until that surface flattens.
3. If the AST pass is flat or negative, pivot back to text-path work or parser-cost isolation.
4. Keep every pass isolated and CI-verified.
