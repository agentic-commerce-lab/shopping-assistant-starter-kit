# Go Pack

Concrete binding of the [quality gate methodology](../../references/methodology.md) and the shared [coding conventions](../../references/conventions.md) to the Go toolchain. Read the methodology first (capability contract, modes, baseline mechanics, boundaries) and conventions for the language-neutral coding policy; this pack supplies the tools, configs, commands, and the Go-specific bindings. It follows the [pack-authoring contract](../../references/pack-authoring.md).

Prefer `mise` as the single tool-version manager and task runner. Keep changes cohesive; do not replace a project's existing Makefile/Taskfile targets unless they are broken or missing — wire the canonical names to whatever the project already runs.

## Capability → Tool

| Capability | Tool | Config asset |
|------------|------|--------------|
| Format | `golangci-lint fmt` — gofumpt + goimports + golines (line length 120) | `assets/configs/.golangci.yml` (`formatters:` block) |
| Lint | `golangci-lint run` — errcheck, govet, staticcheck, ineffassign, unused, errorlint, gocritic, revive, gosec, sloglint, exhaustive, goconst, mnd, depguard, bodyclose, nilerr, forbidigo, wrapcheck, unconvert, unparam, misspell | `assets/configs/.golangci.yml` (`linters:` block) |
| Type / strictness check | `go vet ./...` + `go build ./...` (staticcheck via golangci-lint adds strictness) | — (Go toolchain; no separate strictness config) |
| Maintainability score | **Merged into Lint:** cyclop (cyclomatic 10), gocognit (cognitive 12), funlen (~75 lines), nestif (depth 4), revive `argument-limit` (5) / `file-length-limit` (400) / `max-public-structs` (5), `maintidx` (capped maintainability index) | `assets/configs/.golangci.yml` (`linters.settings`) |
| Graph health / dead code | `deadcode ./...` (official `golang.org/x/tools`) + golangci `unused`/`dupl`/`depguard` + `go build` (package import cycles are a compile error) + `go mod tidy -diff` (dependency hygiene) | `assets/configs/.golangci.yml` + `assets/configs/mise.toml` |
| Security | `govulncheck ./...` (official `golang.org/x/vuln`) | `assets/configs/mise.toml` (`quality:vuln`) |
| Dependency freshness | `go list -u -m -json all \| go-mod-outdated` (weekly issue) | `assets/github/dependency-freshness-weekly.yml`, `…-project-matrix-weekly.yml` |
| Hooks | lefthook | `assets/configs/lefthook.yml` |
| CI | GitHub Actions (`jdx/mise-action@v2`) | `assets/github/quality-gate-*.yml` |
| Package manager / toolchain | `mise` — pins Go + dev tools; go.mod `toolchain` directive is the language-native pin both mise and CI read | `assets/configs/mise.toml` |
| `AGENTS.md` | Go-tuned template | `assets/agents/AGENTS.md` |

**Merged slots (stated per [pack-authoring.md](../../references/pack-authoring.md) §1):** Format and Lint are the same binary (golangci-lint). Maintainability score is merged into Lint — Go has no FTA-style single-number scorer, so the complexity linters realize the threshold-parity numbers, with `maintidx` as the capped maintainability-index analog.

**Go-specific slot:** Security (`govulncheck`) is an extra blocking sub-gate Go affords through its official vulnerability database; it has no TypeScript-pack analog.

**Baseline shapes:** golangci-lint is **diff-based** (`--new-from-merge-base`, no committed snapshot file); complexity/`maintidx` are **capped-score** (thresholds in `.golangci.yml`); vet/build/deadcode/govulncheck have no baseline (fix, scope a `//nolint`, or exclude a generated path). See the methodology's *Baseline mechanics* and [adoption-workflow.md](adoption-workflow.md).

## Detailed References

