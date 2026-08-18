# Python Quality Gate Policy

The Python **bindings** for this gate: the concrete tools, thresholds-as-config, lint-rule coverage, and Python-specific mechanics. The language-neutral pieces live in the shared docs and are not restated here:

- Mechanical contract, modes, baseline mechanics, boundaries, freshness-as-visibility, hook split, override hygiene → [methodology.md](../../references/methodology.md).
- Coding conventions (code safety, logging, error handling, constants/env, persistence, shared-contract principle, directory/cohesion/coupling, design principles) → [conventions.md](../../references/conventions.md).

Use these defaults as strict recommendations for new Python projects. Relax only when a framework or generated code path makes a rule noisy, and scope the override to that path.

## Tool Responsibilities

- mise: toolchain version (Python), virtualenv (delegated to uv), and the canonical task vocabulary. `jdx/mise-action` installs the toolchain in CI.
- uv: dependency resolution, lockfile (`uv.lock`), and the project virtualenv (`uv sync`).
- Ruff: formatting (`ruff format`) and linting (`ruff check`), including the per-function maintainability rules (cyclomatic complexity, parameter count, statements, branches, nesting).
- basedpyright: strict type checking, plus the type-error **baseline** (`--writebaseline` → `.basedpyright/baseline.json`). **Baseline-file** shape, consumed automatically.
- tach: circular-dependency detection and module-boundary enforcement (`tach check`).
- Vulture: dead-code detection. **Baseline-file** shape (`whitelist.py`).
- Bandit: security linting. **Baseline-file** shape (`-b .bandit-baseline.json`).
- uv outdated: dependency freshness reporting (`uv pip list --outdated`).
- pre-commit framework: fast pre-commit formatting/linting on staged files; whole-program type checking on pre-push.
- GitHub Actions: authoritative quality gate.

## Recommended Thresholds

These realize the shared threshold-parity targets ([pack-authoring.md](../../references/pack-authoring.md) §3) as Python tool config. Where Python has no rule, the deviation is documented under *Capability Gaps*.

- Cyclomatic complexity: 10 through Ruff `C901` (`[tool.ruff.lint.mccabe] max-complexity = 10`).
- Parameter count: 5 through Ruff `PLR0913` (`[tool.ruff.lint.pylint] max-args = 5`).
- Function length: approximated by statements through Ruff `PLR0915` (`max-statements = 50`). **Deviation:** statements, not physical lines — Ruff has no physical-line-per-function rule.
- File length (~400 lines): the shipped `quality:filesize` task (`scripts/check_file_length.py`). Ruff has no module-length rule, so this dependency-free check fills the slot.
- Nesting depth (4): **deviation/gap** — Ruff's `PLR1702` is a *preview* rule, so `max-nested-blocks = 4` only takes effect under `preview = true`. The key is shipped; until the rule stabilizes, nesting depth is review-only.
- Cognitive complexity (12): **gap** — no maintained core tool. Cyclomatic complexity stands in.
- Maintainability-index score: **gap** — Radon/Xenon are effectively unmaintained; not adopted.
- Circular dependencies: error through tach `forbid_circular_dependencies = true`.
- Architecture boundaries: error through tach module contracts (opt-in via `tach mod`/`tach sync`).
- Dead code: Vulture at `min_confidence = 60` — the floor that catches unused functions/classes/methods/variables (all reported at 60%). At 80 the gate only flags unused imports/unreachable code and is effectively inert; the `whitelist.py` baseline and `ignore_decorators` absorb framework entry points and public-API false positives.
- Security: Bandit over the source root; prefer scoped `# nosec` with a reason over global skips.
- Type strictness: basedpyright `typeCheckingMode = "strict"` with the project's `pythonVersion`.
- Dependency freshness: create or update a weekly issue; do not report on every PR by default.

## Baseline Exceptions

Use baselines as explicit debt decisions, not as bypasses. Existing projects may record known current debt in the basedpyright baseline, the Vulture whitelist, and the Bandit baseline when immediate cleanup is unrealistic. New projects should avoid baselines except for generated code, framework artifacts, or confirmed false positives.

