# TypeScript Quality Gate Policy

The TypeScript **bindings** for this gate: the concrete tools, thresholds-as-config, lint-rule coverage, and TS-specific mechanics. The language-neutral pieces live in the shared docs and are not restated here:

- Mechanical contract, modes, baseline mechanics, boundaries, freshness-as-visibility, hook split, override hygiene → [methodology.md](../../references/methodology.md).
- Coding conventions (code safety, logging, error handling, constants/env, persistence, shared-contract principle, directory/cohesion/coupling, design principles) → [conventions.md](../../references/conventions.md).

Use these defaults as strict recommendations for new TypeScript projects. Relax only when a framework or generated code path makes a rule noisy, and scope the override to that path.

## Tool Responsibilities

- Bun: package manager and script runner.
- Biome: formatting, JSON/JS/TS style normalization, and import organization.
- oxlint: fast semantic linting and strict maintainability rules. Use `oxlint-tsgolint` with type-aware rules and experimental `typeCheck`.
- TypeScript: strict compiler options plus `tsc --noEmit` or the framework's equivalent type checker.
- FTA: per-file maintainability score, cyclomatic complexity, Halstead metrics, and line concentration. **Capped-score** baseline shape (`score_cap`, no baseline file).
- Fallow: repo graph intelligence — dead code, dependency hygiene, circular dependencies, duplication, health score, hotspots. **Baseline-file** shape (`fallow-baselines/*.json`).
- Bun outdated: dependency freshness reporting for current, update, and latest package versions.
- Husky: fast pre-commit formatting and linting on staged files via `lint-staged`; whole-program type checking runs on pre-push.
- GitHub Actions: authoritative quality gate.

## Recommended Thresholds

These realize the shared threshold-parity targets ([pack-authoring.md](../../references/pack-authoring.md) §3) as TS tool config:

- File length: 400 physical lines through `eslint/max-lines`.
- Function length: 75 physical lines through `eslint/max-lines-per-function`.
- Cyclomatic complexity: 10 through oxlint `eslint/complexity`; Fallow health should use `--max-cyclomatic 10`.
- Cognitive complexity: 12 through Fallow health `--max-cognitive 12`.
- Nesting depth: 4 through oxlint `eslint/max-depth`.
- Parameter count: 5 through oxlint `eslint/max-params`.
- FTA score cap: 50 for new projects; lower is better, and FTA considers scores below 50 maintainable.
- Fallow project health score: minimum 85 for new projects. Gate with the native `fallow health --min-score 85` flag, which drives the exit code, instead of parsing JSON by hand.
- Fallow severity gate: fail on high or critical health findings through the native `fallow health --min-severity high` flag. It composes with `--min-score`, so the run fails if either gate trips.
- Test files: exclude from FTA scoring and Fallow health complexity thresholds, but keep visible to Fallow dead-code and dependency analysis so test-only dependencies, stale test helpers, and unlisted test dependencies surface as review signals.
- Coverage gaps: keep Fallow's `coverage-gaps` rule at `warn`, not its default `error`. `coverage-gaps` drives the `fallow health` exit code independently of the health score, so at `error` a project with no test layer fails `quality:health` even with a perfect score of 100. This baseline ships no test gates, so `warn` keeps coverage gaps visible without blocking CI. Promote it to `error` only after adopting a test layer.
- Circular dependencies: error.
- Unresolved imports and unlisted dependencies: error.
- Stale suppressions: error.
- Dependency freshness: create or update a weekly issue; do not report on every PR by default.
- TypeScript strictness: use `strict`, `noEmit`, `exactOptionalPropertyTypes`, `noUncheckedIndexedAccess`, `noImplicitOverride`, `noImplicitReturns`, `noFallthroughCasesInSwitch`, `useUnknownInCatchVariables`, `forceConsistentCasingInFileNames`, and `isolatedModules`.

## Baseline Exceptions