- [quality-gate-policy.md](quality-gate-policy.md) — Go tool bindings: thresholds-as-config, the golangci-lint linter/formatter coverage, the convention→linter mapping, slog/forbidigo/depguard mechanics, and where Go diverges (no tsconfig, merged maintainability, shared-contract approach). Read before choosing thresholds or relaxing defaults. (Language-neutral coding conventions live in [conventions.md](../../references/conventions.md).)
- [adoption-workflow.md](adoption-workflow.md) — strict vs baseline rollout, golangci-lint diff-based new-issues mode, capped complexity. Read before applying to a brownfield repo.
- [project-topologies.md](project-topologies.md) — multi-module repos, `go.work` workspaces, `cmd/`+`internal/` layout, monorepos. Read before applying to anything with more than one module.
- [development-usage.md](development-usage.md) — Go binding of the shared [implementation-phase workflow](../../references/development-usage.md): the runner (`mise run`), this pack's `quality:*` task names, and Go-specific review items.

## Setup Flow

1. Inspect the project (per the core workflow) and **decide the mode** (methodology → *Mode Decision*). If unsure, run the gate once (step 9) — a wall of pre-existing failures means baseline mode; load [adoption-workflow.md](adoption-workflow.md) and follow it.
2. Classify the topology with [project-topologies.md](project-topologies.md) before touching multi-module repos or `go.work` workspaces.
3. Copy or merge assets: `.golangci.yml`, `mise.toml`, `lefthook.yml`, `.editorconfig` to the gated module root; GitHub workflows to `.github/workflows/`. Merge existing YAML/TOML carefully; set `formatters.settings.goimports.local-prefixes` (and any `depguard` allow/deny rules) to the project's module path from `go.mod`.
4. Ensure `go.mod` declares a `go` and `toolchain` version, and set the matching `go` version in `mise.toml` `[tools]`. Install the toolchain and dev tools:

   ```fish
   mise install
   mise run lefthook-install   # registers git hooks
   ```

5. Verify the canonical tasks exist: `mise tasks ls` must list `format`, `format:check`, `lint`, `lint:fix`, `typecheck`, `quality`, `quality:deps`, and the `quality:*` sub-gates.
6. Validate the linter config:

   ```fish
   golangci-lint config verify
   ```

7. Add GitHub Actions:
   - [assets/github/quality-gate-strict.yml](assets/github/quality-gate-strict.yml) for new modules.
   - [assets/github/quality-gate-baseline.yml](assets/github/quality-gate-baseline.yml) for existing modules (regression-only via `--new-from-merge-base`).
   - [assets/github/quality-gate-project-matrix.yml](assets/github/quality-gate-project-matrix.yml) when one repo contains multiple gated modules.
   - [assets/github/quality-gate-project-matrix-baseline.yml](assets/github/quality-gate-project-matrix-baseline.yml) for existing multi-module repos.
   - [assets/github/dependency-freshness-weekly.yml](assets/github/dependency-freshness-weekly.yml) for single-module repos; [assets/github/dependency-freshness-project-matrix-weekly.yml](assets/github/dependency-freshness-project-matrix-weekly.yml) for multi-module repos.
8. Create or merge `AGENTS.md` at each gated module root from [assets/agents/AGENTS.md](assets/agents/AGENTS.md) — a required output (methodology → *AGENTS.md Is a Required Output*). Fill every placeholder with the project's real choices (logger, validation library, persistence stack, env-config accessor, strict vs baseline mode) and delete the guidance comments. Merge, do not overwrite; keep it short.
9. Run the gate:

   ```fish
   mise run quality
   ```

   - **Strict mode:** fix every failure before merging.
   - **Baseline mode:** do not chase a clean run on legacy debt. Switch the lint step to `--new-from-merge-base` and temporarily raise complexity/`maintidx` thresholds per [adoption-workflow.md](adoption-workflow.md), then rely on the baseline CI workflow to fail only on regressions.

## Adopting onto an Existing Toolchain

The principle — adopt by capability slot, keep the tools the user wants kept, and wire the canonical names to them — is in [methodology.md](../../references/methodology.md) → *Adopting onto an Existing Toolchain*. The Go substitutions:

- **Keep their runner** (Makefile / Taskfile) and define the canonical names as targets that call the same tools, instead of forcing `mise` in. CI's "verify the commands exist" step checks only the names.
- **Keep their golangci-lint config** if they have one; add only the missing capability slots — usually Security (`govulncheck`), dead code (`deadcode`), and dependency freshness. These compose with any existing setup.
- **Skip** swapping their formatter/linter unless the user asks; gofmt-family output is stable across gofmt/gofumpt so formatting churn is low-risk, but still treat a swap as an explicit request.

