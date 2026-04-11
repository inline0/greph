# greph

Pure PHP code search and structural refactoring tool. Two modes: fast text search (like ripgrep) and AST-aware structural search with rewrite (like ast-grep). No extensions, no FFI, no shelling out. Oracle-tested against grep, ripgrep, and ast-grep.

## Quick Reference

```bash
# Testing (oracle-driven)
./bin/test-scenario <name>               # Single scenario: oracle → actual → compare
./bin/test-regression                    # All scenarios
./bin/test-regression --jobs 4           # Parallel
./bin/test-regression --category text    # By category (text, ast, rewrite)
./bin/test-regression --fast             # Pass/fail only, no reports
./bin/verify-compliance                  # Full compliance report

# Benchmarks
./bin/bench                              # Full benchmark suite
./bin/bench --category text              # Text search benchmarks only
./bin/bench --compare rg,grep            # Compare against external tools
./bin/bench --corpus wordpress           # Benchmark on WordPress codebase

# Oracle management
./bin/oracle <name>                      # Capture oracle output (grep/rg/sg)
./bin/oracle --refresh <name>            # Re-capture
./bin/actual <name>                      # Run greph, capture output
./bin/compare <name>                     # Diff oracle vs actual

# Unit tests
composer test:unit                       # Isolated component tests

# Code quality
composer cs                              # Check coding standards
composer cs:fix                          # Fix coding standards
composer analyse                         # PHPStan static analysis

# CLI usage
./bin/greph "pattern" path/             # Text search (grep mode)
./bin/greph -p '$x = new $Class()' src/ # AST search (structural mode)
./bin/greph -p '$OLD' -r '$NEW' src/    # AST rewrite
```

## What This Is

A single tool with two search modes:

**Text mode** (default): line-by-line regex/literal search. Same interface as grep. Optimized for speed via parallel scanning, literal prefix extraction, gitignore pruning, and large buffer reads.

**AST mode** (`-p` flag): structural code search using Abstract Syntax Trees. Patterns are written as ordinary code with `$VARIABLE` wildcards. Understands syntax, ignores formatting and comments. Supports rewrite (`-r`) for automated refactoring.

Both modes share the same CLI, the same file walker, the same output format. The difference is what happens when a file is opened: text mode scans bytes, AST mode parses and matches structure.

## What This Is Not

Not a linter (use PHPStan/Psalm). Not an IDE (use your editor). Not a formatter (use PHP-CS-Fixer). This is a search and refactor tool. It finds things and optionally rewrites them.

## Project Structure

