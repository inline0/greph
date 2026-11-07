---
title: "Indexed Text Search"
description: "Warmed trigram and identifier postings index for repeated text-search workloads."
path: "indexed/text"
order: 60
section: "Indexed Modes"
meta_title: "Indexed Text Search"
meta_description: "Warmed trigram and identifier postings index for repeated text-search workloads."
---

# Indexed Text Search

Indexed text search is the warmed-cache version of [text search](/docs/modes/text). `greph-index build` writes an on-disk store of trigrams and identifier postings for a directory tree; later searches read that store to skip files that cannot contain the pattern, then verify the remaining candidates with the same engine that plain text mode uses. The result is much lower latency for repeated queries on large repositories without a daemon or watcher process.

Greph has three plain modes that read the source tree on every call: [text search](/docs/modes/text), [AST search](/docs/modes/ast) and [AST rewrite](/docs/modes/rewrite). This page covers the warmed counterpart of text search. The structural counterparts are the [AST fact index](/docs/indexed/ast) and the [cached AST store](/docs/indexed/cache). Rewrites always run against the live source tree, so there is no indexed rewrite mode.

The warmed text surface includes:

- single-index search
- repeated `--index-dir` multi-index search
- manifest-backed set search
- lifecycle-aware stale handling
- a per-index query cache for repeated identical queries
- planner diagnostics via `--trace-plan`

The index is exposed under the `greph-index` CLI and the `Greph::buildTextIndex()` / `Greph::searchTextIndexed()` facade methods.

## Indexed or plain text search

Both modes return the same `file:line:content` output and the same `TextFileResult` objects. The difference is where the time goes.

Plain text search walks the tree, applies ignore rules and reads every remaining file on every call. Its cost grows with the size of the tree, and it is always current: it sees every edit the moment it is saved.

Indexed search pays the read cost once at build time. Every lifecycle except `static` still walks the tree and compares file sizes and mtimes before each query, but reads no file contents for that check. A query then loads the file list from the index, looks up postings for the pattern, and reads only the candidate files. Its cost grows with the number of files that could match, and it is only as current as its last build or refresh.

Indexed mode beats the plain mode when all of these hold:

