# Adoption Workflow

Use strict mode for new Go modules and baseline mode for existing modules with current quality debt.

Decide the mode **before** copying configs or running the gate. The common adoption failure is to drop the strict configs into a brownfield module, run the gate, watch hundreds of pre-existing issues fail, and then start loosening thresholds. That destroys the standard. Instead, recognize the module as legacy, keep the strict configs, and absorb the current debt through the mechanisms below so CI fails only on regressions. The thresholds stay strict; the debt is recorded, not waived.

## Baseline Model (Go differs from the TypeScript pack)

The TypeScript pack commits `fallow-baselines/*.json` snapshot files. **Go has no committed baseline file.** Its mechanisms are:

- **golangci-lint = diff-based new-issues mode.** Baseline mode runs `golangci-lint run --new-from-merge-base=origin/<default-branch>`, which fails only on issues introduced by the change. There is no snapshot artifact to commit. `--new-from-rev=<rev>` is the local equivalent.
- **Complexity / `maintidx` = capped-score.** Temporarily raise the threshold in `.golangci.yml` (e.g. `cyclop.max-complexity`, `maintidx.under`, `funlen.lines`) to the current worst value, then lower it back toward the strict target as files are cleaned up.
- **vet / build / deadcode / govulncheck = no baseline.** Absorb their debt by fixing it, scoping a `//nolint:<linter> // reason` directive, or excluding a generated path. (govulncheck findings are security issues — prefer fixing or an explicit, tracked exception over suppression.)

So in baseline mode expect a CI lint step switched to `--new-from-merge-base` plus, if needed, temporarily raised complexity thresholds in `.golangci.yml` — never a Go baseline file.

## New Modules

1. Classify the repository topology with [project-topologies.md](project-topologies.md).
2. Add config assets from `assets/configs/` to each module boundary that should be gated independently.
3. Set `formatters.settings.goimports.local-prefixes` in `.golangci.yml` to the module path from `go.mod`, and the `go` version in `mise.toml` `[tools]` to match `go.mod`'s `toolchain`.
4. Install the toolchain and tools: `mise install`, then `mise run lefthook-install`.
5. Confirm the canonical tasks exist: `mise tasks ls`.
6. Validate the linter config: `golangci-lint config verify`.
7. Add `assets/github/quality-gate-strict.yml` for one module, or `assets/github/quality-gate-project-matrix.yml` for multiple gated modules.
8. Add a dependency freshness workflow (`dependency-freshness-weekly.yml` for one module, `…-project-matrix-weekly.yml` for several).
9. Create or merge an `AGENTS.md` at each gated module root from `assets/agents/AGENTS.md`, filling placeholders with the project's real conventions (logger, validation library, persistence stack, env config, commands) and `strict` as the mode. Merge into any existing `AGENTS.md`; do not overwrite.
10. Run `mise run quality` in each gated module.
11. Fix all failures before merging.

## Existing Modules

1. Classify the repository topology with [project-topologies.md](project-topologies.md).
2. Add config assets from `assets/configs/` to each gated module boundary.
3. Set the module path and `go` version as in step 3 above. Start with strict config files, but do not lower the policy thresholds globally.
4. Run the full gate locally in each gated module and inspect failures:

   ```fish
   mise run quality
   ```

5. Switch CI to baseline mode: use `assets/github/quality-gate-baseline.yml` (or `…-project-matrix-baseline.yml`), whose lint step runs `golangci-lint run --new-from-merge-base=origin/<default-branch>` so only new issues fail.
6. If complexity or `maintidx` floods because of legacy functions, temporarily raise those thresholds in `.golangci.yml` to the current worst value. Add a note in the PR summary that this is a temporary legacy cap, including reason, scope, owner or tracking issue, and cleanup condition.
7. Confirm every `mise run <name>` used by the selected workflow exists (`mise tasks ls`).
8. Add a weekly dependency freshness workflow (single or matrix).
9. Create or merge an `AGENTS.md` at each gated module root, filling placeholders and `baseline` as the mode. Merge, do not overwrite.
10. Make CI fail on regressions with zero tolerance unless the user explicitly chooses a small temporary tolerance.

## Local Checks in Baseline Mode

The shipped `lint` task is **baseline-blind**: `mise run lint` runs `golangci-lint run` with no `--new-from-*` flag. On a brownfield module it reports all pre-existing debt, so `mise run quality` **fails locally even when baseline CI is green**. This is expected, not a misconfiguration — the baseline CI workflow replaces the plain lint step with the diff-based one.

So in baseline mode:

- **CI is the authority for the regression verdict.** Do not raise thresholds to make local `mise run quality` pass on legacy debt — that is the exact mistake the baseline mechanism exists to prevent.
- **To check your change locally like CI runs it:**

  ```fish
  golangci-lint run --new-from-rev=origin/<default-branch>
  ```

- `mise run lint` remains useful as a strict, baseline-blind sweep when you deliberately want to see total debt — just expect it to fail on legacy findings.

## Tightening Baselines

When debt is removed, lower any temporarily raised complexity/`maintidx` thresholds back toward the strict targets, and remove stale `//nolint` directives. Do not let suppressions become permanent invisible debt.

## Override Hygiene

- Prefer scoped `//nolint:<linter> // reason` over global `disable` entries.
- Prefer generated-path excludes (`generated: strict` + explicit paths) for generated files over relaxing global rules.
- Every exception documents reason, scope, owner or tracking issue, and cleanup condition.
- CI must still fail on new regressions outside the accepted baseline.
