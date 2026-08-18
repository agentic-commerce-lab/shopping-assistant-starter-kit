<!--
  ACL quality-gate template. Copy to the project root (or each gated project root in a
  split repo) and MERGE into any existing AGENTS.md — do not overwrite.
  Fill every {{placeholder}} with the project's real choice and delete the guidance comments.
  Keep this file short: it loads into agent context on every task, so prune anything that
  does not change how code is written here.
-->

# Project Conventions

This project runs the ACL quality gate ({{strict | baseline}} mode). Follow these conventions
when writing or refactoring code; CI enforces them. Full rationale lives in the quality-gate
policy, not here.

## Commands

- Format + lint a change: `bun run format:check && bun run lint`
- Add type checking for code changes: `bun run typecheck` (or the framework checker: `{{nuxt typecheck | vue-tsc --noEmit | svelte-check | tsc --noEmit}}`)
- Architecture / import / cleanup changes: `bun run quality:fallow && bun run quality:health`
- Broad refactor or gate change: `bun run quality`
- Run the narrowest useful check for the change; rely on CI as the authority.

## TypeScript

- Strict mode is on. Do not bypass it with `any`, unsafe casts, or broad `unknown` assertions.
- Prefer optional chaining and nullish coalescing where they express intent more safely.

## Frontend

- Styling: {{Tailwind | CSS Modules | styled-components | ...}}. Do not introduce a different styling approach.
- Reuse the existing shared/{{shadcn}}-style component layer before writing new markup; restyle or extend before falling back to raw HTML.
- Split large pages into cohesive components; keep route/page files thin.

## Logging

- Use the project logger ({{logger}}); never `console` in application code (allowed in scripts, CLIs, migrations, config).
- Structured fields, intentional levels, no secrets/PII; log the error object or `cause`, not just `error.message`.

## Error handling

- Throw `Error` or domain-specific `Error` subclasses only — no strings, objects, or other values.
- Preserve the original error via `cause` when wrapping; narrow `unknown` before reading properties.
- No `return`/`throw`/`break`/`continue` from `finally`.

## Shared contracts

- Validate boundary data at runtime with {{Zod | Valibot | ...}}; extend the existing shared contract instead of duplicating request/response types.
- Keep contract files small and domain-oriented.

## Database & persistence

- Use the project's persistence stack ({{ORM/query layer}}) and migration workflow; no raw SQL scattered through app code, no manual/auto-sync schema changes in deployed environments.
- Ship schema, migration, generated-client, and application changes together; keep them in the database-owned location.

## Environment config

- Read config through the typed accessor / framework runtime config; never scatter raw `process.env` reads.
- Keep `.env*` at the owning app/package root; expose browser-safe values only via the framework's public prefix ({{VITE_ | NEXT_PUBLIC_ | NUXT_PUBLIC_}}); never commit real secrets.

## Structure & constants

- High cohesion, loose coupling: each module/directory owns one related responsibility; depend on a module's public entry point, not its internals.
- Place code at the smallest cohesive boundary that owns it; prefer domain/feature folders over `utils`/`helpers`/`common` dumping grounds.
- Import through path aliases or package public APIs, not parent-directory shortcuts.
- Split a directory into focused sub-modules as it grows; don't let one folder become a long flat list of files.
- Before adding a repeated literal, URL, limit, timeout, flag key, or identifier, reuse the existing constant or typed config accessor.
- Reuse before reinventing: for non-trivial functionality, prefer a well-maintained package (runtime/stdlib first, then existing deps / internal shared code) over a bespoke implementation — but don't add a dependency for something a few lines already cover.

# Review Instructions

When reviewing code here (PR/branch review, or a requested "quality pass" or "clean up"), invoke the `acl-quality-gate` skill and follow its quality-pass workflow: run the in-scope checks from **Commands** above, then read the change against the conventions in this file — tools can't judge whether logging, error handling, contracts, or structure are *correct*, only a read can. Treat CI as the final authority; never relax a threshold to make a review pass — record genuine pre-existing debt as a baseline instead.
