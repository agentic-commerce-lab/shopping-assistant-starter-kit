# Python Pack

Concrete binding of the [quality gate methodology](../../references/methodology.md) and the shared [coding conventions](../../references/conventions.md) to the Python toolchain. Read the methodology first (capability contract, modes, baseline mechanics, boundaries) and conventions for the language-neutral coding policy; this pack supplies the tools, configs, commands, and the Python-specific bindings. It follows the [pack-authoring contract](../../references/pack-authoring.md).

Prefer mise + uv. Recommend the tools below for new projects and projects adopting the gate; never swap a toolchain a project already uses — wire the canonical command names to the existing tool and add only the missing layers (see *Adopting onto an Existing Toolchain*).

## Capability → Tool

| Capability | Tool | Config asset |
|------------|------|--------------|
| Toolchain version + venv + task runner | mise (Python version, uv-managed venv, canonical tasks) | `assets/configs/mise.toml` |
| Package manager / dependency resolution | uv (`uv.lock`, `uv sync`) | — (managed in `mise.toml`) |
| Format | Ruff (`ruff format`) | `assets/configs/pyproject.quality.toml` (`[tool.ruff]`) |
| Lint **+ per-function maintainability** | Ruff (`ruff check`; cyclomatic complexity, args, statements, branches) | `assets/configs/pyproject.quality.toml` (`[tool.ruff.lint]`) |
| File length (~400 LOC) | `check_file_length.py` (`quality:filesize`) | `assets/configs/check_file_length.py` |
| Type / strictness check **+ baseline ratchet** | basedpyright (strict; `--writebaseline`) | `assets/configs/pyproject.quality.toml` (`[tool.basedpyright]`) |
| Circular dependencies + boundaries | tach (`forbid_circular_dependencies`) | `assets/configs/tach.toml` |
| Dead code | Vulture (committed `whitelist.py` baseline) | `assets/configs/pyproject.quality.toml` (`[tool.vulture]`) |
| Security | Bandit (`bandit -b` baseline) | `assets/configs/pyproject.quality.toml` (`[tool.bandit]`) |
| Dependency freshness | uv (`uv pip list --outdated`, weekly issue) | `assets/github/dependency-freshness-weekly.yml`, `…-project-matrix-weekly.yml` |
| Hooks | pre-commit framework | `assets/configs/.pre-commit-config.yaml` |
| CI | GitHub Actions + `jdx/mise-action` | `assets/github/quality-gate-*.yml` |
| `AGENTS.md` | Python-tuned template | `assets/agents/AGENTS.md` |

**Merged slots:** Ruff fills *format* and *lint*, and absorbs the per-function *maintainability* metrics (cyclomatic complexity, parameter count, statements, branches). basedpyright is both the *type/strictness* check and the type-error *baseline* mechanism. File length is gated by a small shipped script (`quality:filesize`) because Ruff has no module-length rule.

**Baseline shapes** (methodology → *Baseline mechanics*): baseline-file tools are basedpyright (`.basedpyright/baseline.json`, auto-consumed), Vulture (`whitelist.py`), and Bandit (`-b .bandit-baseline.json`). There is no capped-score tool. Ruff and tach have no baseline concept — absorb their debt by fixing, scoping an ignore, or excluding a generated path.

### Capability gaps (no silent drops)

Python has no single repo-graph-intelligence tool equivalent to the TypeScript pack's Fallow. These slots are **explicitly gapped** and left to review; see [quality-gate-policy.md](quality-gate-policy.md) → *Capability Gaps*:

- **Code duplication** — no gated tool.
- **Dependency hygiene** (unused / unlisted / missing dependencies) — no gated tool.
- **Repo health score + hotspots** — no Python single-score equivalent.
- **Maintainability-index score** (the FTA capped-score analog) — none; per-function complexity via Ruff stands in.
- **Cognitive complexity (12)** — no maintained core tool (threshold-parity deviation); cyclomatic complexity (`C901` = 10) stands in.
- **Nesting depth (4)** — Ruff's `PLR1702` is a *preview* rule, so `max-nested-blocks` is not enforced on stable (threshold-parity deviation); the config key is shipped and activates when the rule stabilizes.
- **Cross-module unused exports/files** — Vulture is heuristic here and weaker than a graph tool.

File length (~400 LOC) is **not** a gap — it is enforced by the shipped `quality:filesize` task. Cyclomatic complexity, parameter count, statements, and branches are enforced via Ruff.

`tach` closes the highest-value gap (circular dependencies + architecture boundaries). The rest are review-only; opt-in add-ons noted in the policy doc.

## Detailed References

- [quality-gate-policy.md](quality-gate-policy.md) — Python tool bindings: thresholds-as-config, Ruff rule coverage, the convention→rule mapping, basedpyright/Vulture/Bandit/tach mechanics, the capability gaps. Read before choosing thresholds or relaxing defaults. (Language-neutral coding conventions live in [conventions.md](../../references/conventions.md).)
- [adoption-workflow.md](adoption-workflow.md) — strict vs baseline rollout; basedpyright/Vulture/Bandit baseline mechanics; baseline-blind local checks. Read before applying to a brownfield repo.
- [project-topologies.md](project-topologies.md) — split services, monorepos, uv workspaces, src-layout. Read before applying to anything with more than one project root.
- [development-usage.md](development-usage.md) — Python binding of the shared [implementation-phase workflow](../../references/development-usage.md): the runner, this pack's `quality:*` task names, and Python-specific review items.

## Setup Flow

