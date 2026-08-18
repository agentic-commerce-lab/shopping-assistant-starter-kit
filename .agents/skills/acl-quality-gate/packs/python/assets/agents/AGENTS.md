<!--
  ACL quality-gate template (Python). Copy to the project root (or each gated project root in
  a multi-project repo) and MERGE into any existing AGENTS.md — do not overwrite.
  Fill every {{placeholder}} with the project's real choice and delete the guidance comments.
  Keep this file short: it loads into agent context on every task, so prune anything that
  does not change how code is written here.
-->

# Project Conventions

This project runs the ACL quality gate ({{strict | baseline}} mode) on the latest stable Python version.
Follow these conventions when writing or refactoring code; CI enforces them. Full rationale
lives in the quality-gate policy, not here.

## Commands

mise owns the toolchain and tasks; uv owns dependencies.

- Format + lint a change: `mise run format:check && mise run lint`
- Add type checking for code changes: `mise run typecheck` (basedpyright, strict)
- Cycles / boundaries / cleanup changes: `mise run quality:boundaries && mise run quality:deadcode`
- Broad refactor or gate change: `mise run quality`
- Run the narrowest useful check for the change; rely on CI as the authority.

## Python strictness

- basedpyright strict is on. Do not bypass it with `Any`, unsafe casts, or broad `# type: ignore`.
- Prefer precise types; narrow broad `object`/`Any` values before use.
- In baseline mode the committed `.basedpyright/baseline.json` records accepted debt; do not
  add new code to it — fix new findings.

## Logging

- Use the project logger ({{logging | structlog | framework logger}}); never `print` in
  application code (allowed in scripts, CLIs, migrations, config — already scoped in Ruff).
- Structured fields, intentional levels, no secrets/PII; log the exception (with `__cause__`),
  not just `str(exc)`.

## Error handling

- Raise `Exception` subclasses (domain-specific where it carries meaning) — never bare values.
- Preserve the original error with `raise ... from err` when wrapping; narrow caught exceptions
  before reading attributes.
- No `return`/`break`/`continue` from `finally`; do not swallow exceptions silently.

## Shared contracts

- Validate boundary data at runtime with {{Pydantic v2}} models (`model_validate` at the edge);
  extend the existing contract instead of duplicating request/response shapes.
- Keep contract modules small and domain-oriented; do not create one catch-all `schemas.py`.

## Database & persistence

- Use the project's persistence stack ({{ORM/query layer + migration tool}}) and migration workflow;
  no raw SQL scattered through app code, no manual/auto schema changes in deployed environments.
- Ship schema, migration, and application changes together; keep them in the database-owned package.

## Environment config

- Read config through a typed accessor ({{pydantic-settings BaseSettings}}); never scatter raw
  `os.environ` reads through application code.
- Keep `.env*` at the owning app/package root; commit a `.env.example` with safe placeholders;
  never commit real secrets.

## Structure & constants

- High cohesion, loose coupling: each module/package owns one related responsibility; import a
  package's public entry point, not its internals. `tach` enforces boundaries and forbids cycles.
- Place code at the smallest cohesive boundary that owns it; prefer domain/feature packages over
  `utils`/`helpers`/`common` dumping grounds.
- Import absolutely through the source root, not relative parent (`..`) paths.
- Split a package into focused sub-modules as it grows; don't let one module become a long file.
- Before adding a repeated literal, URL, limit, timeout, flag key, or identifier, reuse the
  existing constant or typed config accessor.
- Reuse before reinventing: for non-trivial functionality, prefer a well-maintained library
  (stdlib first, then existing deps / internal shared code) over a bespoke implementation — but
  don't add a dependency for something a few lines of stdlib already cover.
