# Adoption Workflow

Use strict mode for new repositories and baseline mode for existing repositories with current quality debt.

Decide the mode **before** copying configs or running the gate. The common adoption failure is to drop the strict configs into a brownfield repository, run the gate, watch hundreds of pre-existing issues fail, and then start loosening thresholds. That destroys the standard. Instead, recognize the repository as legacy, keep the strict configs, and absorb the current debt through the baseline mechanisms below so CI fails only on regressions. The thresholds stay strict; the debt is recorded, not waived.

## Baseline Model

This pack has three baseline-file tools and no capped-score tool. Each is committed and treated as accepted debt, not a permanent ignore list:

- **basedpyright** uses `.basedpyright/baseline.json`, generated with `basedpyright --writebaseline`. It is consumed **automatically** — basedpyright is baseline-aware locally and in CI — and it **shrinks automatically** as baselined errors are removed from the code. This is the closest thing to a true type-error ratchet; plain Pyright has none.
- **Vulture** uses a committed `whitelist.py`, generated with `vulture src --make-whitelist > whitelist.py` and consumed by passing it on the command line (`vulture src whitelist.py`).
- **Bandit** uses a committed `.bandit-baseline.json`, generated with `bandit -c pyproject.toml -r src -f json -o .bandit-baseline.json` and consumed with `bandit -b .bandit-baseline.json`.

Ruff and tach have **no baseline concept**: accept their debt by fixing it, scoping a `noqa`/override with a reason, or excluding a generated path. For tach specifically, mark known-legacy modules `unchecked = true` or a dependency `deprecated = true` in `tach.toml` rather than turning off cycle detection globally.

## New Projects

1. Classify the repository topology with [project-topologies.md](project-topologies.md).
2. Copy `mise.toml`, `tach.toml`, `.pre-commit-config.yaml`, and `.editorconfig` to each project boundary that should be gated independently; merge the `[tool.*]` tables from `pyproject.quality.toml` into each project's `pyproject.toml`.
3. Install the toolchain and dev dependencies (`mise install`; `uv add --dev ruff basedpyright tach vulture bandit pre-commit`; `uv sync`).
4. Install the Git hooks (`uv run pre-commit install` and `… install --hook-type pre-push`).
5. Configure module boundaries (`uv run tach mod` then `uv run tach sync`).
6. Confirm every `mise run <task>` used by the selected workflow exists in the target `mise.toml`.
7. Add `assets/github/quality-gate-strict.yml` for one project, or `assets/github/quality-gate-project-matrix.yml` for multiple separately gated project roots.
8. Add a dependency freshness workflow (single-project or project-matrix variant).
9. Create or merge an `AGENTS.md` at each gated project root from `assets/agents/AGENTS.md`, filling placeholders with the project's real conventions and `strict` as the mode.
10. Run `mise run quality` in each gated project.
11. Fix all failures before merging the baseline.

## Existing Projects

1. Classify the repository topology with [project-topologies.md](project-topologies.md).
2. Copy config assets to each gated boundary and merge the `[tool.*]` tables into each `pyproject.toml`.
3. Start with the strict config files, but do not lower the policy thresholds globally.
4. Install the toolchain, dependencies, and hooks; configure tach modules.
5. Run the full gate locally in each gated project and inspect failures:

   ```fish
   mise run quality
   ```

6. If the current repository fails because of existing debt, create baselines inside each gated project:

   ```fish
   uv run basedpyright --writebaseline
   uv run vulture src --make-whitelist > whitelist.py
   uv run bandit -c pyproject.toml -r src -f json -o .bandit-baseline.json
   ```

   Review each generated baseline before committing — a whitelist entry or suppressed type error is accepted debt, so do not blindly accept findings that are real bugs you could fix now. If the codebase has no security findings, skip generating `.bandit-baseline.json` rather than committing an empty one.
7. Confirm every `mise run <task>` used by the selected workflow exists in the target `mise.toml`.
8. Add `assets/github/quality-gate-baseline.yml` for one project, or `assets/github/quality-gate-project-matrix-baseline.yml` for multiple separately gated project roots.
9. Add a weekly dependency freshness workflow (single-project or project-matrix variant).
10. Create or merge an `AGENTS.md` at each gated project root, filling placeholders with the project's real conventions and `baseline` as the mode.
11. Commit `.basedpyright/baseline.json`, `whitelist.py`, and `.bandit-baseline.json` per project. Treat each baseline as accepted debt.
12. Make CI fail on regressions with zero tolerance unless the user explicitly chooses a small temporary tolerance.

## Local Checks in Baseline Mode

The shipped `quality:deadcode` and `quality:security` tasks are **baseline-blind**: they run `vulture src` and `bandit -c pyproject.toml -r src` with no baseline flags. On a brownfield repo they report pre-existing debt, so `mise run quality` **fails locally even when baseline CI is green**. This is expected, not a misconfiguration — the baseline CI workflow (`quality-gate-baseline.yml`) replaces those two steps with baseline-aware invocations:

```fish
uv run vulture src whitelist.py
uv run bandit -c pyproject.toml -b .bandit-baseline.json -r src
```

`typecheck` is the exception: basedpyright consumes the committed `.basedpyright/baseline.json` automatically, so `mise run typecheck` is already regression-only in both places.

So in baseline mode:

- **CI is the authority for the regression verdict.** Do not loosen thresholds or delete baseline entries to make local `mise run quality` pass on legacy debt — that is the exact mistake the baseline mechanism exists to prevent.
- **To check your change locally against the committed baselines,** run the two baseline-aware commands above (drop any baseline file you did not generate). This is the local equivalent of the CI gate.
- `mise run quality:deadcode` / `quality:security` remain useful as strict, baseline-blind sweeps when you deliberately want to see total debt — just expect them to fail on legacy findings.

## Tightening Baselines

When debt is removed, regenerate the Vulture whitelist and Bandit baseline on the protected branch. basedpyright shrinks its baseline automatically; commit the smaller file. Lower the accepted-debt surface over time toward zero.

## Override Hygiene

- Prefer scoped overrides over global threshold changes.
- Prefer tach `unchecked`/`deprecated` module flags with a reason for known legacy modules over disabling cycle detection.
- Prefer generated-path excludes for generated files.
- Keep `noqa`/`# type: ignore`/`# nosec` suppressions scoped and commented; remove them as the underlying issue is fixed. No tool reports stale Python suppressions, so this is a review responsibility.
