---
title: "AST Rewrite"
description: "Format-preserving structural rewrites with dry-run, interactive, and write modes."
path: "modes/rewrite"
order: 50
section: "Modes"
meta_title: "AST Rewrite"
meta_description: "Format-preserving structural rewrites with dry-run, interactive, and write modes."
---

# AST Rewrite

AST rewrite is AST search with a replacement template. Greph runs the same matcher as [AST search](/docs/modes/ast), then splices the rewritten template back into the source at the matched byte ranges. Surrounding whitespace, indentation, and comments are preserved. Captured nodes and lists are inserted using their original source text (see [Inside a match](#inside-a-match)).

## Quick examples

```bash
# Convert array() literals to []
./vendor/bin/greph -p 'array($$$ITEMS)' -r '[$$$ITEMS]' --dry-run src
./vendor/bin/greph -p 'array($$$ITEMS)' -r '[$$$ITEMS]' src

# isset ternary -> null coalesce
./vendor/bin/greph -p 'isset($X) ? $X : $Y' -r '$X ?? $Y' --dry-run src

# Rename a method call
./vendor/bin/greph -p '$obj->oldName($$$ARGS)' -r '$obj->newName($$$ARGS)' src

# Confirm each file before writing
./vendor/bin/greph -p 'array($$$ITEMS)' -r '[$$$ITEMS]' --interactive src
```

## Replacement templates

The replacement template uses the same metavariable syntax as the search pattern. Captured nodes are spliced back in by name:

| In the pattern | In the template | Effect |
|---|---|---|
| `$X` | `$X` | Insert the captured node |
| `$$$ARGS` | `$$$ARGS` | Insert the captured node sequence |
| `$NAME` (identifier) | `$NAME` | Insert the captured identifier |

Metavariables that appear in the pattern but not in the template are dropped from the rewritten code. A metavariable in the template must be bound by the pattern. An unbound name, including the non-capturing `$_`, is rejected before any files are written; the error names the variable.

## Modes

| Flag | Behavior |
|---|---|
| (none) | Apply the rewrite to every matching file |
| `--dry-run` | Print the rewritten file contents to stdout, do not write |
| `--interactive` | Prompt `Rewrite <file>? [y/N]` before writing each file |

The default is "write everything that changes". Use `--dry-run` first when iterating on a pattern, then drop the flag once you are happy with the diff.

## Format preservation

Greph does not reformat the file. The matcher records the byte range of every match in the original source, and the rewriter splices the rendered template directly into those ranges. Untouched code stays exactly as it was, including:

- Indentation and trailing whitespace
- Inline and standalone comments
- Blank line layout
- String quote style and concatenation
- PHP open/close tag style

This is a deliberate trade-off against full reformatting: Greph will not "tidy up" code it did not match, but it also will not introduce noisy diffs in unrelated lines.

### Inside a match

Captured nodes retain their original bytes, including quote style, numeric spelling, escape sequences, and heredoc indentation. A complete variadic list also retains its whitespace, trailing comma, and comments, including comments in an otherwise empty list.

```php
// before
$d = array(
    "a" => 1,  // trailing comment
);

// after -p 'array($$$ITEMS)' -r '[$$$ITEMS]'
$d = [
    "a" => 1,  // trailing comment
];
```

The template controls newly introduced code. Comments within the match that are outside the retained captures are kept before the replacement, so a comment can move when its original syntax is removed. Greph adds parentheses when needed to preserve expression structure and checks the rewritten file before returning changes. A transformation that cannot preserve the template's AST in context is rejected.

## Multi-line and nested matches

Multi-line patterns work the same as single-line ones. Captured layout comes from the source; new syntax comes from the template.

Greph rewrites each file in one pass and selects the outermost of overlapping matches, matching ast-grep. Nested matches inside a selected capture are left for a later run:

```php
// before
$b = array(1, array(2));

// after one run of -p 'array($$$ITEMS)' -r '[$$$ITEMS]'
$b = [1, array(2)];

// after a second run
$b = [1, [2]];
```

The outermost match also wins when matches start at the same offset, such as a method chain. The rewriter does not repeat the pattern until convergence; run it again if you want to transform remaining nested constructs.

## Programmatic use

```php
use Greph\Greph;
use Greph\Ast\AstSearchOptions;

$rewrites = Greph::rewriteAst(
    'array($$$ITEMS)',
    '[$$$ITEMS]',
    'src',
    new AstSearchOptions(jobs: 4),
);

foreach ($rewrites as $result) {
    if (!$result->changed()) {
        continue;
    }

    echo "{$result->file}: {$result->replacementCount} replacement(s)\n";
    file_put_contents($result->file, $result->rewrittenContents);
}
```

`Greph::rewriteAst()` does not write files itself. The CLI is responsible for committing the changes (or skipping them in `--dry-run` and declined `--interactive` cases). This makes the facade safe to call from automated agents that want to inspect the diff before applying it.

`RewriteResult` exposes:

- `file`: absolute path
- `originalContents`: the file contents before the rewrite
- `rewrittenContents`: the file contents after the rewrite
- `replacementCount`: number of structural replacements
- `changed()`: helper that returns `true` when `originalContents !== rewrittenContents`

## When to use rewrite

Rewrite is the right tool when:

- The transformation is local and structural (not whole-file restructuring).
- You want a deterministic, repeatable refactor that you can re-run until nothing changes.
- You want to preview the change before applying it.
- You want to integrate the refactor into a script or CI job without invoking an external binary.

Rewrite is not the right tool when:

- The transformation depends on type information that PHP-Parser cannot resolve from a single file.
- The transformation needs to add or remove imports across the file boundary.
- You want a formatter rather than a refactor (use PHP-CS-Fixer).