1. Inspect the project (per the core workflow) and **decide the mode** (methodology → *Mode Decision*). If unsure, run the gate once (step 8) — a wall of pre-existing failures means baseline mode; load [adoption-workflow.md](adoption-workflow.md) and follow it.
2. Classify the topology with [project-topologies.md](project-topologies.md) before touching split services, monorepos, or uv workspaces.
3. Copy or merge assets into the project: `mise.toml`, `tach.toml`, `.pre-commit-config.yaml`, and `.editorconfig` to the gated project root; `check_file_length.py` to `scripts/`; the `[tool.*]` tables from [assets/configs/pyproject.quality.toml](assets/configs/pyproject.quality.toml) merged into the project's `pyproject.toml`; GitHub workflows to `.github/workflows/`; VS Code assets to `.vscode/`. Merge existing TOML/YAML carefully; do not reformat large files unnecessarily. Set `src` to the project's real source root everywhere it appears. **Pin the Python version** in `mise.toml` to the latest stable Python at adoption time (`mise ls-remote python` or python.org) — do not assume the shipped default stays current.
4. Trust the config, then install the toolchain and dependencies:

   ```fish
   mise trust
   mise install
   uv add --dev ruff basedpyright tach vulture bandit pre-commit
   uv sync
   ```

   (`mise trust` is only needed for local use; CI's `mise-action` trusts the config automatically.)

5. Install the Git hooks:

   ```fish
   uv run pre-commit install
   uv run pre-commit install --hook-type pre-push
   ```

6. Configure module boundaries for tach (needed before `tach check` is meaningful):

   ```fish
   uv run tach mod    # mark source roots and modules
   uv run tach sync   # populate each module's allowed dependencies
   ```

7. Add GitHub Actions:
   - [assets/github/quality-gate-strict.yml](assets/github/quality-gate-strict.yml) for new projects.
   - [assets/github/quality-gate-baseline.yml](assets/github/quality-gate-baseline.yml) for existing projects with committed baselines.
   - [assets/github/quality-gate-project-matrix.yml](assets/github/quality-gate-project-matrix.yml) when one repo contains multiple separately gated projects.
   - [assets/github/quality-gate-project-matrix-baseline.yml](assets/github/quality-gate-project-matrix-baseline.yml) for existing multi-project repos with committed baselines.
   - [assets/github/dependency-freshness-weekly.yml](assets/github/dependency-freshness-weekly.yml) for single-project repos; [assets/github/dependency-freshness-project-matrix-weekly.yml](assets/github/dependency-freshness-project-matrix-weekly.yml) for multi-project repos.
8. Create or merge `AGENTS.md` at each gated project root from [assets/agents/AGENTS.md](assets/agents/AGENTS.md) — a required output (methodology → *AGENTS.md Is a Required Output*). Fill every placeholder with the project's real choices (logger, contract/validation library, persistence/migration stack, env-config accessor, Python version, strict vs baseline mode) and delete the guidance comments. Merge, do not overwrite; keep it short.
9. Run the gate:

   ```fish
   uv sync
   mise run quality
   ```

   - **Strict mode:** fix every failure before merging the baseline.
   - **Baseline mode:** do not chase a clean run on legacy debt. Generate and commit the baseline files (`.basedpyright/baseline.json`, `whitelist.py`, `.bandit-baseline.json`) per [adoption-workflow.md](adoption-workflow.md), then rely on the baseline CI workflow to fail only on regressions. The local `quality:deadcode`/`quality:security` tasks are baseline-blind — see [adoption-workflow.md](adoption-workflow.md) → *Local Checks in Baseline Mode*.

## Adopting onto an Existing Toolchain

The principle — adopt by capability slot, keep the tools the user wants kept, and wire the canonical names to them — is in [methodology.md](../../references/methodology.md) → *Adopting onto an Existing Toolchain*. The Python substitutions:

- **Keep their formatter/linter/type checker** and wire the canonical names to them: `format:check` → `black --check .`, `lint` → `ruff check .` or `flake8`, `typecheck` → `mypy`. If the project has no task runner, mise tasks are the lightest way to expose the names; otherwise wire them into the existing runner (Makefile, Poe, tox).
- **Add the layers they're missing**, usually the structural ones: tach (cycles + boundaries), Vulture (dead code), Bandit (security), and the dependency-freshness report. These compose with any formatter/linter/type checker.
- **Keep Black** when the project standardizes on it (use Ruff only as the linter); **keep mypy** when the team prefers it — but note mypy has no native error baseline, so the type-ratchet story changes (use a mypy baseline tool or scoped ignores).

## Asset Map

- `assets/configs/mise.toml`: Python version, uv-managed venv, and the canonical task vocabulary. Copy to the gated project root.
- `assets/configs/pyproject.quality.toml`: `[tool.ruff]`, `[tool.ruff.lint]`, `[tool.basedpyright]`, `[tool.vulture]`, `[tool.bandit]` tables to merge into the project's `pyproject.toml`.
- `assets/configs/tach.toml`: circular-dependency and module-boundary config. Populate modules with `tach mod` + `tach sync`.
- `assets/configs/.pre-commit-config.yaml`: pre-commit (Ruff format + lint on staged) and pre-push (basedpyright) hooks.
- `assets/configs/.editorconfig`: cross-editor formatting contract (4-space Python, 2-space data files).
- `assets/configs/check_file_length.py`: dependency-free file-length gate (~400 LOC). Copy to `scripts/`; backs the `quality:filesize` task.
- `assets/agents/AGENTS.md`: placeholder-driven `AGENTS.md` template.
- `assets/github/quality-gate-strict.yml`: CI for new projects.
- `assets/github/quality-gate-baseline.yml`: CI for legacy adoption with regression baselines.
- `assets/github/quality-gate-project-matrix.yml`: CI for multiple separately gated project roots.
- `assets/github/quality-gate-project-matrix-baseline.yml`: baseline-aware CI for existing multi-project repos.
- `assets/github/dependency-freshness-weekly.yml`: scheduled freshness workflow that creates/updates one tracking issue.
- `assets/github/dependency-freshness-project-matrix-weekly.yml`: scheduled freshness issue with per-project sections.
- `assets/editor/vscode-settings.json`: VS Code workspace settings (Ruff formatter, basedpyright; no format-on-save).
- `assets/editor/vscode-extensions.json`: VS Code extension recommendations.

## Implementation Rules

- Keep Ruff responsible for formatting **and** linting; the maintainability metrics (cyclomatic complexity, parameter count, statements, branches) live in `[tool.ruff.lint]` config. Do not add a separate complexity tool — Radon/Xenon are effectively unmaintained and their per-function signal is already in Ruff. Nesting depth (`PLR1702`/`max-nested-blocks`) is a Ruff *preview* rule and is not enforced on stable; the key is shipped for when it stabilizes.
- File length has no Ruff rule, so the shipped `scripts/check_file_length.py` (the `quality:filesize` task, ~400 LOC) fills that slot. It is dependency-free; keep it wired into `quality` and CI rather than adding pylint just for `C0302`.
- Pin the Python version in `mise.toml` to the **latest stable** at adoption time, not a frozen default; keep `pyproject.toml` `requires-python` and Ruff `target-version` in step with it.
- Keep basedpyright (not plain Pyright) as the type checker: it is a drop-in fork that adds a real error baseline (`--writebaseline` → `.basedpyright/baseline.json`), which plain Pyright lacks. The committed baseline is consumed automatically, so basedpyright is baseline-aware locally as well as in CI.
- Keep `forbid_circular_dependencies = true` in `tach.toml`; it is the highest-value graph check the pack provides. `tach check` needs modules defined — run `tach mod`/`tach sync` during setup. Boundary contracts beyond cycle detection are opt-in.
- Treat Bandit as a Python-specific addition to the gate (no other pack has a security slot). Leave Ruff's `S` (flake8-bandit) ruleset **off** to avoid duplicate findings with Bandit.
- Allow `print` only in scripts, CLIs, migrations, and config through the scoped `per-file-ignores` for `T20`; keep it an error in application code.
- Do not enable pytest/test rules or test gates in this baseline. Tests are out of scope unless the user explicitly asks for test gates.
- Treat dependency freshness as weekly visibility by default, not a PR blocker. Use `uv pip list --outdated` in a scheduled issue; prefer issue-driven follow-up for patch/minor and explicit migration work for majors.
- Gate by deployable or package boundary. Do not merge unrelated services' debt into one baseline; do not split one integrated app into artificial sub-gates.
- For shared data contracts, use Pydantic v2 and validate boundary data at runtime; keep contract modules small and domain-oriented. There is no dedicated contract structural gate (Python's linter has no file-size cap) — it is an `AGENTS.md` + review convention.
- Keep generated outputs excluded explicitly (protobuf `*_pb2.py`, build/dist, migration artifacts) rather than relaxing global rules. Keep the excluded paths enumerated and narrow.
- For new projects, fail CI on strict thresholds immediately. For existing projects, commit baselines and fail CI only on regressions until debt is intentionally cleaned up.
- Allow baselines only as explicit debt decisions. Document the reason, scope, owner or tracking issue, and cleanup condition; do not use baselines to hide new-project debt.
- Keep workflow steps backed by mise tasks. Every `mise run <task>` used by a workflow must exist in the target `mise.toml` (the CI *Verify mise tasks* step enforces this), either from `assets/configs/mise.toml` or as an intentional replacement.

## Verification

After editing a target project, run:

```fish
uv sync --frozen
mise run format:check
mise run lint
mise run typecheck
mise run quality:boundaries
mise run quality:filesize
mise run quality:deadcode
mise run quality:security
```

For dependency freshness visibility, run the non-blocking report separately:

```fish
mise run quality:deps
```

If the lockfile is not committed yet, run `uv sync` first, then repeat with `--frozen`.