```
greph/
├── src/
│   ├── Greph.php                       # Static facade (public API entry point)
│   │
│   ├── Text/
│   │   ├── TextSearcher.php             # Core text search engine
│   │   ├── LiteralSearcher.php          # Fast path: strpos-based literal search
│   │   ├── RegexSearcher.php            # PCRE2 JIT regex search
│   │   ├── LiteralExtractor.php         # Extract literal prefixes from regex patterns
│   │   ├── Match.php                    # Readonly: file, line number, column, content, captures
│   │   └── BufferedReader.php           # 64KB chunked file reader with line tracking
│   │
│   ├── Ast/
│   │   ├── AstSearcher.php              # Core AST search engine
│   │   ├── PatternParser.php            # Parse search pattern into AST pattern tree
│   │   ├── PatternMatcher.php           # Match pattern tree against source AST
│   │   ├── MetaVariable.php             # $VAR, $$$ARGS wildcard handling and capture
│   │   ├── AstRewriter.php              # Apply rewrite template with captured variables
│   │   ├── AstMatch.php                 # Readonly: file, node, captures, position
│   │   └── Parsers/
│   │       ├── ParserInterface.php      # Interface for language parsers
│   │       ├── PhpParser.php            # PHP parser (nikic/PHP-Parser)
│   │       ├── JsonParser.php           # JSON structure parser
│   │       └── ParserFactory.php        # Language detection and parser selection
│   │
│   ├── Walker/
│   │   ├── FileWalker.php               # Recursive directory traversal
│   │   ├── GitignoreFilter.php          # .gitignore / .grephignore rule parser and matcher
│   │   ├── BinaryDetector.php           # Magic bytes check (skip binary files)
│   │   ├── FileTypeFilter.php           # Extension-based type filtering (--type php)
│   │   └── FileList.php                 # Collected file list for distribution to workers
│   │
│   ├── Parallel/
│   │   ├── WorkerPool.php               # pcntl_fork-based parallel execution
│   │   ├── Worker.php                   # Single search worker (receives file list chunk)
│   │   ├── ResultCollector.php          # Aggregates results from workers via pipes
│   │   └── WorkSplitter.php            # Distribute files across workers (round-robin or size-based)
│   │
│   ├── Output/
│   │   ├── Formatter.php                # Interface for output formatting
│   │   ├── GrepFormatter.php            # grep-compatible output (file:line:content)
│   │   ├── JsonFormatter.php            # JSON output (--json)
│   │   ├── CountFormatter.php           # Match count only (-c)
│   │   ├── FilesOnlyFormatter.php       # File paths only (-l)
│   │   └── ColorFormatter.php           # ANSI color output (default terminal)
│   │
│   └── Exceptions/
│       ├── PatternException.php         # Invalid search pattern
│       ├── ParseException.php           # Unparseable source file
│       └── WalkerException.php          # Filesystem access error
│
├── bin/
│   ├── greph                           # CLI entry point
│   ├── oracle                           # Capture oracle output (grep/rg/ast-grep)
│   ├── actual                           # Run greph, capture output
│   ├── compare                          # Diff oracle vs actual
│   ├── test-scenario                    # Full pipeline: oracle → actual → compare
│   ├── test-regression                  # Run all scenarios
│   ├── verify-compliance                # Full compliance report
│   └── bench                            # Performance benchmark runner
│
├── tests/
│   ├── Unit/                            # Isolated component tests
│   └── Oracle/
│       ├── OracleCapture.php            # Runs grep/rg/sg, captures structured output
│       ├── ActualCapture.php            # Runs greph, captures same structure
│       ├── ScenarioComparator.php       # Diffs oracle vs actual output
│       ├── ScenarioRunner.php           # Orchestrates: setup → oracle → actual → compare
│       └── ScenarioRepository.php       # Discovers and loads scenarios from disk
│
├── scenarios/
│   ├── text/                            # Text search scenarios (oracle: grep + ripgrep)
│   │   ├── literal-simple/
│   │   ├── literal-case-insensitive/
│   │   ├── regex-basic/
│   │   ├── regex-character-class/
│   │   ├── regex-alternation/
│   │   ├── regex-quantifiers/
│   │   ├── regex-groups/
│   │   ├── regex-anchors/
│   │   ├── multiline/
│   │   ├── binary-skip/
│   │   ├── gitignore-respect/
│   │   ├── file-type-filter/
│   │   ├── count-only/
│   │   ├── files-only/
│   │   ├── invert-match/
│   │   ├── context-lines/
│   │   ├── max-count/
│   │   └── unicode/
│   │
│   ├── ast/                             # AST search scenarios (oracle: ast-grep)
│   │   ├── match-function-call/
│   │   ├── match-assignment/
│   │   ├── match-class-method/
│   │   ├── match-new-instance/
│   │   ├── match-array-access/
│   │   ├── match-if-condition/
│   │   ├── match-return-value/
│   │   ├── match-string-concat/
│   │   ├── meta-variable-single/
│   │   ├── meta-variable-multi/         # $$$ARGS matching
│   │   ├── meta-variable-repeated/      # Same $VAR must match same node
│   │   ├── ignore-whitespace/
│   │   ├── ignore-comments/
│   │   └── nested-match/
│   │
│   ├── rewrite/                         # AST rewrite scenarios (oracle: ast-grep)
│   │   ├── rename-function/
│   │   ├── swap-arguments/
│   │   ├── extract-variable/
│   │   ├── inline-variable/
│   │   ├── change-method-chain/
│   │   ├── add-argument/
│   │   ├── remove-argument/
│   │   └── replace-pattern/
│   │
│   └── edge/                            # Edge cases
│       ├── empty-file/
│       ├── huge-file/
│       ├── deeply-nested-dirs/
│       ├── symlinks/
│       ├── no-matches/
│       └── malformed-php/               # Syntax errors in source (must not crash)
│
├── benchmarks/
│   ├── corpora/
│   │   ├── wordpress/                   # WordPress core checkout
│   │   ├── laravel/                     # Laravel framework checkout
│   │   └── synthetic/                   # Generated files for controlled benchmarks
│   │       ├── generate-corpus.php      # Generate files with known characteristics
│   │       ├── 1k-files/
│   │       ├── 10k-files/
│   │       └── 100k-lines-single/       # Single huge file
│   │
│   ├── suites/
│   │   ├── text-literal.php             # Literal string search benchmarks
│   │   ├── text-regex.php               # Regex search benchmarks
│   │   ├── ast-search.php               # AST pattern search benchmarks
│   │   ├── ast-rewrite.php              # AST rewrite benchmarks
│   │   ├── walker.php                   # Directory traversal benchmarks
│   │   ├── parallel-scaling.php         # 1, 2, 4, 8 worker benchmarks
│   │   └── vs-external.php             # Compare against grep/rg/sg
│   │
│   ├── BenchmarkRunner.php              # Orchestrates benchmark suites
│   ├── BenchmarkResult.php              # Readonly: operation, duration, memory, files, matches
│   └── BenchmarkReport.php             # Renders results as markdown table
│
├── composer.json
├── phpunit.xml.dist
├── phpcs.xml
└── CLAUDE.md
```

