# Go Quality Gate Policy

The Go **bindings** for this gate: concrete tools, thresholds-as-config, linter coverage, and Go-specific mechanics. Language-neutral pieces live in the shared docs and are not restated here:

- Mechanical contract, modes, baseline mechanics, boundaries, freshness-as-visibility, hook split, override hygiene → [methodology.md](../../references/methodology.md).
- Coding conventions → [conventions.md](../../references/conventions.md).

Use these defaults as strict recommendations for new Go modules. Relax only when a framework or generated path makes a rule noisy, and scope the override to that path.

## Tool Responsibilities

- mise: tool-version manager (Go + dev tools) and task runner exposing the canonical command names.
- golangci-lint (v2): formatting (`fmt`, via the `formatters` block: gofumpt + goimports + golines) and linting (`run`, ~100 linters). Diff-based regression mode via `--new-from-merge-base`.
- Go toolchain: `go vet ./...` + `go build ./...` are the whole-module type/strictness check; `go mod tidy -diff` gates dependency hygiene.
- deadcode (golang.org/x/tools): unreachable function detection. No failing exit code — gate on non-empty output.
- govulncheck (golang.org/x/vuln): call-graph-aware vulnerability scanning. Blocking sub-gate.
- go-mod-outdated: dependency freshness table over `go list -u -m -json all`.
- lefthook: fast pre-commit (format + lint on staged files) and pre-push (whole-module type check).
- GitHub Actions: authoritative gate via `jdx/mise-action@v2` + `mise run`.

## Recommended Thresholds

These realize the shared threshold-parity targets ([pack-authoring.md](../../references/pack-authoring.md) §3) as Go tool config:

