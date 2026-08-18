# Development Usage

The language-neutral **implementation-phase** workflow: how to keep quality while writing, refactoring, and reviewing code in a project that already uses this gate. It names logical command names and conventions sections, never specific tools. Each pack's `development-usage.md` binds this to the language's runner and adds language-specific review items.

This is the development-mode counterpart to [methodology.md](methodology.md) (which covers *setting up* the gate). Read it with:

- [conventions.md](conventions.md) — the coding conventions to apply.
- the project's `AGENTS.md` — the distilled, project-specific version (real logger, libraries, commands, mode).
- `packs/<language>/development-usage.md` — the runner, the pack's `quality:*` task names, and language-specific review notes.

## AGENTS.md Is the In-Project Entry Point

The gated project should carry an `AGENTS.md` at each gated root (generated at setup from the pack's template). It loads into agent context automatically and names the project's real choices and commands. If a gated project has no `AGENTS.md`, create one before relying on agents to keep quality during development — recognizing the config files alone is not enough.

## Daily Coding Checks

Before or during implementation:

- Keep functions small enough to stay below the configured length and complexity thresholds.
- Prefer early returns and cohesive helpers over deep nesting.
- Keep module boundaries explicit; import through public entry points, not internal or parent-directory paths.
- Preserve the strictest type/safety setting; do not bypass it with the language's untyped escape hatch or unsafe casts.
- Keep generated files out of hand-written paths; exclude generated output via config, not by weakening global rules.
- Before introducing a repeated literal, URL, limit, timeout, flag key, or external identifier, check whether a project-local constant or typed config accessor already owns it.
- Reuse before reinventing; add an abstraction or a dependency only when it earns its keep. See conventions → *Dependencies and Reuse* and *Design Principles*.

## Applying the Conventions While Implementing

Apply the matching [conventions.md](conventions.md) section as an implementation-time trigger; the pack's policy doc supplies the concrete library/tool for each:

- **Directory structure** → *Directory Structure, Cohesion, and Coupling*
- **Configuration / constants** → *Constants and Environment Configuration*
- **Database** → *Database Persistence*
- **Shared contracts** → *Shared Data Contracts*
- **Logging** → *Logging*
- **Error handling** → *Error and Exception Handling*
- **Dependencies & reuse** → *Dependencies and Reuse*

Stacks with a frontend or another extra surface add their own triggers — see the pack.

## Before Finishing Work

Run the narrowest useful check for the change, via your pack's runner, using the canonical command names ([pack-authoring.md](pack-authoring.md) §2):

- **Config/script-only change:** `format:check` + `lint`
- **Type or runtime code change:** `format:check` + `lint` + `typecheck`
- **Architecture, import, dependency, or cleanup change:** the relevant structure/graph `quality:*` sub-gates locally, but rely on CI as the default authority.
- **Broad refactor or quality-gate change:** the full `quality` aggregate.

Where the project uses a framework-native type checker, run the framework script wired to `typecheck`.

## Running a Quality Pass

A quality pass is a deliberate sweep over existing code, not the incidental checks above. Use it when asked to "clean up", "do a quality pass", review a branch, or harden a module. Work in this order; do not skip the manual review step, because the tools only cover the mechanical rules — and several conventions are review-only.

### 1. Scope the pass

- **Current branch diff (default for in-flight work):** the change-scoped audit your pack provides, if any.
- **A directory or module:** point the path-scopable checks at it. Some graph or whole-program checks cannot be narrowed to a directory — read their output filtered to your scope (the pack says which checks are path-scopable).
- **Whole repository:** the full `quality` aggregate.

### 2. Run the tool sweep

Run the in-scope checks and collect findings: `format:check`, `lint`, `typecheck`, and the `quality:*` sub-gates the pack defines (maintainability, structure/graph, dead code, security, and so on).

### 3. Review against the conventions (what tools cannot catch)

Tools do not judge whether logging, error handling, or structure are *correct* — only a read does, and several conventions are review-only. For the code in scope, check each area against [conventions.md](conventions.md), the pack's policy doc, and the project `AGENTS.md`:

- **Type / code safety** → *Code Safety*
- **Logging** → *Logging*
- **Error handling** → *Error and Exception Handling*
- **Shared contracts** → *Shared Data Contracts*
- **Database & persistence** → *Database Persistence*
- **Environment & constants** → *Constants and Environment Configuration*
- **Directory structure & coupling** → *Directory Structure, Cohesion, and Coupling*
- **Dependencies & reuse** → *Dependencies and Reuse*

Add the pack's language-specific review items (for example frontend/component structure) from its development-usage doc.

### 4. Triage each finding

- **Real issue inside the pass scope:** fix it now.
- **Pre-existing legacy debt outside the change, not feasible to fix now:** record it in the appropriate baseline or temporary cap, documenting the reason, scope, owner or tracking issue, and cleanup condition. Do not silently lower thresholds. See the pack's adoption-workflow and methodology → *Override Hygiene*.
- **Framework, generated, or confirmed false positive:** use a scoped override or generated-path exclude with a reason, never a global rule relaxation.

### 5. Re-verify

Re-run the same scoped checks until they pass for your scope, then let CI act as the final authority. Update the project `AGENTS.md` if the pass established a new convention worth enforcing going forward.