## CLI Interface

### Text Search (grep-compatible)

The default mode. Matches grep's flags where possible.

```bash
greph "pattern" [path...]               # Search for regex pattern
greph -F "literal" [path...]            # Fixed string (no regex)
greph -i "pattern" [path...]            # Case insensitive
greph -w "word" [path...]               # Whole word match
greph -v "pattern" [path...]            # Invert match
greph -c "pattern" [path...]            # Count matches per file
greph -l "pattern" [path...]            # List matching files only
greph -L "pattern" [path...]            # List non-matching files
greph -n "pattern" [path...]            # Show line numbers (default)
greph -H "pattern" [path...]            # Show filename (default for multi-file)
greph -r "pattern" [path...]            # Recursive (default)
greph -A 3 "pattern" [path...]          # Show 3 lines after match
greph -B 3 "pattern" [path...]          # Show 3 lines before match
greph -C 3 "pattern" [path...]          # Show 3 lines context
greph -m 10 "pattern" [path...]         # Max 10 matches per file
greph --type php "pattern" [path...]    # Only search PHP files
greph --type-not js "pattern" [path...]  # Exclude JS files
greph --glob "*.php" "pattern" [path...] # Glob file filter
greph --json "pattern" [path...]        # JSON output
greph --no-ignore "pattern" [path...]   # Don't respect gitignore
greph --hidden "pattern" [path...]      # Search hidden files
greph -j 4 "pattern" [path...]         # Use 4 workers
```

### AST Search (ast-grep-compatible)

Activated by `-p` (pattern) flag. Patterns are written as ordinary PHP code with `$VARIABLE` meta-variables.

```bash
# Search for patterns
greph -p 'new $Class()' src/                          # Find all constructor calls
greph -p '$x = array()' src/                          # Find old-style array creation
greph -p 'isset($x) ? $x : $default' src/             # Find ternary isset patterns
greph -p '$obj->$method($$$ARGS)' src/                 # Any method call, any args
greph -p 'function $name($$$PARAMS): void {}' src/     # Void return functions

# Rewrite
greph -p 'array($$$ITEMS)' -r '[$$$ITEMS]' src/       # array() → []
greph -p 'isset($x) ? $x : $y' -r '$x ?? $y' src/    # isset ternary → null coalesce
greph -p '$a . $b' -r "\"{$a}{$b}\"" src/              # Concat → interpolation

# Options
greph -p 'pattern' --lang php src/                     # Explicit language
greph -p 'pattern' --json src/                         # JSON output
greph -p 'pattern' -r 'rewrite' --dry-run src/         # Preview changes
greph -p 'pattern' -r 'rewrite' --interactive src/     # Confirm each change
```

### Meta-Variable Syntax

| Syntax | Matches | Example |
|---|---|---|
| `$VAR` | Any single AST node | `$x + $y` matches `foo() + bar` |
| `$$$ARGS` | Zero or more nodes (variadic) | `func($$$ARGS)` matches `func()`, `func(1)`, `func(1, 2, 3)` |
| `$_` | Any single node (non-capturing) | `$_->method()` matches any receiver |
| `$VAR` repeated | Must match same structure | `$x == $x` matches `a == a`, not `a == b` |

