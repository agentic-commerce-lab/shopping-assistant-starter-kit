# Development Usage (PHP)

The language-neutral implementation-phase workflow — daily checks, applying the conventions,
before-finishing checks, and the quality-pass process — lives in
[references/development-usage.md](../../references/development-usage.md). **Read it first.**
This page only binds it to the PHP toolchain. The coding standards live in
[conventions.md](../../references/conventions.md) and this pack's
[quality-gate-policy.md](quality-gate-policy.md).

## Runner and Commands

Run the canonical command names with `composer run <name>`. This pack's `quality:*` gates:

- `quality:filesize` — file-length gate (~400 LOC; `check_file_length.php`)
- `quality:dupes` — code duplication (jscpd)
- `quality:depcheck` — unused/missing Composer dependencies (composer-dependency-analyser)
- `quality:security` — dependency vulnerabilities (composer audit)
- `quality:boundaries` — circular dependencies + module boundaries (Mago guard; opt-in)
- `quality:deps` — non-blocking dependency freshness (composer outdated)
- `quality:maintainability` / `quality:hotspots` — **non-blocking advisory** cognitive
  complexity, method length, and churn hotspots (phpcca)

Formatting is `mago fmt` (`format`/`format:check`), linting is `mago lint` (`lint`/`lint:fix`),
and type checking is `mago analyze` (`typecheck`).

## Scoping a Quality Pass (PHP)

For the shared *Running a Quality Pass* → *Scope* step:

- **Path-scopable** (can target a directory/file): `mago lint <path>`, `mago fmt --check <path>`,
  `jscpd <path>`, `php scripts/check_file_length.php <path>`, `phpcca analyse <path>`.
- **Whole-program only** (cannot narrow to a directory): `mago analyze` and `mago guard` —
  read their output filtered to your scope.
- There is no change-scoped audit tool; for in-flight work, scope to the touched
  directory/module.

## PHP-Specific Review Items

Add these to the shared *Review against the conventions* checklist:

- **Type safety specifics:** no `mixed`, unsafe casts, or broad `@var`/`assert` papering over
  real types; `declare(strict_types=1)` in every file. See conventions → *Code Safety*.
- **Cognitive complexity & method length are advisory:** phpcca *measures* them but does not
  gate. Read its output during a quality pass and treat overruns as review findings — the
  mechanical gate only covers cyclomatic complexity, nesting, params, and file length.
- **Cross-file dead code:** Mago analyze finds private/unreachable dead code only; unused
  *public* API across files is a human read (no maintained standalone tool — see
  [quality-gate-policy.md](quality-gate-policy.md) → *Capability Gaps*).

## Recording Legacy Debt (baseline mode)

When triage records pre-existing debt, this pack uses the Mago lint/analyze (and guard)
baselines and a raised jscpd threshold — see [adoption-workflow.md](adoption-workflow.md).
The local `quality:dupes` and plain `lint`/`typecheck` are baseline-blind; to mirror the CI
verdict, run the baseline-aware commands from that doc.
