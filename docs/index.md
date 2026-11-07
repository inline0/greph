---
title: "Introduction"
description: "Greph searches and refactors PHP source trees in pure PHP: grep-compatible text search, AST search with metavariables and format-preserving rewrites."
path: "."
order: 10
meta_title: "Introduction"
meta_description: "Greph searches and refactors PHP source trees in pure PHP: grep-compatible text search, AST search with metavariables and format-preserving rewrites."
---

# Introduction

Greph is a pure PHP search and refactoring engine for source trees, installed as the Composer package `greph/greph`. It does three jobs that usually need three native binaries:

- **Text search** with grep-compatible output and ripgrep-style ergonomics, like `grep` and `rg`.
- **AST search** with PHP structural patterns and metavariables, like `ast-grep`.
- **AST rewrite** with format-preserving structural refactoring, like `ast-grep --rewrite`.

On top of these it ships warmed indexed modes for repeated text and AST queries, and `rg` and `sg` wrappers that accept the ripgrep and ast-grep command lines. Every mode runs inside PHP: Greph never shells out to `grep`, `rg` or `ast-grep`.

## Why Greph exists

PHP applications and agents that need fast code search usually shell out to native tools (`grep`, `ripgrep`, `ast-grep`), giving up portability and adding deployment friction. Structural search and rewrite is even worse: it normally means leaving the PHP process entirely.

Greph closes that gap. Your PHP code can run text searches, structural matches and refactoring rewrites using only PHP's bundled extensions plus `nikic/php-parser`. Output formats and exit codes follow the canonical tools, so existing scripts and agents that parse `grep` or `rg` output do not need to change.

## Modes

| Mode | Command | Facade method | Use it for |
|---|---|---|---|
| [Text search](/docs/modes/text) | `greph "pattern" src` | `Greph::searchText()` | Fixed strings and PCRE2 regexes, line by line |
| [AST search](/docs/modes/ast) | `greph -p 'new $CLASS()' src` | `Greph::searchAst()` | PHP code shapes, independent of formatting and comments |
| [AST rewrite](/docs/modes/rewrite) | `greph -p 'array($$$ITEMS)' -r '[$$$ITEMS]' src` | `Greph::rewriteAst()` | Mechanical refactors that keep surrounding code untouched |
| [Indexed text](/docs/indexed/text) | `greph-index search -F "function" .` | `Greph::searchTextIndexed()` | Repeated text queries on a large tree |
| [Indexed AST](/docs/indexed/ast) | `greph-index ast-index search 'new $CLASS()' src` | `Greph::searchAstIndexed()` | Repeated structural queries with a recognizable head |
| [Cached AST](/docs/indexed/cache) | `greph-index ast-cache search 'array($$$ITEMS)' src` | `Greph::searchAstCached()` | Repeated structural queries without reparsing |
| [rg wrapper](/docs/compatibility/rg) | `rg -F "function" src` | | Tools and agents that already call ripgrep |
| [sg wrapper](/docs/compatibility/sg) | `sg run --pattern 'new $CLASS()' src` | | Tools and agents that already call ast-grep |

