---
title: "sg Compatibility"
description: "Drop-in ast-grep wrapper backed by the Greph engine."
path: "compatibility/sg"
order: 100
section: "Compatibility"
meta_title: "sg Compatibility"
meta_description: "Drop-in ast-grep wrapper backed by the Greph engine."
---

# sg Compatibility

`vendor/bin/sg` is an ast-grep-style compatibility wrapper. It accepts the most commonly used `ast-grep` flags and reuses the same `Greph\Ast\AstSearcher` engine that `vendor/bin/greph -p` uses. The verified surface is reproduced from probe runs against the upstream `sg` binary and lives in [Advanced / Feature Matrix](/docs/advanced/feature-matrix).

## When to use it

Use the wrapper when:

- You have an existing tool, agent, or script that already invokes `sg`.
- You want a drop-in replacement that works in environments where you cannot install `ast-grep` itself.
- You want ast-grep ergonomics from a Composer-only install.

Use native `vendor/bin/greph -p` when:

- You are writing new code and do not need the ast-grep CLI shape.
- You want the same flag set as text mode (the native binary unifies both).

## Usage

```bash
sg run --pattern PATTERN [options] [path...]
sg scan -p PATTERN [options] [path...]
sg rewrite -p PATTERN -r TEMPLATE [options] [path...]
sg outline [options] [path...]
```

`run` is the canonical ast-grep verb. `scan` and `rewrite` are wrapper-only aliases that mirror common usage. A bare invocation works as a one-shot search:

```bash
./vendor/bin/sg --pattern 'array($$$ITEMS)' src
```

## PHP outlines

```bash
sg outline src/
sg outline src/Service.php --view expanded
sg outline --json=compact --items all --type class,enum src/
sg outline --match '^Service$' --pub-members src/Service.php
cat src/Service.php | sg outline --stdin --lang php --json=stream
```

The PHP outline command follows ast-grep 0.51's built-in rules. It emits classes,
interfaces, traits, enums, functions and imports, with direct methods, properties,
and enum cases. File inputs default to a signature-and-member digest; directory
inputs default to grouped exported names.

- `--view`: `names`, `signatures`, `digest`, or `expanded`.
- `--items`: `structure`, `exports`, `imports`, or `all`.
- `--type`: comma-separated top-level symbol types such as `class,enum`.
- `--match`: a regex applied to top-level names and signatures.
- `--pub-members`: include public members only.
- `--json`, `--json=compact`, `--json=stream`: structured output.
- `--stdin --lang php`: outline standard input.

Explicit template files such as `.phtml` work with PHP language selection.
Directory scanning uses ast-grep's `.php` language glob. Plain-text presentation
and JSON data are checked against upstream; source columns count Unicode
characters, with byte offsets provided separately.

## Patterns

The pattern grammar is identical to native AST search. See [Modes / AST Search](/docs/modes/ast) for the full reference.

```bash
./vendor/bin/sg run --pattern 'array($$$ITEMS)' src
./vendor/bin/sg run --pattern '$obj->$method($$$ARGS)' src
./vendor/bin/sg run --pattern 'new $CLASS()' src
```

## Rewrites

The wrapper supports the same dry-run / interactive / write workflow as native rewrite, with ast-grep flag names:

```bash
# Preview only (default)
./vendor/bin/sg run --pattern 'array($$$ITEMS)' --rewrite '[$$$ITEMS]' src

# Apply without confirmation
./vendor/bin/sg run --pattern 'array($$$ITEMS)' --rewrite '[$$$ITEMS]' --update-all src

# Confirm each match
./vendor/bin/sg run --pattern 'array($$$ITEMS)' --rewrite '[$$$ITEMS]' --interactive src

# Wrapper-only aliases
./vendor/bin/sg rewrite -p 'array($$$ITEMS)' -r '[$$$ITEMS]' --dry-run src
./vendor/bin/sg rewrite -p 'array($$$ITEMS)' -r '[$$$ITEMS]' --interactive src
```

## JSON output

`--json` emits ast-grep-shaped JSON. Three styles are supported:

```bash
./vendor/bin/sg run --json --pattern 'dispatch($EVENT)' src
./vendor/bin/sg run --json=stream --pattern 'dispatch($EVENT)' src
./vendor/bin/sg run --json=compact --pattern 'dispatch($EVENT)' src
```

| Style | Shape |
|---|---|
| `--json` (default `pretty`) | Single pretty-printed JSON array |
| `--json=stream` | One match per line, newline-delimited JSON |
| `--json=compact` | Single compact JSON array |

## Verified surface

The following flags are verified against the upstream `sg` binary in the [feature matrix](/docs/advanced/feature-matrix). Where a flag has two spellings (`-p` and `--pattern`, `--glob` and `--globs`), the probe uses one of them and the wrapper parses both as the same option.

### Pattern flags

- `--pattern PATTERN` (top-level and inside `run`)
- `-p PATTERN` (alias)
- `--rewrite TEMPLATE`
- `-r TEMPLATE`
- `--lang NAME`, `-l NAME`

### Match flags

- `--files-with-matches`
- `--json` (`pretty`, `stream`, `compact`)
- `--update-all`, `-U`

### Walker flags

- `--no-ignore hidden` (combined form)
- `--glob GLOB`, `--globs GLOB`

### Worker flags

- `-j N`, `--threads N`

### Accepted without a probe

The wrapper also accepts these flags, but no feature matrix probe covers them yet:

- `--no-ignore` on its own, or with a value other than `hidden`
- `--type NAME`, `--type-not NAME`

### Wrapper-only extras

The wrapper exposes a few helpers that ast-grep itself does not have:

- `scan` and `rewrite` aliases for `run`
- `--hidden` flag (ast-grep folds this into `--no-ignore hidden`)
- `--dry-run` rewrite preview alias
- `--interactive` rewrite confirmation flow

## Limitations

Greph's `sg` wrapper covers the structural search and rewrite surface that most tools and agents use. It does not currently expose the following ast-grep features:

- YAML rule files (`--rule`, `--config`) and custom outline extractors (`--outline-rules`)
- Multi-language scanning beyond PHP
- Non-pattern transformers (`--debug-query`, `--inspect`)
- LSP integration

If you hit a missing flag, file an issue with the probe you ran against upstream `sg` and the expected output.

## Behavior parity

The wrapper aims for behavior parity, not byte parity. Output is semantically equivalent but may differ in cosmetic details. The probes used by the [feature matrix](/docs/advanced/feature-matrix) check the parser-relevant fields (file path, line number, captured code, captures, JSON shape) for equivalence, not the byte stream.
