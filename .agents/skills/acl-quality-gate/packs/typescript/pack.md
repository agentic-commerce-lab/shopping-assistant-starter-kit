# TypeScript / JavaScript Pack

Concrete binding of the [quality gate methodology](../../references/methodology.md) and the shared [coding conventions](../../references/conventions.md) to the TypeScript/JavaScript toolchain. Read the methodology first (capability contract, modes, baseline mechanics, boundaries) and conventions for the language-neutral coding policy; this pack supplies the tools, configs, commands, and the TS-specific bindings. It follows the [pack-authoring contract](../../references/pack-authoring.md).

Prefer Bun. Keep changes cohesive, and avoid replacing a project's existing framework-specific scripts unless the existing command is broken or missing.

## Capability → Tool

| Capability | Tool | Config asset |
|------------|------|--------------|
| Format | Biome (formatting + import organization) | `assets/configs/biome.json`, `assets/configs/biome.contracts.json` |
| Lint | oxlint with `typeAware`, experimental `typeCheck`, and framework plugins (e.g. `vue`); `oxlint-tsgolint` for type-aware rules | `assets/configs/.oxlintrc.json`, `assets/configs/.oxlintrc.contracts.json` |
| Type / strictness check | TypeScript strict options + `tsc --noEmit` or the framework's checker | `assets/configs/tsconfig.quality.json` |
| Maintainability score | FTA (capped-score; `score_cap` drives the exit code) | `assets/configs/fta.json` |
| Graph health / dead code | Fallow (dead code, cycles, duplication, health, baselines) | `assets/configs/.fallowrc.json` |
| Dependency freshness | `bun outdated` (weekly issue) | `assets/github/dependency-freshness-weekly.yml`, `…-project-matrix-weekly.yml` |
| Hooks | Husky + lint-staged | `assets/hooks/husky-pre-commit`, `assets/hooks/husky-pre-push` |
| CI | GitHub Actions | `assets/github/quality-gate-*.yml` |
| Package manager / runtime | Bun (pinned via `.bun-version`) | `assets/configs/.bun-version` |
| `AGENTS.md` | TS-tuned template | `assets/agents/AGENTS.md` |

FTA is a capped-score tool (no baseline file — raise/lower `score_cap`); Fallow is a baseline-file tool (`fallow-baselines/*.json`). See the methodology's *Baseline mechanics* and [adoption-workflow.md](adoption-workflow.md).

## Detailed References

- [quality-gate-policy.md](quality-gate-policy.md) — TS tool bindings: thresholds-as-config, oxlint rule coverage, the convention→rule mapping, Fallow/FTA mechanics, contract-gating, frontend styling, tsconfig. Read before choosing thresholds or relaxing defaults. (Language-neutral coding conventions live in [conventions.md](../../references/conventions.md).)
- [adoption-workflow.md](adoption-workflow.md) — strict vs baseline rollout, Fallow baseline + FTA cap mechanics. Read before applying to a brownfield repo.
- [project-topologies.md](project-topologies.md) — split projects, monorepos, Bun workspaces, meta-framework apps. Read before applying to anything with more than one project root.
- [development-usage.md](development-usage.md) — TS binding of the shared [implementation-phase workflow](../../references/development-usage.md): the runner, this pack's `quality:*` task names, and TS-specific review items.

## Setup Flow

1. Inspect the project (per the core workflow) and **decide the mode** (methodology → *Mode Decision*). If unsure, run the gate once (step 7) — a wall of pre-existing failures means baseline mode; load [adoption-workflow.md](adoption-workflow.md) and follow it.
2. Classify the topology with [project-topologies.md](project-topologies.md) before touching split projects, monorepos, or meta-framework apps.
3. Copy or merge assets into the project: config assets to the gated project root, GitHub workflows to `.github/workflows/`, hooks to `.husky/`, VS Code assets to `.vscode/`, and the script fragment merged into `package.json`. Merge existing JSON/YAML carefully; do not reformat large files unnecessarily.
4. Install dev dependencies with Bun:

   ```fish
   bun add -d @biomejs/biome oxlint oxlint-tsgolint typescript fallow fta-cli husky lint-staged
   ```

