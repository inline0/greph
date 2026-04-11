import { defineConfig } from "onedocs/config";
import {
  Code2,
  Cpu,
  Database,
  FileSearch,
  Package,
  Replace,
  Search,
  Terminal,
} from "lucide-react";

const iconClass = "h-5 w-5 text-fd-primary";

export default defineConfig({
  title: "Greph",
  description:
    "Pure PHP code search, structural search, and rewrite engine. grep, ripgrep, and ast-grep workflows in a single Composer package.",
  logo: {
    light: "/logo-light.svg",
    dark: "/logo-dark.svg",
  },
  icon: { light: "/icon.png", dark: "/icon-dark.png" },
  nav: {
    github: "inline0/greph",
  },
  footer: {
    links: [{ label: "Inline0.com", href: "https://inline0.com" }],
  },
  homepage: {
    features: [
      {
        title: "Pure PHP",
        description:
          "No exec(), no FFI, no extensions beyond ext-json. Works everywhere PHP 8.2+ runs, including hardened hosting.",
        icon: <Package className={iconClass} />,
      },
      {
        title: "Text Search",
        description:
          "Fixed-string and regex search with whole-word, case-insensitive, context, count, and file-only modes. grep-compatible output.",
        icon: <Search className={iconClass} />,
      },
      {
        title: "AST Search",
        description:
          "Structural PHP search with $VAR and $$$VARIADIC metavariables, repeated captures, and JSON output. Format-agnostic matching.",
        icon: <Code2 className={iconClass} />,
      },
      {
        title: "AST Rewrite",
        description:
          "Format-preserving structural rewrites with dry-run, interactive, and write modes. Refactor without losing comments or layout.",
        icon: <Replace className={iconClass} />,
      },
      {
        title: "Indexed Modes",
        description:
          "Warmed trigram text indexes, AST fact indexes, and cached AST search. Order-of-magnitude faster than re-scanning.",
        icon: <Database className={iconClass} />,
      },
      {
        title: "rg & sg Wrappers",
        description:
          "Drop-in ripgrep and ast-grep compatibility wrappers, probe-verified against the upstream binaries.",
        icon: <Terminal className={iconClass} />,
      },
      {
        title: "Parallel Workers",
        description:
          "pcntl-based worker pool with single-process fallback. Scales text and AST scans across all available cores.",
        icon: <Cpu className={iconClass} />,
      },
      {
        title: "Oracle-Tested",
        description:
          "Every mode is verified against canonical grep, ripgrep, and ast-grep oracles in a regression corpus that runs in CI.",
        icon: <FileSearch className={iconClass} />,
      },
    ],
  },
});