## Configuration

| Constant / Flag | Default | Description |
|---|---|---|
| `GREPH_WORKERS` / `-j` | CPU count | Number of parallel workers |
| `GREPH_BUFFER_SIZE` | `65536` | Read buffer size in bytes |
| `GREPH_MAX_FILESIZE` | `10M` | Skip files larger than this |
| `GREPH_MAX_COLUMNS` | `500` | Truncate long lines in output |
| `GREPH_BINARY_CHECK_BYTES` | `512` | Bytes to check for binary detection |

## Key Rules

1. Pure PHP. No extensions beyond what ships with every PHP install. No FFI. No `exec()`. No shelling out to grep, ripgrep, or ast-grep at runtime. Those tools are oracles for testing, not runtime dependencies.
2. Three oracles, not one. Text search is tested against both `grep` and `ripgrep`. AST search is tested against `ast-grep --lang php`. All three are captured in oracle output. If greph disagrees with all oracles, greph is wrong. If oracles disagree with each other, investigate and document.
3. Text mode grep-compatible output. The default output format must match `grep -rn` exactly: `file:line:content`. This means existing scripts and tools that parse grep output work unchanged with greph.
4. AST mode uses nikic/PHP-Parser. This is the only Composer dependency. It is pure PHP, actively maintained, and handles PHP 7/8+ syntax completely. No tree-sitter, no C bindings. PHP files only for v1. Other languages are a future concern.
5. Performance is measured, not assumed. Every PR must not regress benchmarks. The benchmark suite runs against real corpora (WordPress, Laravel) and synthetic datasets. Results are tracked over time.
6. Parallel scanning via pcntl_fork. The file walker produces a file list, the work splitter distributes it across N workers, each worker searches independently, results are collected via pipes. If pcntl is unavailable (Windows, some hosting), fall back to single-process gracefully.
7. Literal prefix extraction is the single biggest text search optimization. Before applying PCRE2, extract literal substrings from the pattern and use `strpos()` (C-speed) to pre-filter. For fixed-string searches (`-F`), skip regex entirely.
8. Gitignore pruning is the single biggest I/O optimization. Parse `.gitignore`, `.grephignore`, and `$GIT_DIR/info/exclude`. Prune entire directory trees before reading any files. This typically eliminates 90%+ of filesystem I/O (node_modules, vendor, .git).
9. Large buffer reads, not line-by-line. Read files in 64KB chunks with `fread()`, find newlines in the buffer manually. This reduces syscall count dramatically compared to `fgets()` per line.
10. AST patterns are valid PHP. The pattern `$x = new $Class()` is parsed by PHP-Parser the same way source code is. Meta-variables (`$VAR`, `$$$ARGS`) are recognized by name convention after parsing. This means patterns get syntax checking for free.
11. AST rewrite is format-preserving. When replacing matched code, preserve surrounding whitespace, indentation, and comments. Use the original source positions from PHP-Parser to splice in the rewritten fragment.
12. Binary files are detected and skipped by default. Check the first 512 bytes for null bytes or non-text byte sequences. Override with `--binary` flag.
13. PHP 8.2+. Use readonly classes for `Match`, `AstMatch`, `BenchmarkResult`. Use enums for output modes. Use match expressions for flag dispatch.

## Oracle Model

Greph is verified end-to-end against the canonical search and refactoring tools it imitates.

**Three oracles, one tool:**

| Mode | Oracle | What it proves |
|---|---|---|
| Text search | `grep` | Output format compatibility, flag behavior, edge cases |
| Text search | `ripgrep` (rg) | Gitignore handling, file type filtering, performance baseline |
| AST search | `ast-grep` (sg) | Structural matching correctness, meta-variable semantics |

### Scenario Structure

```
scenarios/text/literal-simple/
├── scenario.json                 # Metadata: name, category, oracles, flags
├── setup/                        # Test corpus (source files to search through)
│   ├── main.php
│   ├── helpers.php
│   └── lib/
│       └── utils.php
├── oracle/
│   ├── grep.txt                  # grep output
│   ├── rg.txt                    # ripgrep output
│   └── rg.json                   # ripgrep JSON output (for structured comparison)
├── actual/
│   ├── greph.txt                # greph output
│   └── greph.json               # greph JSON output
└── reports/
    └── comparison.json           # Diff results per oracle
```