Use baselines and temporary FTA caps as explicit debt decisions, not as bypasses. Existing projects may add known current debt to Fallow baselines or temporarily raise an FTA `score_cap` when immediate cleanup is unrealistic. New projects should avoid baselines except for generated code, framework artifacts, confirmed false positives, or a staged migration that is already tracked.

Every exception must document the reason, scope, owner or tracking issue, and cleanup condition. CI must still fail on new regressions outside the accepted baseline. Remove baseline entries and lower temporary FTA caps as soon as the underlying debt is cleaned up.

## Fallow Warning Policy

Fallow `warn` rules are intentional review signals. They surface in Fallow CLI output summaries and job logs with a lower displayed severity than `error` rules. How much the `warn`/`error` distinction affects the exit code depends on the command:

- `fallow dead-code --fail-on-issues` (the `quality:fallow` gate) fails on **any** enabled finding regardless of level. `dead-code` has no `--min-severity` knob, so `warn` and `error` rules block CI identically here; the only non-blocking level for this gate is `off`. Set a `dead-code` rule to `off` (not `warn`) when it must never fail CI, and rely on the displayed severity plus reviewer discipline for the `warn` rules you keep enabled.
- `fallow health --min-severity high` (the `quality:health` gate) genuinely respects severity and only fails on findings at or above the configured level, so `warn`-level health findings stay advisory.
- `fallow audit` (the `quality:audit` gate) scopes its verdict to findings introduced by the changeset; inherited findings are reported but do not fail the verdict unless `--gate all` is used.

So the `warn` levels in `.fallowrc.json` shape Fallow's output and the `health`/`audit` exit codes, but they do **not** soften the `dead-code` gate. Choose `warn` vs `off` for dead-code rules with that in mind.

Use `warn` for findings that need human context:

- exported APIs that may be consumed externally
- framework, decorator, ORM, or serialization-driven class members
- optional dependencies that may be runtime/environment dependent
- enum members that may be persisted, externalized, or used by feature flags
- test-only dependency signals while test gates are out of scope

Require a human reviewer to decide whether each warning should be fixed, accepted as intentional, scoped with an override, or promoted to `error` for that repository. If a warning type is repeatedly real in a project, promote it to `error`; if it is repeatedly noisy because of a framework or generated pattern, prefer scoped ignores over global `off`.

## Convention Bindings (TypeScript)

How the shared [conventions.md](../../references/conventions.md) map onto TypeScript enforcement. Where a rule exists, the convention is part of the mechanical `lint`/`typecheck` gate; otherwise it is an `AGENTS.md` + review convention.

| Convention | TypeScript enforcement |
|---|---|
| No untyped escape hatch | `tsconfig` `strict` (+ no broad `any`); boundary `any` only, deliberately isolated |
| Logging (no ad-hoc stdout) | oxlint `eslint/no-console` (error; scoped overrides for scripts/CLIs/migrations/config) |
| Throw only error types, preserve cause, narrow caught | oxlint `typescript/only-throw-error`, `eslint/no-throw-literal`, `eslint/preserve-caught-error`, `eslint/no-unsafe-finally`, `typescript/use-unknown-in-catch-callback-variable`, `oxc/missing-throw` |
| No param reassignment | oxlint `eslint/no-param-reassign` (scoped overrides for migrations/scripts) |
| Strict equality, no debugger/eval | oxlint `eslint/eqeqeq`, `eslint/no-debugger`, `eslint/no-eval`, `eslint/no-constant-condition` |
| Prefer immutable bindings | oxlint `eslint/prefer-const` |
| Exhaustive closed-set handling | oxlint `typescript/switch-exhaustiveness-check` |
| No unsafe async | oxlint `typescript/await-thenable`, `typescript/unbound-method` (+ floating-promise discipline) |
| No lossy coercions | oxlint `typescript/no-array-delete`, `no-base-to-string`, `no-misused-spread`, `require-array-sort-compare`, `restrict-template-expressions`, `eslint/no-unsafe-optional-chaining` |
| Prefer optional chaining / nullish coalescing | oxlint `typescript/prefer-optional-chain`, `typescript/prefer-nullish-coalescing` |
| Constants/env | typed config accessors over raw `process.env`; client-public values only behind `VITE_`/`NEXT_PUBLIC_`/`NUXT_PUBLIC_` |
| Reuse over reinvention | review/`AGENTS.md` (not lint-enforceable); check npm for a maintained package before building non-trivial functionality |