- File length: 400 lines through revive `file-length-limit` (revive >= 1.5.0).
- Function length: 75 lines / 50 statements through `funlen`.
- Public structs per file: 5 through revive `max-public-structs` (curbs god-files).
- Return values: 3 through revive `function-result-limit`.
- Cyclomatic complexity: 10 through `cyclop` (`max-complexity: 10`) and revive `cyclomatic`.
- Cognitive complexity: 12 through `gocognit` (`min-complexity: 12`) and revive `cognitive-complexity`.
- Nesting depth: 4 through `nestif` (`min-complexity: 4`).
- Parameter count: 5 through revive `argument-limit`.
- Maintainability index: `maintidx under: 20` (the capped-score analog of FTA's score cap).
- Duplication: `dupl threshold: 150`.
- Line length: 120 through `lll` and the `golines` formatter.
- Package import cycles: compile error (covered by `go build`).
- Unlisted / untidy dependencies: `go mod tidy -diff` (error).
- Known vulnerabilities: `govulncheck ./...` (error).
- Stale suppressions: keep `//nolint` directives scoped and justified; review catches obvious dead suppressions. (golangci-lint has no first-class stale-`nolint` gate — note this gap and rely on review.)

## Baseline Exceptions

Use diff-based new-issues mode and temporarily raised thresholds as explicit debt decisions, not bypasses — full mechanics in [adoption-workflow.md](adoption-workflow.md). Every exception documents reason, scope, owner or tracking issue, and cleanup condition. CI must still fail on new regressions.

## Convention Bindings (Go)

How the shared [conventions.md](../../references/conventions.md) map onto Go enforcement. Where a rule exists, the convention is part of the mechanical `lint`/`typecheck` gate; otherwise it is an `AGENTS.md` + review convention.

| Convention | Go enforcement |
|---|---|
| No untyped escape hatch (`interface{}`/`any`) | review + errcheck `check-type-assertions`; `forcetypeassert` for unchecked assertions; isolate boundary `any` |
| Logging (no ad-hoc stdout) | `forbidigo` (forbid `fmt.Print*`/`log.Print*`; scoped exclusion for `cmd`/`main`/scripts) + `sloglint` (prefer `log/slog`) |
| Throw only error types, preserve cause | `errorlint` (`%w`, `errors.Is/As`), `wrapcheck` (wrap external errors), `nilerr`; errors are values in Go — panic is discouraged in libraries (review) |
| Handle each error once / don't swallow | `errcheck` (`check-blank`, `check-type-assertions`), revive `unhandled-error`, `nilerr` |
| Strict equality / no unsafe deferred control | n/a (Go has `==` only); `gocritic`/`govet` catch suspicious comparisons; deferred-control-flow review |
| Exhaustive closed-set handling | `exhaustive` (`default-signifies-exhaustive: true`) |
| No unsafe concurrency | `govet` (lostcancel, copylocks), `gosec`, `bodyclose`, `sqlclosecheck`, `rowserrcheck`; goroutine-leak review |
| No lossy coercions | `gosec` (G115 integer conversions), `gocritic`, `unconvert` |
| No magic numbers / repeated literals | `mnd`, `goconst` |
| Constants/env | typed config accessors over scattered `os.Getenv`; review + `AGENTS.md` |
| Naming / idioms | revive (`var-naming`, `receiver-naming`, `error-naming`, `context-as-argument`, `early-return`, `indent-error-flow`, `superfluous-else`), staticcheck ST checks |
| Architecture boundaries | `depguard` import rules (+ `internal/` is enforced by the Go compiler) |
| Reuse over reinvention | review/`AGENTS.md`; check the stdlib first, then existing deps |

Items the linter cannot enforce (review/`AGENTS.md` only): stale-suppression hygiene, contract-shape correctness, accept-interfaces/return-structs, package cohesion / "this package does too much", and most coupling judgments.

## Shared Contracts (Go bindings)

The principle — define a contract once, validate boundary data at runtime, keep contract files small — is in [conventions.md](../../references/conventions.md) → *Shared Data Contracts*. The Go mechanics:

- Go has no single Zod-equivalent. **Existing projects:** follow the project's current approach (`go-playground/validator` struct tags, protobuf/gRPC generated types, or OpenAPI-generated clients).
- **Greenfield:** define request/response/event structs in a dedicated package (e.g. `internal/contracts/<domain>`), validate boundary data with `go-playground/validator` (or generated code), and derive nothing by hand that a generator owns.
- Keep contract files small via the standard file-length + complexity rules; **do not** add a separate contracts golangci config — Go contracts are plain structs already swept by the module-root run. This is the deliberate divergence from the TS pack's `quality:contracts`.
- Keep contracts free of framework, DB, and config imports — enforce with a `depguard` rule on the contracts package when the project wants it mechanical.

## Generated and Framework Code

Exclude generated outputs via the `generated: strict` convention plus explicit paths: `*.pb.go`, `*_gen.go`, `mocks/`, `wire_gen.go`, `bindata.go`, and vendored trees. Keep excluded paths narrow and enumerated.

## Task Runner (mise)

Expose the canonical command names ([pack-authoring.md](../../references/pack-authoring.md) §2) as `mise` tasks. Prefer an existing Makefile/Taskfile when the project has one — wire the canonical names to it rather than forcing mise in.

## Local Hooks

Keep local hooks fast (rationale in [methodology.md](../../references/methodology.md) → *Local Hooks vs CI*):

- Pre-commit: `golangci-lint fmt` + `golangci-lint run` on staged Go files via lefthook's `{staged_files}`. golangci-lint analyzes whole packages, so passing staged files lints the packages that contain them.
- Pre-push: `mise run typecheck` (`go vet` + `go build`), which cannot be scoped to staged files.
- deadcode, govulncheck, and dependency freshness belong in CI.

## Dependency Freshness

Use `go list -u -m -json all | go-mod-outdated -update -direct` weekly, creating/updating one tracking issue; prefer patch/minor first, treat majors as migration work. `govulncheck` is the separate, blocking security gate (not freshness).