## Asset Map

- `assets/configs/.golangci.yml`: golangci-lint v2 config — `linters` (rule surface + complexity/maintainability settings + exclusions) and `formatters` (gofumpt + goimports + golines).
- `assets/configs/mise.toml`: tool-version pins (Go + golangci-lint + deadcode + govulncheck + go-mod-outdated + lefthook) and the canonical `[tasks]` (the command vocabulary).
- `assets/configs/lefthook.yml`: pre-commit (format + lint on staged Go files) and pre-push (whole-module `typecheck`).
- `assets/configs/.editorconfig`: cross-editor formatting contract (tabs for Go, matching gofmt).
- `assets/agents/AGENTS.md`: placeholder-driven `AGENTS.md` template.
- `assets/github/quality-gate-strict.yml`: CI for new modules.
- `assets/github/quality-gate-baseline.yml`: regression-only CI (`--new-from-merge-base`).
- `assets/github/quality-gate-project-matrix.yml`: CI for repos with multiple gated modules.
- `assets/github/quality-gate-project-matrix-baseline.yml`: regression-only multi-module CI.
- `assets/github/dependency-freshness-weekly.yml`: scheduled freshness workflow that creates/updates one tracking issue.
- `assets/github/dependency-freshness-project-matrix-weekly.yml`: scheduled freshness issue with per-module sections.

## Implementation Rules

- Keep golangci-lint responsible for both formatting (`fmt`, via the `formatters` block) and linting (`run`). Do not add a separate gofmt/gofumpt invocation — the formatters run inside golangci-lint.
- Keep the strictness check as `go vet ./...` + `go build ./...`. Go has no tsconfig-style strictness levels; the linter set *is* the strictness. Do not invent a "strict mode" config file.
- Treat the complexity linters as the maintainability gate. There is no FTA-style scorer; do not look for one. `maintidx` is the capped maintainability-index analog — raise its `under` threshold temporarily for legacy debt, never disable it to hide new debt.
- Package import cycles are a compile error, so `go build` covers that slot natively. Do not add a third-party cycle detector for package-level cycles.
- `deadcode` does not set a failing exit code; the `quality:deadcode` task must fail when its output is non-empty (the shipped task does this).
- Gate by module boundary (`go.mod`). One gate per module. Do not merge unrelated modules' debt into one run; do not split a single module into artificial sub-gates.
- Logging: forbid `fmt.Print*` and `log.Print*` in application code (forbidigo) and prefer stdlib `log/slog`; allow stdout in `main`, CLIs, scripts, and generated code via scoped exclusions.
- Exclude generated code with `formatters.exclusions.generated: strict` and `linters.exclusions.generated: strict` (the `// Code generated … DO NOT EDIT.` convention), plus explicit paths for `mocks/`, `*.pb.go`, and similar.
- Keep tests out of the maintainability/security thresholds (exclude `_test.go` from funlen/dupl/gosec/cyclop/gocognit/maintidx) but keep them visible to errcheck/govet so test bugs still surface.
- Allow raised thresholds or `//nolint` directives only as explicit debt decisions: document reason, scope, owner or tracking issue, and cleanup condition. Prefer scoped `//nolint:<linter> // reason` over global disables, and generated-path excludes over rule relaxation.
- Keep workflow commands backed by `mise` tasks. Every `mise run <name>` used by a workflow must exist in `mise.toml`.
- Shared data contracts: Go has no single Zod-equivalent. Bind the *principle* to review + `AGENTS.md` and, where a project validates boundary data, the project's chosen validator (commonly `go-playground/validator`) or generated code (protobuf/OpenAPI). Keep contract/DTO files small via the standard file-length and complexity rules — no separate contracts config. See [quality-gate-policy.md](quality-gate-policy.md) → *Shared Contracts*.

## Verification

After editing a target module, run:

```fish
mise install
golangci-lint config verify
mise run format:check
mise run lint
mise run typecheck
mise run quality:tidy
mise run quality:deadcode
mise run quality:vuln
```

For dependency freshness visibility, run the non-blocking report separately:

```fish
mise run quality:deps
```

Or run the whole gate at once:

```fish
mise run quality
```