Items the linter cannot yet enforce (review/`AGENTS.md` only): no nested ternaries, no accumulating object/array spread in loops, prefer type-only imports, and member ordering (public before private). See *Oxlint Rule Coverage* for rules deliberately left out.

## Oxlint Rule Coverage

Enable oxlint with `options.typeAware: true`, `options.typeCheck: true`, `oxlint-tsgolint`, and the relevant built-in plugins. Keep the `vue` plugin enabled so Vue and Nuxt projects receive Vue-specific lint coverage.

Type-aware linting hard-requires the `oxlint-tsgolint` binary; without it, a bare `oxlint` run aborts with a `Failed to find tsgolint executable` error, so keep `oxlint-tsgolint` in dev dependencies. `options.typeCheck` (full type checking inside the linter) is experimental and slower than plain `typeAware`. If a project sees instability or noticeable slowdowns, drop `typeCheck` and keep `typeAware`, relying on `tsc --noEmit` (or the framework type checker) for full type checking.

Do not enable Jest/Vitest plugins in this baseline. Tests are handled by a separate quality layer and should not make non-test project setup noisy.

The error-level rules backing the *Convention Bindings* table above are the recommended direct rules: `eslint/no-console`, `eslint/eqeqeq`, `eslint/no-debugger`, `eslint/no-eval`, `eslint/no-constant-condition`, `eslint/no-param-reassign`, `eslint/no-throw-literal`, `eslint/no-unsafe-finally`, `eslint/no-unsafe-optional-chaining`, `eslint/no-useless-catch`, `eslint/preserve-caught-error`, `eslint/prefer-const`, `eslint/use-isnan`, `eslint/valid-typeof`, `oxc/missing-throw`, and the `typescript/*` rules listed above.

Rules that are not currently native oxlint rules should not be copied blindly into `.oxlintrc.json`:

- `@nrwl/nx/enforce-module-boundaries`: keep Nx's ESLint rule only in Nx repositories that already use Nx ESLint, or model boundaries through scoped `no-restricted-imports` plus Fallow architecture checks.
- `@typescript-eslint/member-ordering`: document the convention in review guidance; do not block with oxlint until native support exists.
- `@typescript-eslint/naming-convention`: prefer TypeScript naming through code review and project conventions; do not block with oxlint until native support exists.
- `etc/no-commented-out-code`: prefer Fallow/stale suppression hygiene and review discipline; do not add an unsupported plugin to the baseline.
- `lodash-fp/use-fp`: avoid as a default rule because most projects should not be forced into lodash-fp. Add only for repositories that intentionally standardize on lodash-fp.

## Shared Contracts (TypeScript bindings)

The principle — define a contract once, validate boundary data at runtime, keep contract files small and domain-oriented — is in [conventions.md](../../references/conventions.md) → *Shared Data Contracts*. The TypeScript mechanics:

- **Existing projects:** follow the project's current validation/contract library (Zod 3, Valibot, ArkType, io-ts). The schema-and-`infer` pattern applies regardless; Zod specifics are the greenfield default, not a mandate.
- For greenfield, use Zod (v4 preferred) schemas and derive types with `z.infer` so frontend and backend share one source. Export inferred types, e.g. `type CreateUserRequest = z.infer<typeof CreateUserRequestSchema>`.
- In split frontend/backend repos, prefer a dedicated `shared` package/directory for contracts. Add `zod` as a runtime dependency of that package when both sides import it.
- Keep shared contracts under their own gate when they have their own `package.json`, or include them in the nearest repo gate when they are a plain shared directory.
- Gate contracts with the contract formatter, the contract linter, and `typecheck` only — add dependency freshness when contracts are their own package. Do **not** add `fta.json` or a dedicated `.fallowrc.json` for a contracts boundary: FTA scores declarative schemas near zero (no signal), and Fallow treats `*.contract.ts`/`*.schema.ts` exports as public-API entry points, so its unused-export detection does not apply. A plain contracts directory is already swept by the project-root FTA/Fallow gate.
- Use `biome.contracts.json` for focused contract formatting and `.oxlintrc.contracts.json` for focused contract linting; run `quality:contracts` in pre-commit (`lint-staged`) and CI.
- The structural gate is the contract linter's hard size caps: 200 physical lines per contract file and 80 physical lines for aggregate files named `api.ts`, `contracts.ts`, or `schemas.ts`. Override only in the project-local contract oxlint config with a documented reason.
- Prefer domain-oriented paths such as `shared/src/contracts/customer/create-customer.contract.ts`.

## Frontend Styling and Component Policy

**Existing projects:** match the project's current styling approach (CSS Modules, vanilla-extract, styled-components, Panda, etc.). Do not migrate a project to Tailwind as a side effect of this gate. The Tailwind-first guidance below is the greenfield default.

For greenfield frontend work, use Tailwind utility classes by default. Do not introduce regular CSS, CSS modules, scoped style blocks, or component-specific stylesheet files unless the user explicitly asks for regular CSS or the framework needs a narrow global file for Tailwind imports, resets, fonts, tokens, or truly global browser behavior.

Styling expectations (when the project uses Tailwind):

- Prefer Tailwind utilities and existing design tokens over custom CSS declarations.
- Keep global CSS files small and infrastructural. They should not become a place for feature-level component styling.
- Use regular CSS only with an explicit reason in the PR summary or implementation notes.
- Prefer variant helpers, component props, and `className`/class binding composition over one-off stylesheet classes.
- Do not duplicate a design system in ad hoc CSS when Tailwind tokens or existing component variants already cover the need.

Component expectations:

- Reuse existing shared components before creating new markup. Look for local design-system components, self-written shared components, and shadcn/ui-derived components.
- Restyle or extend an existing component for a special use case before falling back to plain HTML elements.
- If no shared component exists for a repeated UI pattern, propose creating a reusable component so future frontend work stays unified.
- Build pages and complex sections from smaller cohesive components. Do not put a whole page, form, table workflow, or dashboard into one large component file.
- Split by responsibility: container/data loading, layout section, reusable presentational component, form fields, table/list item, empty/error/loading states, and action controls.
- Keep components domain-oriented and readable. Avoid generic component names that hide intent, and avoid catch-all `components.tsx` files.
- Plain HTML elements are fine inside low-level shared components, but feature/page code should prefer the shared component layer for buttons, inputs, dialogs, cards, tabs, menus, tables, alerts, and form controls.

## Generated and Framework Code

Exclude generated outputs rather than relaxing global gates. Typical exclusions include `dist`, `build`, `.next`, `.nuxt`, `.svelte-kit`, `coverage`, generated route trees, GraphQL generated clients, API clients, and migration snapshots. Keep generated paths explicit.

## Package Scripts

Use `bun run` in composed scripts; expose the canonical command names ([pack-authoring.md](../../references/pack-authoring.md) §2). Prefer existing framework-specific commands when present:

- Angular: keep `ng build` or `ng typecheck` equivalents if already configured.
- Nuxt: prefer `nuxt typecheck` or `bunx nuxt typecheck` over plain `tsc --noEmit`.
- Vue: prefer `vue-tsc --noEmit` when present.
- Svelte: prefer `svelte-check` when present.
- Next.js/React packages: `tsc --noEmit` is usually enough unless the project already has a stronger validation script.

