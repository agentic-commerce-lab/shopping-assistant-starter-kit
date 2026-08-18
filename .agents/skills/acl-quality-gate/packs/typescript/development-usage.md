# Development Usage (TypeScript)

The language-neutral implementation-phase workflow — daily checks, applying the conventions, before-finishing checks, and the quality-pass process — lives in [references/development-usage.md](../../references/development-usage.md). **Read it first.** This page only binds it to the TypeScript toolchain. The coding standards live in [conventions.md](../../references/conventions.md) and this pack's [quality-gate-policy.md](quality-gate-policy.md).

## Runner and Commands

Run the canonical command names with `bun run <name>`. This pack's `quality:*` sub-gates:

- `quality:contracts` — shared-contract structure (Zod + contract lint)
- `quality:fta` — per-file maintainability
- `quality:fallow` / `quality:health` — dead code, cycles, duplication, health score
- `quality:audit` — change-scoped regression verdict (the default "is my work clean" pass)
- `quality:deps` — non-blocking dependency freshness

Use framework-native type checking where present (`nuxt typecheck` / `vue-tsc --noEmit` / `svelte-check`) wired to `typecheck`.

## Scoping a Quality Pass (TypeScript)

For the shared *Running a Quality Pass* → *Scope* step:

- **Change-scoped:** `bun run quality:audit` (`fallow audit` scopes its verdict to the changeset).
- **Path-scopable** (can target a directory): `oxlint <dir>`, `biome check <dir>`, `tsc --noEmit` (or the framework checker), `fta <dir> --json`.
- **Whole-project only** (cannot narrow to a directory): Fallow `dead-code` / `health` / cycles / duplication — read their output filtered to your scope.

## TypeScript-Specific Review Items

Add these to the shared *Review against the conventions* checklist:

- **TypeScript safety specifics:** no `any`, unsafe casts, or broad `unknown` assertions papering over real types; prefer optional chaining / nullish coalescing. See conventions → *Code Safety*.
- **Frontend & components:** reuse the shared/shadcn-style component layer before new markup, match the project styling approach, split large pages into cohesive components. See policy → *Frontend Styling and Component Policy*.

## Recording Legacy Debt (baseline mode)

When triage records pre-existing debt, this pack uses Fallow baselines and a temporary FTA `score_cap` — see [adoption-workflow.md](adoption-workflow.md).
