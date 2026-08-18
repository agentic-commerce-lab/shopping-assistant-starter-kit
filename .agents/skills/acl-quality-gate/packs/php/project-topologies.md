# Project Topologies

Use this guide before copying assets into projects with more than one app/package or with a
shared workspace.

## Decision Rule

Gate by deployable or package boundary (methodology → *Gate by Boundary*):

- One deployable app or library: one quality gate at the project root.
- Split services (e.g. API + worker): one gate per project root.
- Shared contracts/packages consumed by more than one service: gate them with the consuming
  project, or as their own package when they have independent dependencies and lifecycle.
- Composer monorepo / path repositories with multiple packages: one gate per package that has
  its own `composer.json`, dependency surface, and ownership.
- Integrated app (a single deployable with web + worker + CLI entry points): one gate at the
  app root.

Do not combine unrelated services' debt into one baseline. Do not split one integrated app
into artificial sub-gates.

## Source Layout

This pack assumes a `src` source root. Set it consistently across `mago.toml` `[source]
paths`, the `.jscpd.json` `path`, the composer-dependency-analyser scanned paths, and the
`quality:filesize` script argument. For a different layout, replace `src` with the real
package directory everywhere it appears. Pin `php-version` in each project's `mago.toml`.

## Split Services

Use separate project-local configs when services have different `composer.json` files,
lockfiles, PHP versions, or deployment lifecycles.

Apply per project:

- `mago.toml`, `.jscpd.json`, `composer-dependency-analyser.php`, `phpcca.yaml`,
  `captainhook.json`, `.editorconfig`, and the merged scripts in that `composer.json`
- `composer.lock` and the project's installed dependencies
- the Mago lint/analyze (and guard) baselines in that project root
- a dependency-freshness report for that project
- `.env*` and `.env.example` owned by that project, not the repo root unless the root is the
  deployable boundary

Use `assets/github/quality-gate-project-matrix.yml` when the services live in one repository;
keep matrix entries explicit (e.g. `api`, `worker`) so each project can fail independently.
Use `assets/github/dependency-freshness-project-matrix-weekly.yml` when freshness must be
checked per project root.

Service-specific adjustments:

- Allow `echo`/`var_dump`/`dd` only in scripts, CLIs, migrations, and config via scoped Mago
  `ignore` for `no-debug-symbols`.
- Exclude generated clients (protobuf/gRPC, OpenAPI), migration artifacts, build output, and
  caches in the Mago `[source] excludes` and the jscpd `ignore`.
- Keep ORM/schema files, migrations, and seed scripts owned by the service that owns the
  database.
- Keep boundaries strict; configure Mago `guard` layers per service when adopting
  `quality:boundaries`.

## Composer Monorepos / Path Repositories

For a repo of multiple packages (path repositories or a monorepo), keep tool config and
baselines close to the package they measure. Choose one of two patterns:

- **Independent packages with separate lockfiles:** use the matrix workflow as-is and run
  `composer install` inside each package path.
- **Shared/root install:** install once at the root, then run the canonical `composer run`
  tasks per package path.

Keep shared tool config at the root only when all packages can use the same thresholds and
ignore paths. Otherwise keep package-local `mago.toml`/`.jscpd.json` and package-local
baselines. Keep Mago `php-version` close to each package when packages target different PHP
versions.

## Symfony / Shopware Bundles & Integrated Apps

Treat a single deployable with multiple entry points (web + console + worker) as one
integrated app with one gate at the app root:

- Mago formats, lints, and analyzes app, console, worker, and shared code.
- `mago guard` (opt-in) models the app's internal layers (e.g. `Domain`/`Application`/
  `Infrastructure`).
- jscpd, dependency hygiene, freshness, and security run once for the app.
- `.env*` files live at the app root.
- ORM/schema files, migrations, and generated clients live under this app boundary unless the
  repo has a separate database package.
- Exclude framework-generated output explicitly (e.g. `var/cache`, `var/log`, generated
  proxies, `public/build`).

For Symfony/Shopware specifically: enable Mago's built-in Symfony integration; note that its
framework stubs are thinner than a PHPStan + `phpstan-symfony` setup — teams that need that
depth keep/choose PHPStan via adopt-by-slot ([pack.md](pack.md) → *Adopting onto an Existing
Toolchain*).