Do not add test commands to `quality` in this skill. Tests are covered separately.

## Local Hooks

Keep local Git hooks fast and focused (the pre-commit/pre-push split rationale is in [methodology.md](../../references/methodology.md) → *Local Hooks vs CI*):

- Pre-commit: run `lint-staged` so the formatter and linter touch only the staged files. `lint-staged` stashes unstaged changes during the run and re-stages only the files it modified, so partial stages and unrelated working-tree edits are never swept into the commit. Never use a blanket `git add -u` in the hook, which would stage unrelated tracked changes.
- Pre-push: run the whole-program type check (`tsc --noEmit` or the framework checker). Type checking cannot be scoped to staged files, so it belongs off the per-commit hot path.
- FTA, Fallow, dependency freshness, and full audit checks belong in GitHub Actions by default.

Do not run full repository intelligence checks in hooks unless the project is small and the user explicitly chooses stricter local enforcement.

### lint-staged glob safety

`lint-staged` matches with picomatch: a glob **without a `/`** (e.g. `*.{ts,json,vue}`) matches the file *basename in any directory*, not just the project root. In a repo that contains nested tool assets — `.agents/`, `.codex/`, `.claude/`, `.cursor/`, vendored examples, templates, or any generated workspace — a slashless glob will pick up nested files such as `.agents/skills/<skill>/assets/configs/biome.json`. Biome then sees that nested `biome.json` as a second root configuration and fails the commit with `Found a nested root configuration, but there's already a root configuration`. This is the same class of failure that breaks `format`, `format:check`, and `quality`, because `biome ci .` / `biome check --write .` discover nested `biome.json` files anywhere in the tree.

This baseline defends against it on two levels, and you must preserve both:

- The shipped `biome.json` and `biome.contracts.json` exclude `.agents`, `.codex`, `.claude`, and `.cursor` from `files.includes`, so Biome never discovers a nested config there. `.oxlintrc.json`, `fta.json`, and `.fallowrc.json` exclude the same directories. Add any project-specific nested-config directories (vendored examples, templates, generated workspaces) to these same exclusions.
- The `lint-staged` Biome commands pass `--no-errors-on-unmatched` so that when a staged batch resolves to only-excluded or unknown files, the hook still exits 0 instead of failing.

Do not format or lint `.agents/**`, `.codex/**`, skill/plugin assets, or generated templates in pre-commit unless that is explicitly intended. Prefer slash-qualified, project-owned patterns (for example `{frontend,backend,shared}/**/*.{ts,tsx,vue}`) over broad slashless globs whenever the repository's deployable roots are known.

## TypeScript Configuration

Use `assets/configs/tsconfig.quality.json` as the strictness baseline. For plain TypeScript packages, extend it from the project `tsconfig.json`. For framework-managed projects, merge the compiler options that do not conflict with the framework's generated or prescribed config.

Do not replace framework `tsconfig` files blindly. Preserve framework `extends`, path aliases, generated includes, project references, JSX settings, module resolution, target, module, and library settings.

Keep `noUnusedLocals` and `noUnusedParameters` out of the default baseline. Let oxlint catch local unused values and Fallow catch unused exports, files, and dependencies; enable TypeScript unused checks only when the project team wants compiler-level enforcement for that repository.

## Dependency Freshness

Use `bun outdated` to make stale dependencies visible (the weekly-visibility-not-a-blocker policy is in [methodology.md](../../references/methodology.md) → *Dependency Freshness as Visibility*). Run it weekly, create/update one tracking issue, prefer patch/minor first and treat majors as migration work. Use Dependabot or Renovate only when the team wants automated update PRs; keep the Bun report even then, because it is lightweight and package-manager-native.
