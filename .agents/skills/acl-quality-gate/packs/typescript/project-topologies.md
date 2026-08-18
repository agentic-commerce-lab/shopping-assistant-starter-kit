# Project Topologies

Use this guide before copying assets into projects with more than one app/package or with integrated server/client frameworks.

## Decision Rule

Gate by deployable or package boundary:

- One deployable app or library: one quality gate at the project root.
- Split frontend/backend projects: one gate per project root.
- Split frontend/backend projects with shared contracts: gate the shared contracts as their own package when they have independent scripts and dependencies.
- Monorepo with multiple packages/apps: one gate per package/app that has its own scripts, dependency surface, and ownership.
- Meta-framework app: one gate at the app root, even when the framework contains server routes and frontend code.

Do not combine unrelated debt into one baseline. Do not split one integrated app into artificial frontend/backend gates.

## Split Frontend and Backend Projects

Use separate project-local configs when frontend and backend have different `package.json` files, lockfiles, framework commands, or deployment lifecycles.

Apply per project:

- `biome.json`, `.oxlintrc.json`, `.fallowrc.json`, and `fta.json`
- `tsconfig.quality.json`, extended or merged into that project's TypeScript config
- `package.json` scripts from `package-scripts.fragment.json`
- Fallow baselines in that project's `fallow-baselines/`
- framework-native `typecheck` script
- dependency freshness report in that project's working directory
- `.env*` files and `.env.example` files owned by that project, not by the repository root unless the root is the deployable boundary

Use `assets/github/quality-gate-project-matrix.yml` when both projects live in one repository. Keep matrix entries explicit, for example `frontend` and `backend`, so each project can fail independently.

Use `assets/github/dependency-freshness-project-matrix-weekly.yml` when dependency freshness must be checked separately for each project root.

Shared-contract adjustments:

- Prefer a `shared` package or directory for Zod 4 API contracts consumed by both frontend and backend.
- If `shared` has its own `package.json`, add it to the project matrix with format, basic lint, typecheck, `quality:contracts`, and dependency freshness checks. Copy `biome.contracts.json` and `.oxlintrc.contracts.json` into that package.
- If `shared` is a plain directory without package scripts, include it in the nearest root quality gate and make sure Biome, oxlint, TypeScript, and `quality:contracts` include it. Keep the contract-specific configs at that root.
- Keep contract files cohesive by domain and operation. Avoid one large `api.ts` or `contracts.ts` file.
- Keep shared contracts dependency-light. They may depend on Zod and TypeScript helpers, but must not depend on frontend UI, backend framework, database, or runtime config modules.
- Import shared contracts through package/public entry points, not through deep parent-directory paths.

Backend-specific adjustments:

- Allow `console` only in scripts, CLIs, migrations, and config files through scoped overrides.
- Exclude generated database clients, OpenAPI clients, migration snapshots, build output, and coverage.
- Keep circular dependencies and unlisted dependencies strict.
- Keep ORM/schema files, migrations, generated database clients, and seed scripts owned by the backend project root.
- Keep backend directories cohesive and well named around routes/adapters, application/use cases, domain logic, persistence, integrations, jobs, config, and database ownership.

Frontend-specific adjustments:

- Exclude generated route trees, generated API clients, build output, and framework caches.
- Keep file length, function length, and complexity strict for components, composables, hooks, stores, and state logic.
- Use Tailwind utilities by default. Add regular CSS only when explicitly requested or when needed for global Tailwind/framework plumbing.
- Reuse existing shared components, design-system components, and shadcn/ui-derived components before adding plain HTML in feature code.
- Split page and feature implementations into cohesive smaller components instead of large route/page files.
- Keep frontend directories cohesive and well named around routes/pages, features, shared UI, hooks/composables, state, API clients, and contracts.

## Monorepos and Bun Workspaces

For Bun workspaces, prefer a root install plus per-package quality scripts. Keep baselines close to the package/app they measure.

Choose one of two patterns:

- **Independent projects with separate lockfiles:** use the matrix workflow as-is and run `bun install --frozen-lockfile` inside each project path.
- **Shared workspace lockfile:** run `bun install --frozen-lockfile` at the repository root, then run `bun --cwd <project-path> run quality` per matrix entry.

Keep shared configs at the root only when all packages can use the same thresholds and ignore paths. Otherwise, keep package-local configs.

Keep TypeScript strictness close to each package when packages have different framework configs, JSX settings, module resolution, or project references.

For Bun workspaces with one root `package.json`, `bun outdated` supports `--filter`. Use the single-project dependency freshness workflows at the workspace root when one root report is enough; use filtered or matrix workflows when the issue should show each package/app separately.

## Meta-Framework Apps

Treat Nuxt, SvelteKit, Next.js, and similar meta-frameworks as one integrated app unless the repository also contains separate packages (e.g. a dedicated backend directory/project).

Use one quality gate at the app root:

- Biome formats app, server, shared utilities, and config.
- oxlint checks TypeScript application code and framework files.
- TypeScript strictness is merged into the framework-managed config without replacing generated or framework-required settings.
- FTA measures maintainability across routes, components, composables/hooks, server handlers, and shared modules.
- Fallow analyzes the integrated app graph rather than separate frontend/backend graphs.
- dependency freshness runs once for the app.
- `.env*` files live at the app root because the framework owns both browser-facing and server-side runtime configuration.
- ORM/schema files, migrations, generated database clients, and seed scripts also live under this app boundary unless the repository has a separate database package.
- Shared Zod contracts can live inside the app boundary when they are only used by that integrated app; extract them into a package only when another app/package consumes them.
- Frontend UI inside the meta-framework should still follow Tailwind-first styling, shared component reuse, and small component composition.
- Keep integrated app directories clear even when frontend and server code share one project. Use framework conventions for routes/server files, but avoid unrelated code collecting in generic folders.

Use the framework-native type checker:

- Nuxt: `nuxt typecheck` or `bunx nuxt typecheck`
- Vue: `vue-tsc --noEmit`
- SvelteKit: `svelte-check`
- React/Next.js: keep an existing stronger validation command when present; otherwise use `tsc --noEmit`

For Nuxt, prefer `nuxt typecheck` because Nuxt uses `vue-tsc` and includes server route typing that feeds `$fetch` and `useFetch` type inference.

Exclude framework-generated output explicitly, such as `.nuxt`, `.output`, `.next`, `.svelte-kit`, `dist`, `build`, and `coverage`.
