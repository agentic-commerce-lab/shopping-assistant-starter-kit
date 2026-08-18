# Development Usage (Python)

The language-neutral implementation-phase workflow — daily checks, applying the conventions, before-finishing checks, and the quality-pass process — lives in [references/development-usage.md](../../references/development-usage.md). **Read it first.** This page only binds it to the Python toolchain. The coding standards live in [conventions.md](../../references/conventions.md) and this pack's [quality-gate-policy.md](quality-gate-policy.md).

## Runner and Commands

Run the canonical command names with `mise run <name>`. This pack's `quality:*` sub-gates:

- `quality:boundaries` — circular dependencies + module boundaries (tach)
- `quality:filesize` — file-length gate (~400 LOC)
- `quality:deadcode` — dead code (Vulture)
- `quality:security` — security lint (Bandit)
- `quality:deps` — non-blocking dependency freshness

Type checking is `basedpyright` (strict), wired to `typecheck`.

## Scoping a Quality Pass (Python)

For the shared *Running a Quality Pass* → *Scope* step:

- **Path-scopable** (can target a directory): `ruff check <dir>`, `ruff format --check <dir>`, `vulture <dir>`, `bandit -r <dir>`, `python scripts/check_file_length.py <dir>`.
- **Whole-program only** (cannot narrow to a directory): `basedpyright` and `tach check` — read their output filtered to your scope.
- There is no change-scoped audit tool; for in-flight work, scope to the touched directory/module.

## Python-Specific Review Items

Add this to the shared *Review against the conventions* checklist:

- **Type safety specifics:** no `Any`, unsafe casts, or broad `# type: ignore` papering over real types; narrow broad `object`/`Any` values before use. See conventions → *Code Safety*.

(Vulture is heuristic, so cross-module unused-export findings need a human read — see [quality-gate-policy.md](quality-gate-policy.md) → *Capability Gaps*.)

## Recording Legacy Debt (baseline mode)

When triage records pre-existing debt, this pack uses the basedpyright baseline, the Vulture `whitelist.py`, and the Bandit baseline — see [adoption-workflow.md](adoption-workflow.md). The local `quality:deadcode` / `quality:security` tasks are baseline-blind; to mirror the CI verdict, run the baseline-aware commands from that doc.
