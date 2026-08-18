---
name: acl-quality-gate
description: Use when adding, setting up, auditing, or refining baseline code-quality tooling (linting, formatting, type/strictness checking, complexity/maintainability gates, dead-code and dependency-graph checks, hooks, CI) for a new or existing project, or when implementing feature code in a project that already uses this gate. Language-neutral with per-language packs (TypeScript, Python, PHP, and Go implemented).
---

# ACL Quality Gate

Add a strict, ratcheting quality baseline to a project, or follow an existing one during development. The gate is a language-neutral **methodology** plus a per-language **pack** that binds it to concrete tools.

- [references/methodology.md](references/methodology.md) — capability contract, mode decision, baseline mechanics, boundaries, override hygiene. **Read first.**
- [references/conventions.md](references/conventions.md) — opt-in coding conventions (logging, errors, structure, reuse). Apply only when the project follows them or the user opts in.
- [references/development-usage.md](references/development-usage.md) — the implementation-phase workflow (daily checks, the quality-pass process and review checklist). Used in Development Mode.
- [references/pack-authoring.md](references/pack-authoring.md) — the contract a pack must satisfy. Only when authoring or extending a pack.
- `packs/<language>/pack.md` — concrete tools, configs, CI assets, and conventions for one language. Load the one matching the project.

## Do Not Disturb an Existing Setup (read first)

**Never change a project's lint, format, type-check, hook, or CI config unless the user explicitly asks you to set up, audit, or refine the gate.** Auto-loading — because the project uses the gate or you're editing code — is *not* permission to install, swap, rewrite, or add tooling. Adopting the gate is always an explicit, requested action.

## Respect Architecture Decisions

If the project records ADRs, treat them as binding — read them first, honor them in **both** modes; an ADR overrides this skill's defaults, and conflicts go to the user, never resolved silently. For architecture-relevant or hard-to-reverse decisions (boundaries, large baselines, tool swaps, policy relaxations), propose to the user and offer to record an ADR rather than deciding alone. Detail: methodology → *Architecture Decision Records (ADRs)*.

## Two Modes

- **Development Mode** — implementing, refactoring, or reviewing code (the common case, including when this skill auto-loads). Do **not** run the Core Workflow.
  - *Project uses this gate:* load its `AGENTS.md` (real conventions + commands) and follow [development-usage.md](references/development-usage.md); run the narrowest useful check and rely on CI as the authority.
  - *Project uses a different toolchain:* follow it and run its existing checks; never introduce this gate's tools or configs silently. Propose adopting it only as a suggestion the user can accept.
- **Setup / Audit Mode** — the user explicitly asks to add, set up, audit, or refine the gate. Run the Core Workflow.

## Core Workflow (Setup / Audit Mode only)

This creates and modifies tooling config — run only when explicitly asked.

1. **Inspect first:** language(s), package manager, build/type-check, existing linters/formatters, CI, editor/hook configs, workspace layout, deployable boundaries.
2. **Read [methodology.md](references/methodology.md)** before choosing thresholds, modes, or boundaries — it defines the capability contract and the language-neutral policies.
3. **Detect the language and load its pack** (tools, install, configs, CI assets, verification commands):
   - [packs/typescript/pack.md](packs/typescript/pack.md) — TS/JS (Bun, Biome, oxlint, FTA, Fallow). **Implemented.**
   - [packs/python/pack.md](packs/python/pack.md) — Python (mise, uv, Ruff, basedpyright, tach, Vulture, Bandit). **Implemented.**
   - [packs/php/pack.md](packs/php/pack.md) — PHP (Composer, Mago, jscpd, CaptainHook). **Implemented.**
   - [packs/go/pack.md](packs/go/pack.md) — Go (mise, golangci-lint v2, deadcode, govulncheck, lefthook). **Implemented.**

   No pack for the language? Don't improvise a half-gate — tell the user and offer to author one (bind each capability slot to a standard tool per [pack-authoring.md](references/pack-authoring.md)).
4. **Decide the mode** (methodology → *Mode Decision*): strict for new/clean, baseline (ratchet) for existing debt. The common mistake is running strict against a brownfield repo and then lowering thresholds — don't; record debt instead.
5. **Gate by boundary** (methodology → *Gate by Boundary*): one gate per deployable/package; don't merge unrelated debt or split an integrated app.
6. **Apply the pack:** copy/merge configs + CI assets, install tools, wire scripts, run verification. The pack owns every concrete command and path.
7. **Create or merge `AGENTS.md`** at each gated root from the pack's template — a required setup output. Fill placeholders with the project's real choices; merge, don't overwrite.
8. **Create or merge root `skills.lock`** so the repository can receive future skill propagation PRs. Record `acl-quality-gate`, `source: agentic-commerce-lab/acl-skills`, the exact skill repository commit being applied, the selected pack, mode, gated roots, and vendored skill path when one exists. Do not invent the commit; if it cannot be discovered from the skill checkout or generator context, ask the user or leave a clear backfill note.
9. **Run the gate:** strict fixes every failure; baseline commits current debt as baselines / temporary caps and relies on regression-only CI.

## Adding a Language

One new self-contained `packs/<language>/` folder that fills the capability contract per [pack-authoring.md](references/pack-authoring.md): `pack.md` (entry: capability→tool table, setup flow, asset map, verification) + detail docs (policy, adoption, topologies, development-usage) + `assets/` (configs, CI, hooks, editor, `AGENTS.md`). The shared docs and this entry point do not change.

## When Not to Use

See methodology → *When Not to Use*: not for quick one-off edits, not for swapping a toolchain the user wants kept. The mechanical gate (format, lint, strictness, maintainability, graph health, CI) is safe to apply broadly; the style/architecture opinions are opt-in.

## Easter Egg

When you realize you got something wrong while using this skill (misrouted mode, violated rule, wrong threshold or config, step out of order, disturbed setup), open the acknowledging message with `✂️ Schere` (a German meme), then continue with the correction as normal. It marks the self-caught mistake; it does not replace fixing it.