Every exception must document the reason, scope, owner or tracking issue, and cleanup condition. CI must still fail on new regressions outside the accepted baseline. Remove baseline entries as the underlying debt is cleaned up — basedpyright shrinks its baseline automatically when errors are fixed; regenerate the Vulture whitelist and Bandit baseline on the protected branch.

## Convention Bindings (Python)

How the shared [conventions.md](../../references/conventions.md) map onto Python enforcement. Where a rule exists, the convention is part of the mechanical `lint`/`typecheck` gate; otherwise it is an `AGENTS.md` + review convention.

| Convention | Python enforcement |
|---|---|
| No untyped escape hatch (`Any`) | basedpyright strict (`reportAny`/`reportExplicitAny`) for inferred/implicit `Any` + Ruff `ANN401` for explicit `Any` in annotations; boundary `Any` only, deliberately isolated |
| Logging (no ad-hoc stdout) | Ruff `T20` (error; scoped `per-file-ignores` for scripts/CLIs/migrations/config); project logger / stdlib `logging` |
| Throw only error types, preserve cause, narrow caught | Ruff `B904` (raise-from), `TRY` (tryceratops), `BLE001` (blind except), `B012` (no control flow in `finally`) |
| Strict equality | Ruff `E711`/`E712`/`E713`/`E714`, `F632` |
| Exhaustive closed-set handling | basedpyright `reportMatchNotExhaustive` + `assert_never`; review |
| No unsafe async | Ruff `ASYNC` (flake8-async), `RUF006` (dangling `asyncio` task) |
| No lossy coercions / mutable defaults | Ruff `B` (bugbear, incl. `B006`), `RUF` |
| Explicit / type-only imports | Ruff `I` (isort), `TID252` (ban relative-parent imports), `TC` (type-checking blocks), `F401` (unused) |
| Function complexity, params, statements, branches | Ruff `C901`, `PLR0913`/`PLR0915`/`PLR0912` (nesting `PLR1702` is preview — review-only for now) |
| File length (~400 LOC) | `quality:filesize` (`scripts/check_file_length.py`) |
| Constants/env | typed config accessors (`pydantic-settings`) over raw `os.environ` reads |
| Reuse over reinvention | review / `AGENTS.md` (not lint-enforceable); search PyPI for a maintained library before building non-trivial functionality |

Items the linter cannot enforce (review/`AGENTS.md` only): preferring immutable bindings (`Final`), member ordering, and the structural file-size cap for shared contracts. See *Capability Gaps* for what is deliberately left out.

## Ruff Rule Coverage

Enable Ruff with the rule groups in `[tool.ruff.lint] select`: `E`/`F`/`W` (pycodestyle + pyflakes), `I` (isort), `UP` (pyupgrade), `B` (bugbear), `C90` (mccabe), `SIM` (simplify), `PL` (pylint refactor/complexity/error/warning), `RUF`, `TRY` (tryceratops), `T20` (no print), `ASYNC`, `TID` (tidy-imports), `TC` (type-checking), and `ANN401` (ban explicit `Any`).

- `TRY003` is ignored — flagging long messages outside the exception class is too noisy as a hard gate.
- `PLR2004` (magic value) is kept on; it supports the constants/env convention.
- Leave Ruff's `S` (flake8-bandit security port) **off**. Bandit fills the security slot; enabling `S` duplicates the findings.
- Do not enable test plugins (`PT`, flake8-pytest) in this baseline — tests are a separate concern.
- Naming conventions (`N`/pep8-naming) and docstrings (`D`/pydocstyle) are left to review, not blocked here.

basedpyright strict carries the type-safety surface; Ruff does not duplicate type checking. If basedpyright strict is too noisy on a legacy repo, record the debt in its baseline rather than lowering `typeCheckingMode`.

## Shared Contracts (Python bindings)

The principle — define a contract once, validate boundary data at runtime, keep contract files small and domain-oriented — is in [conventions.md](../../references/conventions.md) → *Shared Data Contracts*. The Python mechanics:

- **Existing projects:** follow the project's current validation library (Pydantic, dataclasses + a validator, attrs, msgspec). The define-once-and-derive-types pattern applies regardless.
- For greenfield, use **Pydantic v2**: define `BaseModel` schemas and validate at the edge with `model_validate`. Share models through a dedicated package or module when more than one side consumes them.
- Keep contracts free of UI, framework request/response objects, database clients, secrets, and environment reads. Split by domain and operation; avoid one catch-all `schemas.py`.
- **No dedicated contract structural gate.** Python's linter has no file-size cap rule (the TypeScript pack used oxlint for this). Keeping contract files small is an `AGENTS.md` + review convention here.

## Capability Gaps

Python has no single repo-graph-intelligence tool equivalent to the TypeScript pack's Fallow. These are deliberately gapped, not silently dropped:

| Gap | Consequence | Opt-in if a project needs it |
|---|---|---|
| Code duplication | review only | `pylint` `R0801` (duplicate-code) as a focused run |
| Dependency hygiene (unused/unlisted/missing deps) | review only | `deptry` |
| Repo health score + hotspots | review only | no maintained single-score tool |
| Maintainability-index score | review only | (Radon/Xenon unmaintained — not recommended) |
| Cognitive complexity (12) | review only | `complexipy` (Rust, cognitive complexity) |
| Nesting depth (4) | review only | Ruff `PLR1702` once it leaves preview (`max-nested-blocks` key already shipped) |
| Cross-module unused exports/files | weaker than a graph tool | Vulture (heuristic) |

tach closes the highest-value gap (circular dependencies + boundaries), and `quality:filesize` closes file length. The rest above are review-only; add an opt-in only when a project's risk justifies the extra tool, behind its own `quality:*` task and CI step.

## Generated and Framework Code

Exclude generated outputs rather than relaxing global gates. Typical exclusions: protobuf/gRPC (`*_pb2.py`, `*_pb2.pyi`), `build`/`dist`, `__pycache__`, ORM/migration artifacts where generated, and any vendored client code. Keep generated paths explicit in the Ruff `extend-exclude`, basedpyright `exclude`, Vulture `exclude`, and tach `exclude`.

## Tasks

Expose the canonical command names ([pack-authoring.md](../../references/pack-authoring.md) §2) as mise tasks. Prefer existing framework commands when present — e.g. keep a project's `manage.py`-based checks, or its `tox`/`nox` sessions, and wire the canonical names to them. Do not add test commands to `quality`; tests are covered separately.

## Local Hooks

Keep local Git hooks fast and focused (the pre-commit/pre-push split rationale is in [methodology.md](../../references/methodology.md) → *Local Hooks vs CI*):

- Pre-commit: Ruff format + lint on the **staged** files via the pre-commit framework (it passes filenames and stashes unrelated working-tree edits, so partial stages are safe). A small set of generic file fixers (trailing whitespace, end-of-file, check-toml/yaml) runs here too.
- Pre-push: `basedpyright` whole-program type check. Type checking cannot be scoped to staged files, so it belongs off the per-commit hot path.
- tach, Vulture, Bandit, the file-length gate (`quality:filesize`), and dependency freshness belong in GitHub Actions by default.

Do not run full repository-intelligence checks in hooks unless the project is small and the user explicitly chooses stricter local enforcement.

## Python Configuration

Merge the `[tool.*]` tables from `assets/configs/pyproject.quality.toml` into the project's `pyproject.toml`. Set `line-length`, `target-version`/`pythonVersion`, and `src` to the project's real values.

- Keep basedpyright at `typeCheckingMode = "strict"`. Do not lower it to absorb legacy debt — record the debt in the baseline instead.
- Pin the Python version in `mise.toml` (`[tools] python`), the single source of truth for local installs and `mise-action` in CI.
- Keep `noqa`/`# type: ignore`/`# nosec` suppressions scoped and commented; do not let them become permanent invisible debt (`stale-suppression` discipline is a review concern here, since no tool reports them).

## Dependency Freshness

Use `uv pip list --outdated` to make stale dependencies visible (the weekly-visibility-not-a-blocker policy is in [methodology.md](../../references/methodology.md) → *Dependency Freshness as Visibility*). Run it weekly, create/update one tracking issue, prefer patch/minor first and treat majors as migration work. Use Dependabot or Renovate only when the team wants automated update PRs; keep the uv report even then, because it is lightweight and package-manager-native.