The three plain modes read the source tree on every call, so their results are always current. The three indexed modes store data on disk under the searched root (`.greph-index`, `.greph-ast-index`, `.greph-ast-cache`) and trade a build step for faster repeated queries. [Indexed Text Search](/docs/indexed/text#indexed-or-plain-text-search) explains when that trade pays off.

All modes share one [file walker](/docs/advanced/walker), so `.gitignore` and `.grephignore` rules, hidden-file handling, binary detection and `--type` and `--glob` filters behave the same in every mode. Plain text and AST scans can split work across [parallel workers](/docs/advanced/parallel) when `ext-pcntl` is available.

## Executables

Installing the package adds four executables to `vendor/bin/`:

- `greph`: native text search, AST search and AST rewrite
- `greph-index`: warmed text index, AST fact index and cached AST search
- `rg`: ripgrep-style compatibility wrapper
- `sg`: ast-grep-style compatibility wrapper, which also prints PHP code outlines with `sg outline`

The `vendor/bin` executables need Greph 0.2.3 or later when Greph is installed as a dependency of your project. The [CLI reference](/docs/cli) lists every flag of all four.

## A minimal example

Install the package:

```bash
composer require greph/greph
```

Given a project with this file at `src/e.php`:

```php
<?php
$config = array('debug' => true);
$logger = new Logger();
```

A fixed-string text search prints grep-style `file:line:content` lines:

```bash
vendor/bin/greph -F "Logger" src
```

```text
src/e.php:3:$logger = new Logger();
```

An AST search matches the code shape, whatever the class name:

```bash
vendor/bin/greph -p 'new $CLASS()' src
```

```text
src/e.php:3:new Logger()
```

A rewrite with `--dry-run` prints the rewritten file without writing it:

```bash
vendor/bin/greph -p 'array($$$ITEMS)' -r '[$$$ITEMS]' --dry-run src
```

```text
=== src/e.php ===
<?php
$config = ['debug' => true];
$logger = new Logger();
```

Drop `--dry-run` to write the change. The same three operations from PHP:

```php
use Greph\Greph;
use Greph\Ast\AstSearchOptions;
use Greph\Text\TextSearchOptions;

$matches = Greph::searchText(
    'Logger',
    'src',
    new TextSearchOptions(fixedString: true),
);

foreach ($matches as $file) {
    foreach ($file->matches as $match) {
        echo "{$file->file}:{$match->line}:{$match->content}\n";
    }
}

$nodes = Greph::searchAst(
    'new $CLASS()',
    'src',
    new AstSearchOptions(),
);

$rewrites = Greph::rewriteAst(
    'array($$$ITEMS)',
    '[$$$ITEMS]',
    'src',
);
```

`Greph::rewriteAst()` returns `RewriteResult` objects with the original and rewritten contents and does not write files; the caller decides whether to save them. The result classes are documented in the [API reference](/docs/api#result-objects).

## How the docs are organized

**Start here**

- [Getting Started](/docs/getting-started): installation, requirements and a first text search, AST search and rewrite.
- [CLI](/docs/cli): every flag of `greph`, `greph-index`, `rg` and `sg`, plus exit codes.
- [API](/docs/api): the `Greph\Greph` facade, option classes, result objects and exceptions.

**Modes**, the plain engines that read the tree on every call:

- [Text Search](/docs/modes/text): fixed-string and regex search, file filters, context, counts and JSON output.
- [AST Search](/docs/modes/ast): pattern syntax, `$VAR`, `$_` and `$$$VARIADIC` metavariables, repeated captures.
- [AST Rewrite](/docs/modes/rewrite): replacement templates, dry-run and interactive modes, format preservation.

**Indexed Modes**, the warmed stores for repeated queries:

- [Indexed Text Search](/docs/indexed/text): trigram and identifier postings, lifecycle profiles, index sets and when indexing beats a plain scan.
- [Indexed AST Search](/docs/indexed/ast): the AST fact index that prefilters files by call names, class names and declarations.
- [Cached AST Search](/docs/indexed/cache): stored parse trees that skip the parser on repeated structural queries.

**Compatibility**, the drop-in wrappers:

- [rg Compatibility](/docs/compatibility/rg): the ripgrep command line and JSON event format on the Greph engine.
- [sg Compatibility](/docs/compatibility/sg): the ast-grep command line, rewrites and PHP outlines.

**Advanced**, internals and verification:

- [File Walker](/docs/advanced/walker): ignore rules, type aliases, binary detection, symlinks and size limits.
- [Parallel Workers](/docs/advanced/parallel): when the `pcntl` worker pool starts and how it falls back.
- [Process-Restricted Hosts](/docs/advanced/process-restricted-hosts): running where `proc_open()`, `shell_exec()` or `pcntl_fork()` are disabled.
- [Feature Matrix](/docs/advanced/feature-matrix): the probe-driven report of what the wrappers support.
- [Testing](/docs/advanced/testing): unit tests and the oracle corpus that diffs Greph against grep, ripgrep and ast-grep.
- [Benchmarks](/docs/advanced/benchmarks): the benchmark harness, corpora and how the published numbers are produced.

## What it requires

- PHP 8.2 or later
- `ext-json`, `ext-ctype` and `ext-tokenizer`, which are enabled in almost every PHP build
- `ext-zlib` for the cached AST store (`greph-index ast-cache`)
- `nikic/php-parser` (the only Composer dependency)

Core text and AST features run in pure PHP. Optional benchmark comparisons depend on external tools like `rg`, `grep`, and `sg`, but Greph itself does not require them to operate. Parallel scans use `ext-pcntl` when available and fall back to single-process execution otherwise. Hosts that forbid `proc_open()` and `shell_exec()` are covered in [Process-Restricted Hosts](/docs/advanced/process-restricted-hosts).

## What it is not

Greph finds code and optionally rewrites it. It is not a linter (use PHPStan or Psalm), not a formatter (use PHP-CS-Fixer) and not an IDE. AST search and rewrite parse PHP only; text search works on any text file.