5. Add or merge TypeScript strictness from [assets/configs/tsconfig.quality.json](assets/configs/tsconfig.quality.json). Extend it in plain TS projects; merge individual compiler options into framework-managed configs when extending would fight the framework.
6. Add or merge scripts from [assets/package/package-scripts.fragment.json](assets/package/package-scripts.fragment.json). If the project already has a framework-specific `typecheck` or build validation, keep it and wire `quality` to it.
7. Add GitHub Actions:
   - [assets/github/quality-gate-strict.yml](assets/github/quality-gate-strict.yml) for new projects.
   - [assets/github/quality-gate-baseline.yml](assets/github/quality-gate-baseline.yml) for existing projects with committed Fallow baselines.
   - [assets/github/quality-gate-project-matrix.yml](assets/github/quality-gate-project-matrix.yml) when one repo contains multiple separately gated projects.
   - [assets/github/quality-gate-project-matrix-baseline.yml](assets/github/quality-gate-project-matrix-baseline.yml) for existing multi-project repos with committed Fallow baselines.
   - [assets/github/dependency-freshness-weekly.yml](assets/github/dependency-freshness-weekly.yml) for single-project or meta-framework repos; [assets/github/dependency-freshness-project-matrix-weekly.yml](assets/github/dependency-freshness-project-matrix-weekly.yml) for split/multi-project repos.
8. Create or merge `AGENTS.md` at each gated project root from [assets/agents/AGENTS.md](assets/agents/AGENTS.md) — a required output (methodology → *AGENTS.md Is a Required Output*). Fill every placeholder with the project's real choices (styling, component library, logger, contract/validation library, persistence/migration stack, env-config accessor, framework type-check command, strict vs baseline mode) and delete the guidance comments. Merge, do not overwrite; keep it short.
9. Run the gate:

   ```fish
   bun install
   bun run quality
   ```

   - **Strict mode:** fix every failure before merging the baseline.
   - **Baseline mode:** do not chase a clean run on legacy debt. Generate and commit the Fallow baselines (and any temporary FTA `score_cap`) per [adoption-workflow.md](adoption-workflow.md), then rely on the baseline CI workflow to fail only on regressions. Note that the local `quality:*` scripts are baseline-blind — see [adoption-workflow.md](adoption-workflow.md) → *Local Checks in Baseline Mode*.

## Adopting onto an Existing Toolchain

The principle — adopt by capability slot, keep the tools the user wants kept, and wire the canonical names to them — is in [methodology.md](../../references/methodology.md) → *Adopting onto an Existing Toolchain*. The TypeScript substitutions:

- **Keep their formatter/linter/type checker** (e.g. ESLint + Prettier) and wire the canonical names to them: `format:check` → `prettier --check .`, `lint` → `eslint .`, `typecheck` → their existing checker.
- **Add the layer they're missing**, usually graph/maintainability: FTA (`fta.json` + `quality:fta`) and Fallow (`.fallowrc.json` + `quality:fallow`/`quality:health`/`quality:audit`), plus the CI workflow and the dependency-freshness report. These are linter-agnostic.
- **Skip** the Biome/oxlint configs and `quality:contracts` unless the user opts into them.

## Asset Map

- `assets/configs/biome.json`: Biome formatter and import organization baseline.
- `assets/configs/.oxlintrc.json`: Strict oxlint config (line, complexity, depth, parameter, safety, TypeScript rules).
- `assets/configs/.oxlintrc.contracts.json`: Focused oxlint config for shared contracts, including hard contract file-size caps.
- `assets/configs/.fallowrc.json`: Fallow rules for dead code, dependency hygiene, cycles, stale suppressions, and architecture evidence.
- `assets/configs/fta.json`: FTA maintainability gate with strict new-project scoring.
- `assets/configs/biome.contracts.json`: Focused Biome formatter/import config for shared contracts.
- `assets/configs/tsconfig.quality.json`: Strict TypeScript compiler-option baseline to extend or merge.
- `assets/package/package-scripts.fragment.json`: Scripts to merge into `package.json`.
- `assets/agents/AGENTS.md`: Placeholder-driven `AGENTS.md` template.
- `assets/github/quality-gate-strict.yml`: CI for new projects.
- `assets/github/quality-gate-baseline.yml`: CI for legacy adoption with regression baselines.
- `assets/github/quality-gate-project-matrix.yml`: CI for split projects or monorepos with multiple project roots.
- `assets/github/quality-gate-project-matrix-baseline.yml`: Baseline-aware CI for existing split projects or monorepos.
- `assets/github/dependency-freshness-weekly.yml`: Scheduled dependency freshness workflow that creates/updates one tracking issue.
- `assets/github/dependency-freshness-project-matrix-weekly.yml`: Scheduled dependency freshness issue with per-project sections.
- `assets/configs/.editorconfig`: Cross-editor formatting contract.
- `assets/configs/.bun-version`: Pinned Bun version; single source of truth for CI (`bun-version-file`) and local installs.
- `assets/vscode/vscode-settings.json`: VS Code workspace settings.
- `assets/vscode/vscode-extensions.json`: VS Code extension recommendations.
- `assets/hooks/husky-pre-commit`: Fast pre-commit hook running `lint-staged`. Copy to `.husky/pre-commit`.
- `assets/hooks/husky-pre-push`: Pre-push hook running the full type check. Copy to `.husky/pre-push`.