### scenario.json

```json
{
    "name": "literal-simple",
    "category": "text",
    "description": "Simple literal string search across PHP files",
    "pattern": "function",
    "flags": [],
    "path": "setup/",
    "oracles": {
        "grep": "grep -rn 'function' setup/",
        "rg": "rg -n 'function' setup/",
        "rg_json": "rg --json 'function' setup/"
    },
    "expectations": {
        "grep": "exact",
        "rg": "exact"
    }
}
```

### AST Scenario Structure

```
scenarios/ast/match-function-call/
├── scenario.json
├── setup/
│   └── source.php
├── oracle/
│   ├── sg.txt                    # ast-grep output
│   └── sg.json                   # ast-grep JSON output
├── actual/
│   ├── greph.txt
│   └── greph.json
└── reports/
    └── comparison.json
```

### Comparison Rules

| Oracle | Mode | Comparison |
|---|---|---|
| `grep` | Text output | Exact line-by-line match (file:line:content) |
| `rg` | Text output | Exact match (after normalizing color codes) |
| `rg` | JSON output | Semantic match (same matches, same positions, order may differ) |
| `ast-grep` | Text output | Semantic match (same matched code regions, formatting may differ) |
| `ast-grep` | JSON output | Semantic match (same node positions and captures) |

### When Oracles Disagree

Grep, ripgrep, and ast-grep sometimes produce different results. Document disagreements:

```json
{
    "oracle_disagreement": {
        "grep_vs_rg": "grep matches binary files by default, rg skips them",
        "greph_follows": "rg",
        "reason": "Binary file matching is rarely desired, rg's default is safer"
    }
}
```

## Benchmarks

Benchmarks are first-class. They run in CI and results are tracked over time.

### Benchmark Corpora

| Corpus | Size | Purpose |
|---|---|---|
| WordPress core | ~2,500 PHP files, ~1.5M lines | Real-world PHP codebase |
| Laravel framework | ~1,800 PHP files, ~400K lines | Modern PHP framework |
| Synthetic 1k | 1,000 generated PHP files | Controlled scaling test |
| Synthetic 10k | 10,000 generated PHP files | Parallel scaling test |
| Synthetic single 100k | 1 file, 100,000 lines | Single-file performance |

### Benchmark Suite

```bash
./bin/bench                              # Full suite, all corpora
./bin/bench --category text              # Text search only
./bin/bench --category ast               # AST search only
./bin/bench --category walker            # File walking only
./bin/bench --category parallel          # Parallel scaling
./bin/bench --corpus wordpress           # Only WordPress corpus
./bin/bench --compare rg,grep,sg         # Include external tools for comparison
./bin/bench --output results.json        # Machine-readable output
```

### Benchmark Report Format

```
greph Benchmark Report
=======================
Corpus: WordPress (2,547 files, 1,482,391 lines)
Workers: 4

Text Search:
  Literal "function"         12ms    (rg: 3ms, grep: 48ms)
  Literal (case insensitive) 18ms    (rg: 4ms, grep: 52ms)
  Regex /\$\w+->save\(/      24ms    (rg: 5ms, grep: 89ms)
  Regex (complex alternation) 31ms   (rg: 7ms, grep: 120ms)

AST Search:
  $x = new $Class()          180ms   (sg: 45ms)
  $obj->$method($$$ARGS)     210ms   (sg: 52ms)
  array($$$ITEMS)            165ms   (sg: 38ms)

File Walking:
  Full traversal             8ms     (rg: 2ms)
  With gitignore pruning     5ms     (rg: 1ms)

Parallel Scaling (literal "function"):
  1 worker                   38ms
  2 workers                  21ms
  4 workers                  12ms
  8 workers                  10ms    (diminishing returns)

Memory:
  Peak (text, WordPress)     4.2MB
  Peak (AST, WordPress)      28MB   (PHP-Parser AST in memory)
```

### Performance Targets

Order-of-magnitude expectations. Not hard SLAs.

