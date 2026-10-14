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

AST rewrite is AST search with a replacement template. Greph runs the same matcher as [AST search](/docs/modes/ast), then splices the rewritten template back into the source at the matched byte ranges. Surrounding whitespace, indentation, and comments are preserved; the matched code itself is re-printed (see [Inside a match](#inside-a-match)).

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

Metavariables that appear in the pattern but not in the template are dropped from the rewritten code. A metavariable that appears in the template but not in the pattern is not rejected today: it is printed literally, so `-r '$o->$m($$$A, $EXTRA)'` writes a `$EXTRA` variable into the code ([#4](https://github.com/inline0/greph/issues/4)). Check templates with `--dry-run` first.

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

The matched range itself is replaced by the template rendered through PHP-Parser's pretty printer, with the captured nodes re-printed rather than copied as source text. Today that means ([#2](https://github.com/inline0/greph/issues/2)):

- A comment that is not attached to a following node inside the match is dropped, for example a trailing comment after the last array item or a comment in an otherwise empty array.
- Comments attached to captured nodes are kept but move onto their own line, which can turn a one-line expression into several lines.
- Line breaks inside the match are not kept: a multi-line `array(...)` without such comments becomes a one-line `[...]`.
- Literal spelling is normalized: `0x1F` becomes `0x1f`, `1_000` becomes `1000`, escape sequences such as `"\x41"` are written out as the character (`"A"`), and flexible heredocs lose their indentation.

```php
// before
$d = array(
    "a" => 1,  // trailing comment
);

// after -p 'array($$$ITEMS)' -r '[$$$ITEMS]'
$d = ["a" => 1];
```

Review the `--dry-run` output for matches that contain comments or hand-formatted literals.

## Multi-line and nested matches

Multi-line patterns work the same as single-line ones. The replacement is rendered by the pretty printer with the metavariables substituted in, so the layout of the replaced code comes from the printer, not from the original match.

Greph rewrites each file in one pass and never applies two overlapping matches. It walks the matches from the end of the file backwards and skips any match that overlaps one it has already rewritten. For nested matches that start at different offsets, the inner match is rewritten and the outer one is left as it was:

```php
// before
$b = array(1, array(2));

// after one run of -p 'array($$$ITEMS)' -r '[$$$ITEMS]'
$b = array(1, [2]);

// after a second run
$b = [1, [2]];
```

When two matches start at the same offset (a method chain matched by `$obj->$method($$$ARGS)`, for example), the outer match is rewritten instead. When a pattern can nest, run the rewrite again until `--dry-run` prints nothing ([#3](https://github.com/inline0/greph/issues/3)). ast-grep rewrites the outer of two nested matches, so `vendor/bin/sg` and the upstream `sg` produce different results here.

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
