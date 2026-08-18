# Quality Gate Methodology

This is the language-neutral spine of the gate: the capabilities a gate must provide, how to roll it out, and the policies that hold regardless of language. It names no tools. Each language pack under `packs/<language>/` binds these capabilities to concrete tools, configs, and CI assets.

Read this first, then load the pack for the project's language.

Companion docs:
- [conventions.md](conventions.md) — the **opt-in** coding conventions (code safety, logging, error handling, constants/env, persistence, shared-contract principle, directory/cohesion/coupling, design principles). Language-neutral; each pack binds them to its tools.
- [development-usage.md](development-usage.md) — the language-neutral **implementation-phase** workflow (daily checks, before-finishing checks, the quality-pass process and review checklist). This sets up the gate; that one is how you work inside a gated project.
- [pack-authoring.md](pack-authoring.md) — the contract every language pack must satisfy (capability coverage, command vocabulary, threshold parity, CI set, `AGENTS.md` skeleton). Read it before authoring a new pack.

## The Capability Contract

A quality gate fills the same slots in every language. A pack supplies one concrete tool (and its config + CI wiring) per slot. The methodology and CI flow reason about *slots*, never about a specific tool.

| Capability | What it gates | Notes |
|------------|---------------|-------|
| Format | Consistent formatting and import/declaration ordering | Auto-fixable; runs on staged files in pre-commit. |
| Lint | Semantic correctness, unsafe patterns, maintainability rules (line/function length, complexity, nesting, parameter count) | The bulk of the per-file rule surface. |
| Type / strictness check | Static type safety at the strictest setting the language and framework allow | Whole-program; cannot be scoped to staged files, so it runs in pre-push and CI, not pre-commit. |
| Maintainability score | Per-file maintainability / cyclomatic complexity concentration | A measured score with a cap that drives the exit code. |
| Graph health / dead code | Repo-graph intelligence: dead code, unused exports/files/deps, circular dependencies, duplication, health score, hotspots | The only layer that sees cross-file structure; supports regression baselines. |
| Dependency freshness | Current vs latest dependency versions | Weekly visibility, not a PR blocker (see below). |
| Hooks | Fast local enforcement | Pre-commit = format + lint on staged files; pre-push = whole-program type check. |
| CI | The authoritative gate | Strict thresholds for new code; regression-only for baselined debt. |
| `AGENTS.md` | In-project conventions for future agents | Required setup output (see below). |

Not every language has a distinct tool for every slot — one tool may cover several (e.g. a single linter that also formats), or a slot may be covered by the type checker. The pack states which tool fills each slot and which slots are merged. A slot is never silently dropped; if a language has no good option, the pack says so explicitly.

## Mode Decision: Strict vs Baseline

This is the most important fork, and skipping it is the most common adoption mistake. Decide the mode **before** copying any config or running the gate.

- **New project, or an existing one that already passes the strict thresholds → strict mode.** Fail CI on the strict thresholds immediately. Fix every failure before merging.
- **Existing project with current quality debt → baseline (ratchet) mode.** Use the *same strict configs*. Absorb current debt into regression baselines (and any temporary maintainability-cap relaxation) so CI fails only on *new* regressions. Ratchet the debt down over time.

The failure mode to avoid: dropping strict configs into a brownfield repo, watching a wall of pre-existing issues fail, and then loosening the thresholds. That destroys the standard for all future code. Recognize the repo as legacy, keep the thresholds strict, and record the debt instead of waiving it.

If unsure which applies, run the gate locally once. A clean run means strict mode; a wall of pre-existing failures means baseline mode.

### Baseline mechanics (language-neutral)

"Baseline" means recording accepted current debt so CI only fails on regressions beyond it. Two shapes recur, and the pack states which tools use which:

- **Capped-score tools** have no baseline file — their only knob is a measured cap (e.g. a maximum maintainability score). Temporarily raise the cap to the current worst value, then lower it back toward the strict target as files are cleaned up.
- **Baseline-file tools** emit committed snapshot files of current findings and fail only on findings beyond the snapshot. Commit these; treat each as accepted debt, not a permanent ignore list.

Format, lint, and type checks generally have no baseline concept — absorb their debt by fixing it, scoping an override, or excluding a generated path.

## Gate by Boundary

Gate by deployable or package boundary:

- One deployable app or library → one gate at its root.
- Split frontend/backend → one gate per project root.
- Monorepo with multiple packages/apps → one gate per package/app that has its own scripts, dependency surface, and ownership.
- Integrated meta-framework app → one gate at the app root, even when it contains both server and client code.

Do not merge unrelated debt into one baseline. Do not split one integrated app into artificial sub-gates.

## Architecture Decision Records (ADRs)

Treat a project's existing ADRs as binding constraints, not background reading.