| Operation | Target | vs ripgrep | vs grep |
|---|---|---|---|
| Literal search (WordPress) | <20ms | ~5x slower | ~3x faster |
| Regex search (WordPress) | <40ms | ~5x slower | ~3x faster |
| AST search (WordPress) | <300ms | N/A | N/A |
| File walking (WordPress) | <10ms | ~3x slower | ~2x faster |
| AST rewrite (100 files) | <500ms | N/A | N/A |

The goal is not to beat ripgrep (impossible in PHP). The goal is to be fast enough that agents don't notice, and faster than grep.

## Implementation Order

Build bottom-up. Each phase unlocks new scenario categories and benchmarks.

### Phase 1: File walker (the I/O foundation)

1. `FileWalker` (recursive directory traversal)
2. `GitignoreFilter` (parse and apply .gitignore rules)
3. `BinaryDetector` (magic bytes check)
4. `FileTypeFilter` (extension-based filtering)
5. `FileList` (collected paths for distribution)

**Oracle gate:** `./bin/test-regression --category edge` passes. Walker finds same files as `rg --files`. Gitignore pruning matches `rg --files` exactly.

**Benchmark gate:** `./bin/bench --category walker` establishes baseline. Must be within 5x of ripgrep on WordPress corpus.

### Phase 2: Text search (grep compatibility)

6. `BufferedReader` (64KB chunked reads with line tracking)
7. `LiteralSearcher` (strpos-based, no regex)
8. `RegexSearcher` (PCRE2 JIT)
9. `LiteralExtractor` (regex → literal prefix optimization)
10. `TextSearcher` (orchestrator: choose literal vs regex path)
11. `GrepFormatter` (file:line:content output)
12. `Match` value object

**Oracle gate:** `./bin/test-regression --category text` all green. Output matches `grep -rn` exactly on every scenario.

**Benchmark gate:** `./bin/bench --category text` — must be faster than `grep`, within 10x of `rg`.

### Phase 3: Parallel execution (speed multiplier)

13. `WorkerPool` (pcntl_fork management)
14. `Worker` (receives file list, runs searcher, writes results to pipe)
15. `ResultCollector` (reads from worker pipes, merges results)
16. `WorkSplitter` (distribute files across workers)

**Benchmark gate:** `./bin/bench --category parallel` — 4 workers must be >2.5x faster than 1 worker on 10k file corpus.

### Phase 4: AST search (structural matching)

17. `PhpParser` adapter (wraps nikic/PHP-Parser)
18. `PatternParser` (parse pattern string into AST with meta-variable markers)
19. `MetaVariable` (capture, binding, repeated variable enforcement)
20. `PatternMatcher` (recursive AST node comparison with meta-variable handling)
21. `AstSearcher` (orchestrator: parse file, match pattern, collect results)
22. `AstMatch` value object

**Oracle gate:** `./bin/test-regression --category ast` all green. Matches same code regions as `ast-grep --lang php`.

### Phase 5: AST rewrite (refactoring)

23. `AstRewriter` (apply rewrite template with captured meta-variables)
24. Format-preserving source splicing (use original positions, don't reformat untouched code)
25. Dry-run and interactive modes

**Oracle gate:** `./bin/test-regression --category rewrite` all green. Rewritten code matches `ast-grep` rewrite output.

### Phase 6: Harden

26. Unicode handling (UTF-8 patterns and source)
27. Malformed PHP files (must not crash, skip or warn)
28. Huge files (streaming, don't load entire file for text mode)
29. Symlink handling (follow or skip, configurable)
30. Edge cases: empty files, no matches, permission errors

**Oracle gate:** `./bin/test-regression` full suite green. `./bin/verify-compliance` report clean.

**Benchmark gate:** `./bin/bench` full suite, no regressions from Phase 2/3 baselines.

## Dependencies

| Dependency | Purpose | Required? |
|---|---|---|
| `nikic/php-parser` | PHP AST parsing for structural search/rewrite | Yes (for AST mode) |
| `pcntl` extension | Parallel worker forking | Optional (graceful fallback to single-process) |

No other dependencies. Text search mode has zero Composer dependencies.

## Comment Policy

Same as queuety. PHPDoc on public APIs. Inline comments explain why, not what. No decorative separators. No em dashes. Use periods, commas, colons, or rewrite.
