# Project Topologies

Use this guide before copying assets into projects with more than one app/package or with a shared workspace.

## Decision Rule

Gate by deployable or package boundary (methodology → *Gate by Boundary*):

- One deployable app or library: one quality gate at the project root.
- Split services (e.g. API + worker, or backend + a separate frontend): one gate per project root.
- Shared contracts consumed by more than one service: gate them with the consuming project, or as their own package when they have independent dependencies and lifecycle.
- Monorepo / uv workspace with multiple packages: one gate per package that has its own dependency surface and ownership.
- Integrated app (a single deployable that happens to contain web + worker + CLI entry points): one gate at the app root.

Do not combine unrelated services' debt into one baseline. Do not split one integrated app into artificial sub-gates.

## Source Layout

This pack assumes a `src` import root (the `src`-layout). Set `src` consistently across `mise.toml` tasks, Ruff `src`, tach `source_roots`, and the Vulture/Bandit paths. For a flat layout (package at the repo root), replace `src` with the package directory everywhere it appears, and set tach `source_roots` to `["."]` or the package root.

Pin the Python version in each project's `mise.toml`; it is the single source of truth for local installs and `mise-action` in CI.

## Split Services

Use separate project-local configs when services have different `pyproject.toml` files, lockfiles, Python versions, or deployment lifecycles.

Apply per project:

- `mise.toml`, `tach.toml`, `.pre-commit-config.yaml`, and the merged `[tool.*]` tables in that project's `pyproject.toml`
- `uv.lock` and the project virtualenv (`uv sync` in that project)
- basedpyright baseline, Vulture whitelist, and Bandit baseline in that project root
- dependency freshness report for that project
- `.env*` and `.env.example` owned by that project, not the repo root unless the root is the deployable boundary

Use `assets/github/quality-gate-project-matrix.yml` when the services live in one repository. Keep matrix entries explicit (e.g. `api`, `worker`) so each project can fail independently. Use `assets/github/dependency-freshness-project-matrix-weekly.yml` when freshness must be checked separately per project root.

Shared-contract adjustments:

- Prefer a dedicated package or module for Pydantic v2 contracts consumed by more than one service.
- If it has its own `pyproject.toml`, add it to the matrix with format, lint, typecheck, and dependency freshness; keep it dependency-light (Pydantic + typing helpers only — no service framework, database, or runtime config).
- If it is a plain shared module, include it in the nearest project's gate and make sure Ruff, basedpyright, and tach cover it.
- Import shared contracts through the package public entry point, not deep relative paths.

Service-specific adjustments:

- Allow `print` only in scripts, CLIs, migrations, and config via the scoped `T20` ignores.
- Exclude generated clients (protobuf/gRPC `*_pb2.py`, OpenAPI clients), migration artifacts, build output, and caches.
- Keep ORM/schema files, migrations, and seed scripts owned by the service that owns the database.
- Keep cycles and boundaries strict; populate tach modules per service with `tach mod`/`tach sync`.

## Monorepos and uv Workspaces

For a uv workspace (one root `pyproject.toml` with `[tool.uv.workspace] members`), prefer a root install plus per-package quality scripts. Keep baselines close to the package they measure.

Choose one of two patterns:

- **Independent packages with separate lockfiles:** use the matrix workflow as-is and run `uv sync --frozen` inside each package path.
- **Shared workspace lockfile:** run `uv sync --frozen` once at the workspace root, then run tasks per package path.

Keep shared tool config at the root only when all packages can use the same thresholds and ignore paths. Otherwise keep package-local `pyproject.toml` `[tool.*]` tables and a package-local `tach.toml`. Keep basedpyright config close to each package when packages target different Python versions.

For freshness, `uv pip list --outdated` reports the active environment; use the single-project freshness workflow at the workspace root when one report is enough, or the project-matrix workflow when each package should show separately.

## Integrated Apps

Treat a single deployable with multiple entry points (web + worker + CLI) as one integrated app with one gate at the app root:

- Ruff formats and lints app, worker, CLI, and shared modules.
- basedpyright strict-checks the whole app.
- tach analyzes the integrated app graph; define modules for the app's internal layers.
- dependency freshness runs once for the app.
- `.env*` files live at the app root.
- ORM/schema files, migrations, and generated clients live under this app boundary unless the repo has a separate database package.

Exclude framework-generated output explicitly (e.g. `migrations/versions` artifacts where generated, `build`, `dist`, `__pycache__`).
