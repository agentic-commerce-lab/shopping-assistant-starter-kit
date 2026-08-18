# Development Usage (Go)

The language-neutral implementation-phase workflow — daily checks, applying the conventions, before-finishing checks, and the quality-pass process — lives in [references/development-usage.md](../../references/development-usage.md). **Read it first.** This page only binds it to the Go toolchain. The coding standards live in [conventions.md](../../references/conventions.md) and this pack's [quality-gate-policy.md](quality-gate-policy.md).

## Runner and Commands

Run the canonical command names with `mise run <name>`. This pack's `quality:*` sub-gates:

- `quality:tidy` — `go mod tidy -diff`; fails if `go.mod`/`go.sum` are not tidy
- `quality:deadcode` — unreachable functions (`deadcode -test ./...`)
- `quality:vuln` — known vulnerabilities (`govulncheck ./...`)
- `quality:deps` — non-blocking dependency freshness report

`typecheck` is `go vet ./...` + `go build ./...` — Go has no separate strictness config; the linter set is the strictness.

## Read-Only by Default (do not disturb working code)

In Development Mode the gate **observes**; it does not rewrite your code or your setup (methodology → *Do Not Disturb an Existing Setup*). Of this pack's commands:

- **Non-mutating (safe to run anytime):** `format:check`, `lint`, `typecheck`, `quality:tidy`, `quality:deadcode`, `quality:vuln`, `quality:deps`, and the whole `quality` aggregate. They only report — the working tree is unchanged after they run.
- **Mutating (only when you explicitly invoke them):** `format` (rewrites formatting) and `lint:fix` (applies autofixes). Nothing runs these for you.

So running the gate to check a change never breaks or silently adjusts existing code. Auto-loading this skill while you edit a Go project is **not** permission to install tools, swap the project's linter/formatter, or change its config — that is a Setup/Audit-Mode action the user asks for explicitly. When the project already has its own golangci-lint config or runner, follow it and run its checks; propose adopting this gate as a suggestion, never a silent swap.

## Scoping a Quality Pass (Go)

For the shared *Running a Quality Pass* → *Scope* step:

- **Change-scoped:** `golangci-lint run --new-from-rev=origin/<default-branch>` (only issues your change introduced).
- **Path-scopable** (can target a package): `golangci-lint run ./pkg/...`, `go vet ./pkg/...`, `go build ./pkg/...`.
- **Whole-module only** (cannot narrow to a package): `deadcode ./...`, `govulncheck ./...`, `go mod tidy -diff` — read their output filtered to your scope.

## Go-Specific Review Items

Add these to the shared *Review against the conventions* checklist:

- **Error handling:** wrap with `%w` and inspect with `errors.Is`/`errors.As`; handle each error at most once (don't log *and* return); don't panic in library code. See conventions → *Error and Exception Handling*.
- **Type safety:** no bare `interface{}`/`any` or unchecked type assertions outside isolated boundary code; accept interfaces, return concrete types. See conventions → *Code Safety*.
- **Logging:** `log/slog` (or the project logger) over `fmt.Print*`/`log.Print*`; structured fields, no secrets. See conventions → *Logging*.
- **Concurrency:** goroutine and `context` lifecycle — no leaked goroutines, cancel contexts, check `errgroup`/channel teardown.
- **Structure:** respect the `internal/` boundary; keep packages cohesive and domain-oriented rather than `utils`/`helpers` dumps. See conventions → *Directory Structure, Cohesion, and Coupling*.

## Recording Legacy Debt (baseline mode)

When triage records pre-existing debt, this pack uses golangci-lint's diff-based new-issues mode (`--new-from-merge-base`) and temporarily raised complexity/`maintidx` thresholds — see [adoption-workflow.md](adoption-workflow.md). There is no committed baseline file.