- **Respect recorded decisions.** If the project keeps ADRs (commonly under `docs/adr/`, `doc/adr/`, `architecture/decisions/`, or similar), read them before changing tooling, boundaries, or structure, and honor them in both Development and Setup/Audit Mode. An ADR that fixes a tool, boundary, or pattern overrides this skill's defaults — never silently contradict one. If a gate decision conflicts with an ADR, surface the conflict to the user rather than resolving it yourself.
- **Propose an ADR for critical decisions.** When the gate forces an architecture-relevant or hard-to-reverse choice — the deployable boundaries, accepting a large block of debt as baseline, swapping a tool the project already standardized on, relaxing a strictness policy — do not just make the call. Propose it to the user and offer to record it as an ADR, following the project's existing ADR format if it has one. Routine, mechanical choices do not need an ADR; reserve it for decisions a future maintainer would want the rationale for.

Make stale dependencies visible without pretending every repo can always jump to the latest version. Treat freshness as an operational signal, not a PR blocker:

- Run it on a weekly schedule, not on every pull request, by default.
- On that schedule, create or update a single tracking issue so dependency drift has an owner-visible backlog item.
- Prefer patch and minor updates first; handle majors as intentional migration work.
- Automated update PRs (Dependabot/Renovate) are opt-in; keep the lightweight native report even when they are enabled.

## Override Hygiene

Baselines and temporary cap relaxations are explicit debt decisions, never bypasses.

- Every exception documents the reason, scope, owner or tracking issue, and cleanup condition — in the config or the PR summary.
- Prefer scoped overrides over global threshold changes.
- Prefer generated-path excludes for generated files over relaxing global rules.
- CI must still fail on new regressions outside the accepted baseline.
- Remove baseline entries and lower temporary caps as the underlying debt is cleaned up. Do not let suppressions become permanent invisible debt.

## Generated and Framework Code

Exclude generated outputs explicitly rather than relaxing global gates. Keep the excluded paths enumerated and narrow, never a blanket loosening of a rule.

## Local Hooks vs CI

Keep local hooks fast and CI authoritative:

- **Pre-commit:** format + lint on *staged files only*. Never blanket-stage unrelated tracked changes in the hook.
- **Pre-push:** the whole-program type check, which cannot be scoped to staged files and so belongs off the per-commit hot path.
- **CI:** maintainability scoring, graph health, dependency freshness, and full audits. Do not run full repo-intelligence checks in hooks unless the project is small and the user explicitly opts into stricter local enforcement.

CI is the final authority; local checks exist to catch issues early, not to replace it.

## AGENTS.md Is a Required Output

Never finish a gate setup without creating or merging an `AGENTS.md` at each gated project root. It is the in-project entry point that loads into agent context on every task, names the project's real choices (logger, component/contract/persistence libraries, type-check command, strict vs baseline mode), and keeps future agents following the conventions without rediscovering this skill.

- Fill every placeholder with the project's real choice; delete guidance comments.
- Merge into an existing `AGENTS.md`; do not overwrite.
- Keep it short — prune anything that does not change how code is written here.

Each pack ships an `AGENTS.md` template tuned to its language.

## When Not to Use

Adopting or changing the gate is always an **explicit, requested action**. Loading this skill does not authorize modifying a project's linting, formatting, type-check, hook, or CI configuration. When the task is writing or changing feature code, follow the project's existing setup and run its existing checks — do not introduce or swap tools unasked. Apply gate configuration only when the user asks to set up, audit, or refine it.

- Quick one-off edits where standing up a gate is out of scope.
- Projects with an established toolchain the user wants kept — apply only the pieces they ask for; do not swap their linter, formatter, or test setup.
- Style and architecture *opinions* (the coding conventions in [conventions.md](conventions.md): directory layout, logging, error handling, SOLID heuristics, etc.) unless the project already follows them or the user opts into the house style. The mechanical gate (format, lint, strictness, maintainability, graph health, CI) is safe to apply broadly; coding conventions are not.
- Tests are out of the baseline unless the user explicitly asks for test gates.

## Adopting onto an Existing Toolchain

When a project already runs tools the user wants to keep, do not swap them. Adopt the gate by **capability slot, not by tool**:

- Keep the existing tool for each slot it already covers, and wire the canonical command names ([pack-authoring.md](pack-authoring.md) §2) to it, so CI and hooks call the same logical names regardless of the backing tool. CI's "verify the commands exist" step checks only the names, not which tool implements them.
- Add only the capability slots the project is missing — usually the structural ones (maintainability, graph health / dead code, dependency freshness), which compose with any formatter, linter, or type checker.
- Decide per slot, and surface what you are and are not changing. Replacing a tool the project already uses requires an explicit request from the user.

Each pack lists the concrete substitutions for its language.