## Implementation Rules

- Keep Biome responsible for formatting and import organization. Keep oxlint responsible for linting with `typeAware`, experimental `typeCheck`, and framework plugins such as `vue` enabled. Do not add ESLint unless the project already depends on ESLint-only framework rules that oxlint cannot replace.
- Keep TypeScript strictness explicit. Do not overwrite framework-managed `tsconfig` files; extend or merge the quality compiler options.
- Treat FTA and Fallow as complementary: FTA gates concentrated per-file maintainability, while Fallow gates graph-level health, dead code, duplication, complexity, cycles, and dependency hygiene.
- Do not add `.vue`, `.svelte`, or other single-file-component extensions to FTA `extensions`. FTA only parses JS/JSX/TS/TSX; on an SFC its parser fails and logs `interpreted as non-j/tsx`/`Failed to analyze` (noisy but non-fatal — the run still exits 0). Keep SFC logic in composables/hooks/`.ts` files, which FTA scores; SFC-level complexity is covered by oxlint (with the `vue` plugin) and Fallow. The shipped `fta.json` also lists `.vue`/`.svelte` in `exclude_filenames` as a safety net.
- Keep the FTA gate command as plain `fta .`; the `score_cap` in `fta.json` is what drives the exit code, so it gates correctly without extra flags. FTA's default table output is verbose and is interleaved with the non-fatal `interpreted as non-j/tsx`/`Failed to analyze` parser lines above; treat that output as informational, not failures. For quieter CI logs, run `fta . --json` (machine-readable, same `score_cap` exit behavior) and let the workflow surface only the score. Do not try to silence the gate by lowering `score_cap` or excluding source files.
- Treat dependency freshness as weekly visibility by default, not a PR blocker. Use `bun outdated` to expose current, update, and latest versions in a scheduled issue; prefer issue-driven follow-up for patch/minor updates and explicit migration work for majors.
- Gate by deployable or package boundary. Do not merge frontend and backend debt into one baseline when they are separate projects; do not split an integrated meta-framework app into artificial frontend/backend gates.
- In split frontend/backend projects, consider a separately gated `shared` package or directory for Zod 4 API contracts when both sides exchange typed payloads.
- For frontend implementation, use Tailwind by default, reuse existing shared/shadcn-style components, and split pages into smaller cohesive components.
- Keep backend and frontend directory structures cohesive, well named, and organized around domain, feature, or infrastructure ownership instead of generic dumping grounds.
- Apply SOLID pragmatically: keep modules and components focused, expose narrow interfaces at boundaries, prefer composition over inheritance, and depend on abstractions around infrastructure adapters when it reduces coupling. Do not introduce speculative abstractions only to satisfy a principle.
- For new projects, fail CI on strict thresholds immediately. For existing projects, create a baseline and fail CI only on regressions until debt is intentionally cleaned up.
- Allow Fallow baselines or temporary FTA caps only as explicit debt decisions. Document the reason, scope, owner or tracking issue, and cleanup condition; do not use baselines to hide new-project debt. Document any relaxed threshold directly in the config or PR summary.
- Keep workflow commands backed by package scripts. Every `bun run <script>` used by a workflow must exist in the target `package.json`, either from `assets/package/package-scripts.fragment.json` or as an intentional framework-specific replacement.
- Copy `assets/configs/biome.contracts.json` and `assets/configs/.oxlintrc.contracts.json` when adding `quality:contracts`; GitHub Actions and `lint-staged` depend on them.
- Gate the shared contracts boundary with the contract formatter, the contract linter (its file-size caps are the real structural gate), and `typecheck` only — plus dependency freshness when it is its own package. Do not give a `shared`/`contracts` directory its own `fta.json` or `.fallowrc.json`: FTA scores declarative schemas near zero (no signal), and Fallow treats contract exports as public-API entry points so its unused-export detection is neutralized there. A plain contracts directory is already covered by the project-root FTA/Fallow run.
- Keep tests out of this baseline unless the user explicitly asks to add test gates.

## Verification

After editing a target project, run:

```fish
bun install --frozen-lockfile
bun run format:check
bun run lint
bun run typecheck
bun run quality:contracts
bun run quality:fta
bun run quality:fallow
bun run quality:health
bun run quality:audit
```

For dependency freshness visibility, run the non-blocking report separately:

```fish
bun run quality:deps
```

If the lockfile is not committed yet, run `bun install` first, then repeat with `--frozen-lockfile`.
