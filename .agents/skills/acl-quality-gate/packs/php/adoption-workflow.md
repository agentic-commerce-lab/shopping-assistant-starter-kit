# Adoption Workflow

Use strict mode for new repositories and baseline mode for existing repositories with
current quality debt.

Decide the mode **before** copying configs or running the gate. The common adoption failure
is to drop the strict configs into a brownfield repository, run the gate, watch a wall of
pre-existing issues fail, and then start loosening thresholds. That destroys the standard.
Instead, recognize the repository as legacy, keep the strict configs, and absorb the current
debt through the baseline mechanisms below so CI fails only on regressions.

> **Do not disturb an existing toolchain.** The Mago defaults below are for new projects and
> fresh adoption. If the project already runs PHPStan, Psalm, PHP-CS-Fixer, Pint, PHPMD, or
> deptrac, keep them and adopt by capability slot (see [pack.md](pack.md) → *Adopting onto an
> Existing Toolchain*). Never swap a working tool for Mago without an explicit request.

## Baseline Model

This pack's baseline-capable tools:

- **Mago lint** — `lint-baseline.toml`, generated with
  `mago lint --generate-baseline --baseline lint-baseline.toml`, consumed with
  `mago lint --baseline lint-baseline.toml`.
- **Mago analyze** — `mago-analysis-baseline.toml`, generated/consumed the same way with
  `mago analyze`.
- **Mago guard** — its own baseline (opt-in; only when layers are configured).
- **jscpd** — no baseline file; it is a **capped-score** tool. Raise the `threshold` in
  `.jscpd.json` to the current duplication percentage, then lower it back over time.

`mago fmt`, `check_file_length.php`, `composer audit`, and composer-dependency-analyser have
no baseline concept: absorb their debt by fixing it, scoping an override, or excluding a
generated path. phpcca is advisory and never gates, so it needs no baseline handling.

## New Projects

1. Classify the repository topology with [project-topologies.md](project-topologies.md).
2. Copy `mago.toml`, `.jscpd.json`, `composer-dependency-analyser.php`, `phpcca.yaml`,
   `captainhook.json`, and `.editorconfig` to each gated project root; copy
   `check_file_length.php` to `scripts/`; merge the scripts + `require-dev` from
   `composer-scripts.fragment.json` into each `composer.json`. Set `[source] paths` and the
   `src` references to the real source root, and pin `php-version` in `mago.toml`.
3. Install the toolchain and dependencies:
   ```fish
   composer install   # installs carthage-software/mago (provides vendor/bin/mago) + the other dev tools
   # CI installs Mago via nhedger/setup-mago; `cargo install mago` / `brew install mago` are alternatives.
   # jscpd is a non-PHP binary: `cargo install jscpd` / `brew install jscpd` / `npm i -g jscpd@5`.
   ```
4. Install the Git hooks (`composer require --dev captainhook/captainhook captainhook/hook-installer`;
   `vendor/bin/captainhook install`).
5. Confirm every `composer run <task>` used by the selected workflow exists in
   `composer.json` (the CI *Verify composer scripts* step enforces this).
6. Add `assets/github/quality-gate-strict.yml` for one project, or
   `assets/github/quality-gate-project-matrix.yml` for multiple separately gated roots.
7. Add a dependency-freshness workflow (single-project or project-matrix variant).
8. Create or merge an `AGENTS.md` at each gated root from `assets/agents/AGENTS.md`, filling
   placeholders with the project's real conventions and `strict` as the mode.
9. Run `composer run quality` in each gated project.
10. Fix all failures before merging the baseline.

## Existing Projects

1. Classify the repository topology with [project-topologies.md](project-topologies.md).
2. Copy config assets to each gated boundary and merge the scripts into each `composer.json`.
3. Start with the strict config files, but do not lower the policy thresholds globally.
4. Install the toolchain, dependencies, and hooks.
5. Run the full gate locally in each gated project and inspect failures:
   ```fish
   composer run quality
   ```
6. If the repository fails because of existing debt, create baselines inside each gated
   project:
   ```fish
   mago lint --generate-baseline --baseline lint-baseline.toml
   mago analyze --generate-baseline --baseline mago-analysis-baseline.toml
   # If guard layers are configured: mago guard --generate-baseline --baseline <file>
   ```
   For duplication, raise the `threshold` in `.jscpd.json` to the current percentage rather
   than committing a baseline file. Review each generated baseline before committing — a
   suppressed finding is accepted debt, so do not bury real bugs you could fix now.
7. Confirm every `composer run <task>` used by the selected workflow exists.
8. Add `assets/github/quality-gate-baseline.yml` (or
   `quality-gate-project-matrix-baseline.yml` for multiple roots).
9. Add a weekly dependency-freshness workflow.
10. Create or merge an `AGENTS.md` at each gated root with `baseline` as the mode.
11. Commit `lint-baseline.toml`, `mago-analysis-baseline.toml`, any guard baseline, and the
    raised `.jscpd.json` threshold per project. Treat each as accepted debt.
12. Make CI fail on regressions with zero tolerance unless the user explicitly chooses a small
    temporary tolerance.

## Local Checks in Baseline Mode

The shipped `quality:dupes` task and the plain `composer run lint` / `composer run typecheck`
are **baseline-blind** — they run Mago and jscpd with no baseline flags, so `composer run
quality` **fails locally even when baseline CI is green**. This is expected, not a
misconfiguration — the baseline CI workflow (`quality-gate-baseline.yml`) replaces those
steps with baseline-aware invocations:

```fish
mago lint --baseline lint-baseline.toml
mago analyze --baseline mago-analysis-baseline.toml
# jscpd gates against the raised .jscpd.json threshold
```

So in baseline mode:

- **CI is the authority for the regression verdict.** Do not loosen thresholds or delete
  baseline entries to make local `composer run quality` pass on legacy debt — that is the
  exact mistake the baseline mechanism exists to prevent.
- **To check your change locally against the committed baselines,** run the baseline-aware
  commands above (the local equivalent of the CI gate).

## Tightening Baselines

When debt is removed, regenerate the Mago baselines on the protected branch and commit the
smaller files; lower the jscpd `threshold` back toward target. Lower the accepted-debt
surface over time toward zero.

## Override Hygiene

- Prefer scoped overrides over global threshold changes.
- Prefer Mago path-scoped `ignore` entries (with a reason) for known-legacy paths.
- Prefer generated-path excludes for generated files.
- Keep suppressions scoped and commented; remove them as the underlying issue is fixed. No
  tool reports stale PHP suppressions, so this is a review responsibility.