- **The same tree is searched many times.** Agent loops, editor integrations, CI jobs that run several searches and long-lived tools on a stable checkout all qualify. A one-off search pays the build cost for nothing.
- **The pattern has something to prefilter on.** A fixed string of at least three bytes, a regex with a literal run of at least three bytes, or a fixed-string whole-word identifier lets the index rule out most files. Patterns without one fall back to scanning every indexed file, which is no faster than plain mode.
- **The pattern is selective.** The fewer files contain the literal, the more the index saves. `new Logger` touches a handful of files in a large project; `<?php` touches all of them.
- **The question is a summary.** Counts (`-c`), file lists (`-l`, `-L`) and existence checks (`-q`) can be answered with less per-file work, and some whole-word summaries without opening files at all (see [Direct summaries](#direct-summaries)).
- **The tree changes slowly or predictably.** A vendored framework, WordPress core or a dependency tree can use a `static` index that never pays for a freshness check. A working copy you edit constantly needs a lifecycle that refreshes or rejects stale data.

Stay with [plain text search](/docs/modes/text) when:

- The tree is small. Building and loading an index costs more than scanning a few hundred files.
- You search a path once and will not query it again.
- You need inverted matches (`-v`). Indexed mode scans every selected file for them, so it only adds the cost of loading the index.
- You need results that reflect unsaved or very recent edits and do not want to manage refreshes.
- The pattern is very short (`-F "wp"`) or is a regex built from classes and wildcards with no literal run.

### Break-even from the published numbers

The [README benchmarks](https://github.com/inline0/greph#performance) on the WordPress corpus give a rough break-even point. Building the trigram index took 12,306.51 ms. A plain literal search for `function` took 458.82 ms and the indexed one 90.50 ms, so the build is repaid after about 34 searches. For the `new` instance regex the plain search took 459.58 ms and the indexed one 10.46 ms, which repays the build after about 28 searches.

These indexed rows repeat the same query after one warmup run without clearing the query cache, so they measure repeated identical queries. A first query with a new pattern pays for the postings lookup and candidate verification; the `indexed-text-cold` suite in `benchmarks/suites/` clears the query cache before each run to measure that case. See [Benchmarks](/docs/advanced/benchmarks) for how the numbers are produced.

On these rows the warmed indexed mode is ahead of both `rg` and `grep` on regex queries and on count, files-with-matches and files-without-matches queries. On plain literal queries it beats `grep` but stays slightly behind `rg`.

## Building the index

```bash
# Full build at the current directory
./vendor/bin/greph-index build .

# Full build at an explicit root
./vendor/bin/greph-index build path/to/repo

# Use a non-default index directory
./vendor/bin/greph-index build . --index-dir /tmp/greph-index

# Build with an explicit lifecycle policy
./vendor/bin/greph-index build . \
  --lifecycle opportunistic-refresh \
  --auto-refresh-max-files 32 \
  --auto-refresh-max-bytes 1048576
```

The index is stored at `<root>/.greph-index/` by default. The build walks the root with the same [file walker](/docs/advanced/walker) as plain text search. It skips binary files, files larger than 10 MiB, the `.git` directory and Greph's own index directories, but it records hidden and ignored files with a flag instead of dropping them. Ignore and hidden-file rules are applied at query time, so `--hidden` and `--no-ignore` work against the same index.

A successful build prints a one-line summary:

```text
Built index for 3 files in .greph-index (71 trigrams, 3.16ms, +3 ~0 -0 =0)
```

The fields are the file count, the index directory, the number of distinct trigrams, the build time, and the added, updated, deleted and unchanged file counts. On a fresh build the added counter equals the file count and everything else is zero.

## Refreshing the index

```bash
./vendor/bin/greph-index refresh .
```

`refresh` re-walks the indexed root and compares each file's size and modification time with the stored values. Files whose size and mtime are unchanged keep their stored postings; added and changed files are read again and deleted files are dropped. The output uses the same counters so you can see exactly what moved:

```text
Refreshed index for 4 files in .greph-index (75 trigrams, 5.56ms, +1 ~1 -0 =2)
```

Run `refresh` after editing tracked files when you are using `manual-refresh`, the default lifecycle. For `opportunistic-refresh` indexes, Greph refreshes the index automatically during search if the changed set is still cheap enough.

## Lifecycle profiles

Greph stays daemon-free. Lifecycle profiles control how a warmed index behaves when the source tree changes:

- `static`: never freshness-check or mutate automatically
- `manual-refresh` (default): check freshness for `stats`, but search the stored index as-is
- `opportunistic-refresh`: refresh during search only when the changed set is below the configured thresholds
- `strict-stale-check`: reject stale warmed searches instead of refreshing

Every profile except `static` re-walks the tree and compares sizes and mtimes before each search. That check reads no file contents, but on a large tree it is a noticeable part of a warm query, which is why `static` is the fastest choice for code you do not edit.

The `opportunistic-refresh` thresholds default to 32 changed files and 1,048,576 changed bytes (`--auto-refresh-max-files`, `--auto-refresh-max-bytes`). Added, updated and deleted files all count toward the file limit, and their sizes toward the byte limit. When either limit is exceeded, the search uses the stored index as-is, like `manual-refresh`.

A stale index can miss matches. Files added after the last build or refresh are not in the file list, so indexed search does not read them. A file edited to contain the pattern is still filtered by its old postings and is skipped. A file edited to remove the pattern is re-read during verification, so stale data never produces a match that is not in the file. If you cannot afford missed matches, use `strict-stale-check`, refresh before searching, or use plain text search.

For example, with a `manual-refresh` index built over `src/a.php`, `src/b.php` and `src/c.php`, adding `src/d.php` with `new Logger()` and editing `src/c.php` to contain `new Baz()` leaves the indexed search unchanged:

```bash
./vendor/bin/greph-index search -F "new" .
```

```text
src/b.php:2:$x = new Bar();
src/a.php:2:function alpha() { return new Foo(); }
```

Plain `greph -F "new" .` finds all four matches at this point. Use `greph-index stats --dry-refresh` to inspect what warmed search would do:

```bash
./vendor/bin/greph-index stats . --dry-refresh
```

```text
Text index stats
Root: .
Index: .greph-index
Files: 3
Trigram postings: 71
Word postings: 10
Disk size: 5683 bytes
Query caches: 1 (830 bytes)
Lifecycle: manual-refresh
Stale: yes
Changes: +1 ~1 -0 =2 (stale)
Last refresh: 2026-11-07 09:02:30 UTC
Last build time: 3.16ms
Search behavior: use stale index until explicit refresh
```

After `greph-index refresh .` the same search returns all four matches.

## Querying the index

```bash
# Fixed-string search
./vendor/bin/greph-index search -F "function" .

# Case-insensitive whole-word
./vendor/bin/greph-index search -F -i -w "function" .

# Counts
./vendor/bin/greph-index search -F -c "function" .

# Files only
./vendor/bin/greph-index search -F -l "function" .

# Multi-index search
./vendor/bin/greph-index search -F "apply_filters" . \
  --index-dir wordpress/.greph-index \
  --index-dir wp-content/plugins/my-plugin/.greph-index \
  --show-index-origin

# Regex search
./vendor/bin/greph-index search "function\s+\w+" .

# JSON output
./vendor/bin/greph-index search --json -F "function" .

# Planner trace to stderr
./vendor/bin/greph-index search --trace-plan -F "function" .
```

The flag set is the same as native text search. See the [CLI reference](/docs/cli#greph-index) for the full list.

The path argument selects which indexed files to search. A directory under the index root narrows the search to that subtree. Paths outside the index root, and explicitly named files that the index does not hold, are searched directly with the plain engine and merged into the result.

If the index does not exist at the requested location, `greph-index search` exits with an error instead of scanning. There is no automatic fallback to a non-indexed scan in this command (the AST commands have an opt-in `--fallback scan`, but the text command does not). Build the index first.

## Named index sets

If you repeatedly search the same warmed layout, use a manifest instead of repeating `--index-dir` flags:

```json
{
  "name": "wordpress-local",
  "indexes": [
    {
      "name": "core-text",
      "root": "wordpress",
      "mode": "text",
      "lifecycle": "static",
      "priority": 100
    },
    {
      "name": "plugin-text",
      "root": "wp-content/plugins/my-plugin",
      "mode": "text",
      "lifecycle": "opportunistic-refresh",
      "max_changed_files": 16,
      "max_changed_bytes": 262144,
      "priority": 200
    }
  ]
}
```

Save it as `.greph-index-set.json`, then:

```bash
./vendor/bin/greph-index set build
./vendor/bin/greph-index set stats --dry-refresh
./vendor/bin/greph-index set search --show-index-origin -F "apply_filters" .
```

This layout matches the advice above: WordPress core rarely changes, so it uses `static` and never pays for a freshness check, while the plugin you are editing refreshes itself when a search finds a small set of changes.

## What the index stores

The text index is a trigram + identifier postings store. For every indexed file Greph extracts:

- **Trigram set**: every distinct three-byte sequence in the lowercased file content. Used as a coarse pre-filter for both literal and regex queries.
- **Word set**: every distinct lowercased run of `[A-Za-z0-9_]` characters. Used to accelerate whole-word queries.
- **File metadata**: path, size and mtime for refresh decisions and stale inspection.
- **Lifecycle metadata**: the profile and its refresh thresholds.

Both sets are inverted into postings lists that map each trigram or word to the files that contain it. Because the postings are case-folded, one index serves case-sensitive and case-insensitive queries. Except for the whole-word summaries described under [Direct summaries](#direct-summaries), the verification step applies the exact case rules.

This is the same idea as ripgrep's optional preindexing and codesearch's trigram index, but built specifically around the on-disk shapes that warmed PHP can hit fastest.

## How a query is planned

When a query arrives, Greph selects the indexed files under the requested paths, applies the query's file filters, and chooses one candidate source:

| Candidate source | When | Files read |
|---|---|---|
| `word-postings` | `-F -w` with a pattern made only of `[A-Za-z0-9_]` | Files whose word set contains the lowercased pattern |
| `trigram-postings` | A fixed string of at least three bytes, or a regex with literal runs of at least three bytes | Files that contain every trigram of the selected literals |
| `invert-scan` | `-v` | Every selected file |
| `full-scan` | No usable literal (for example `-F "wp"` or `[a-z]+\(`) | Every selected file |

For regex patterns Greph extracts the literal runs between metacharacters, keeps those of at least three bytes and uses up to the three longest. The trigram postings of those literals are intersected, smallest list first, and the search stops early when the intersection is empty. Candidate files are then searched with the plain text engine, so the matches, line numbers and context lines are identical to [plain text search](/docs/modes/text).

### Query cache

Indexed search also stores complete results for repeated identical queries in `<index>/queries/`. A cached result is written when the search path is the index root itself and is reused for the same pattern and options when the search path is the root or a directory under it. Entries written for an earlier build or refresh are ignored.

The query cache is skipped for inverted matches, context lines (`-A`, `-B`, `-C`), a per-file match limit (`-m`), explicitly named files, paths outside the index root and any walker override: hidden files, disabled ignore rules, followed symlinks, binary files, type and glob filters, or a non-default maximum file size.

### Direct summaries

Some summary queries skip the full text engine:

- A fixed-string search with `-c`, `-l`, `-L` or `-q` (and no `-w`, `-v` or context) reads each candidate file once and checks or counts matching lines with `strpos()` instead of building match objects.
- Whole-word fixed-string `-l`, `-L` and `-q` are answered directly from the case-folded word postings without reopening the candidate files.

## Planner diagnostics

`--trace-plan` emits the warmed text planner decision to `stderr` as JSON while leaving the normal result stream untouched.

```bash
./vendor/bin/greph-index search --trace-plan -F "new" .
```

```json
{
    "mode": "text",
    "index_path": "/path/to/repo/.greph-index",
    "root_path": "/path/to/repo",
    "lifecycle": "manual-refresh",
    "selection": {
        "directories": 1,
        "files": 0,
        "selected_files": 3,
        "fallback_paths": 0
    },
    "cache": {
        "query_cache_eligible": true,
        "query_cache_populate": true
    },
    "plan": {
        "direct_summary": false,
        "direct_word_summary": false,
        "candidate_source": "trigram-postings",
        "whole_word_token": null,
        "seeds": [
            "new"
        ],
        "postings_term_count": 1,
        "candidate_file_count": 2,
        "verified_file_count": 2,
        "selected_file_count": 3,
        "explicit_selection_count": 0
    }
}
```

The fields to read first are `candidate_source` and the ratio of `candidate_file_count` to `selected_file_count`. When the source is `full-scan` or the two counts are close, the index is not saving work for that pattern and [plain text search](/docs/modes/text) is the simpler choice. `seeds` shows which literals the planner extracted from the pattern, and `fallback_paths` counts paths that were searched without the index.

## Programmatic use

```php
use Greph\Greph;
use Greph\Text\TextSearchOptions;
use Greph\Index\IndexLifecycleProfile;

// Build once
Greph::buildTextIndex('.', lifecycle: IndexLifecycleProfile::OpportunisticRefresh);

// Refresh after edits
Greph::refreshTextIndex('.');

// Query
$results = Greph::searchTextIndexed(
    'function',
    'src',
    new TextSearchOptions(fixedString: true),
);
```

`searchTextIndexed` accepts the same `TextSearchOptions` as native text search and returns the same `list<TextFileResult>` shape, so code written against `Greph::searchText()` can switch between the two modes by changing one call. For multi-index or manifest-backed flows, use `searchTextIndexedMany(...)` and `searchTextIndexedSet(...)`. The full signatures are in the [API reference](/docs/api#indexed-text-search).
