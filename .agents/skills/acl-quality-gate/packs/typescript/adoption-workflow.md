# Adoption Workflow

Use strict mode for new repositories and baseline mode for existing repositories with current quality debt.

Decide the mode **before** copying configs or running the gate. The common adoption failure is to drop the strict configs into a brownfield repository, run the gate, watch hundreds of pre-existing issues fail, and then start loosening thresholds. That destroys the standard. Instead, recognize the repository as legacy, keep the strict configs, and absorb the current debt through the baseline mechanisms below so CI fails only on regressions. The thresholds stay strict; the debt is recorded, not waived.

## Baseline Model

"Baseline" means two different things in this setup, and they are not interchangeable:

- **FTA has no baseline file.** Its only knob is the measured `score_cap` in `fta.json`. To accept current FTA debt you temporarily raise `score_cap` to the highest failing score; there is no separate FTA baseline artifact to commit. Lower the cap back toward `50` as files are cleaned up.
- **Fallow uses real baseline files** under `fallow-baselines/` (`dead-code.json`, `health.json`, `dupes.json`), produced with `--save-baseline` and consumed by `fallow audit` via `--dead-code-baseline` / `--health-baseline` / `--dupes-baseline`. These are committed and let CI fail only on regressions beyond the recorded debt.

So when a project is in baseline mode, expect committed `fallow-baselines/*.json` files plus a raised `score_cap` value in `fta.json` — not an FTA baseline file. Biome, oxlint, and TypeScript have no baseline concept; accept their debt by fixing it, scoping an override, or excluding a generated path.

## New Projects

1. Classify the repository topology with [project-topologies.md](project-topologies.md).
2. Add config assets from `assets/configs/` to each project boundary that should be gated independently.
3. Extend or merge `assets/configs/tsconfig.quality.json` into each project's TypeScript config.
4. Install dev dependencies with Bun.
5. Add `quality` scripts.
6. Confirm every `bun run <script>` used by the selected workflow exists in the target `package.json`.
7. Add `assets/github/quality-gate-strict.yml` for one project, or `assets/github/quality-gate-project-matrix.yml` for multiple separately gated project roots.
8. Add a dependency freshness workflow when the project should surface outdated dependencies:
   - `assets/github/dependency-freshness-weekly.yml` for single-project or meta-framework repositories.
   - `assets/github/dependency-freshness-project-matrix-weekly.yml` for split frontend/backend or multi-project repositories.
9. Create or merge an `AGENTS.md` at each gated project root from `assets/agents/AGENTS.md`, filling placeholders with the project's real conventions (styling, components, logger, contract library, persistence stack, env config, commands) and `strict` as the mode. Merge into any existing `AGENTS.md`; do not overwrite.
10. Run `bun run quality` in each gated project.
11. Fix all failures before merging the baseline.

## Existing Projects

1. Classify the repository topology with [project-topologies.md](project-topologies.md).
2. Add config assets from `assets/configs/` to each project boundary that should be gated independently.
3. Extend or merge `assets/configs/tsconfig.quality.json` into each project's TypeScript config.
4. Start with strict config files, but do not lower the policy thresholds globally.
5. Run the full gate locally in each gated project and inspect failures:

```fish
bun run quality
```

6. If the current repository fails because of existing debt, create baselines inside each gated project:

```fish
mkdir -p .fallow
mkdir -p fallow-baselines
bunx fallow dead-code --save-baseline fallow-baselines/dead-code.json
bunx fallow health --save-baseline fallow-baselines/health.json --max-cyclomatic 10 --max-cognitive 12
bunx fallow dupes --save-baseline fallow-baselines/dupes.json
```

   The `dupes` baseline can pass while logging that baseline entries matched zero current clone groups. That warning is benign: it means the saved duplication entries did not line up with any clone group in the current run (typically because the repository has no current duplication clusters, or they shifted since the baseline was written). It does not fail the gate. If the codebase genuinely has no duplication, you can skip generating `dupes.json` entirely rather than commit an empty baseline.

7. For FTA, set `score_cap` to the current highest failing score only when immediate cleanup is not feasible. Add a comment in the PR summary that this is a temporary legacy cap, including the reason, scope, owner or tracking issue, and cleanup condition. To read the current scores without wading through FTA's verbose default table, run `fta . --json` and take the highest reported score; the `interpreted as non-j/tsx`/`Failed to analyze` lines from single-file components are non-fatal noise, not failing files.
8. Confirm every `bun run <script>` used by the selected workflow exists in the target `package.json`.
9. Add `assets/github/quality-gate-baseline.yml` for one project, or `assets/github/quality-gate-project-matrix-baseline.yml` for multiple separately gated project roots.
10. Add a weekly dependency freshness workflow when the project should surface outdated dependencies. Use the single-project workflow for one project root and the project-matrix workflow for split frontend/backend or multi-project repositories.
11. Create or merge an `AGENTS.md` at each gated project root from `assets/agents/AGENTS.md`, filling placeholders with the project's real conventions (styling, components, logger, contract library, persistence stack, env config, commands) and `baseline` as the mode. Merge into any existing `AGENTS.md`; do not overwrite.
12. Commit `fallow-baselines/*.json` and any intentionally chosen baseline files per project. Treat each baseline as accepted debt, not as a permanent ignore list.
13. Make CI fail on regressions with zero tolerance unless the user explicitly chooses a small temporary tolerance.

## Local Checks in Baseline Mode

The shipped `quality:*` scripts are **baseline-blind**: `quality:fallow`, `quality:health`, and `quality:audit` run with no baseline flags. On a brownfield repo they report pre-existing debt, so `bun run quality` (and `bun run quality:audit`) **fail locally even when baseline CI is green**. This is expected, not a misconfiguration — the baseline CI workflow (`quality-gate-baseline.yml`) does not run those scripts as-is. It replaces them with a single baseline-aware step:

```fish
bunx fallow audit --gate all \
  --dead-code-baseline fallow-baselines/dead-code.json \
  --health-baseline fallow-baselines/health.json \
  --dupes-baseline fallow-baselines/dupes.json
```

and FTA passes because the temporary `score_cap` is raised.

So in baseline mode:

- **CI is the authority for the regression verdict.** Do not raise thresholds or lower the FTA cap to make local `bun run quality` pass on legacy debt — that is the exact mistake the baseline mechanism exists to prevent.
- **To check your change locally against the committed baselines,** run the same baseline-aware audit the CI runs (the command above; drop any baseline file you did not generate). This is the local equivalent of the CI gate.
- `bun run quality:audit` and the other `quality:*` scripts remain useful as strict, baseline-blind sweeps when you deliberately want to see total debt — just expect them to fail on legacy findings.

## Tightening Baselines

When debt is removed, regenerate baselines on the protected branch and lower any temporary FTA cap back toward `50`.

## Override Hygiene

- Prefer scoped overrides over global threshold changes.
- Prefer Fallow threshold overrides with reasons for known legacy functions.
- Prefer generated-path excludes for generated files.
- Remove stale suppressions; do not let suppression comments become permanent invisible debt.
