---
title: "Process-Restricted Hosts"
description: "Run Greph where proc_open(), shell_exec() or pcntl_fork() are disabled, and build the comparison helpers in a process-disabled mode."
path: "advanced/process-restricted-hosts"
order: 125
section: "Advanced"
meta_title: "Process-Restricted Hosts"
meta_description: "Run Greph where proc_open(), shell_exec() or pcntl_fork() are disabled, and build the comparison helpers in a process-disabled mode."
---

# Process-Restricted Hosts

Shared hosting, hardened containers and some PaaS platforms disable process functions through `disable_functions`. Greph's search, rewrite and index operations run inside the PHP process (parallel scans fork worker copies of it when they can), so they keep working there.

## What starts processes

| Code | Function | Used for |
|---|---|---|
| `Greph\Support\ToolResolver` | `shell_exec()` | Locating the external `grep`, `rg`, `sg` (or `npm`) and `gh` binaries on the `PATH` |
| `Greph\Support\CommandRunner` | `proc_open()` | Running those binaries and the Greph executables as subprocesses |
| `Greph\Parallel\WorkerPool` | `pcntl_fork()` | Parallel workers for `-j N` / `jobs: N` |

`ToolResolver` and `CommandRunner` serve the comparison tooling only: the feature matrix generator, the benchmark harness and the oracle test harness. `Greph::search*()`, `Greph::rewriteAst()`, the index builders and the four executables never call them.

The worker pool checks `function_exists('pcntl_fork')` before it forks. A function listed in `disable_functions` does not exist as far as PHP is concerned, so on such a host every chunk runs in-process, with the same result as `-j 1`. See [Parallel Workers](/docs/advanced/parallel).

With `proc_open`, `shell_exec`, `exec`, `system`, `passthru`, `popen` and `pcntl_fork` all disabled, text search with `jobs: 4`, AST rewrite, `greph-index build`, `greph-index ast-cache build` and `search`, and the `rg` and `sg` wrappers all run normally.

## Process-disabled helpers

When you embed the comparison helpers in such a host, construct them with an explicit process-disabled policy:

```php
use Greph\Support\CommandRunner;
use Greph\Support\ToolResolver;

$toolResolver = ToolResolver::processDisabled();
$commandRunner = CommandRunner::processDisabled();
```

These instances fail closed: every lookup or command throws a `RuntimeException` instead of attempting to start a process.

```text
Process-based tool lookup is disabled; cannot resolve binary: rg
Process execution is disabled; cannot run command: rg --version
```

Pass them wherever a `ToolResolver` or `CommandRunner` is accepted, for example the `toolResolver:` and `commandRunner:` arguments of `Greph\FeatureMatrix\FeatureMatrixGenerator`. The generator then completes without starting a process, but it cannot produce a useful report: probes against the upstream tools are reported as `Unavailable` and probes that run Greph's own executables as `Fail`, because every probe is a command. Generate the [feature matrix](/docs/advanced/feature-matrix) on a machine that allows processes.
