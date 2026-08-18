# Project Topologies

Use this guide before copying assets into repositories with more than one Go module or a `go.work` workspace.

## Decision Rule

Gate by `go.mod` module boundary:

- One module (one `go.mod`): one quality gate at the module root.
- Multi-module repository: one gate per module that has its own `go.mod`, dependency surface, and ownership.
- `go.work` workspace: gate each member module independently; run the gate from each module root.

Do not combine unrelated modules' debt into one run. Do not split a single module into artificial sub-gates.

## Single-Module App or Library

Use the standard Go layout and one set of configs at the module root:

- `cmd/<binary>/` for executable entrypoints (`main` packages — exempt from the `forbidigo` stdout rule).
- `internal/` for packages private to this module (the compiler forbids importing them from other modules — a free architecture boundary).
- `pkg/` (optional) for packages intended for external import.
- Domain/feature packages over generic `utils`/`helpers`/`common` dumping grounds.

Place `.golangci.yml`, `mise.toml`, `lefthook.yml`, and `.editorconfig` at the module root. Set `goimports.local-prefixes` to the module path.

## Multi-Module Repositories and go.work

- Keep a `.golangci.yml` per module, or share one root config referenced with `golangci-lint run -c <path>` when all modules use identical thresholds.
- Use `assets/github/quality-gate-project-matrix.yml` (or `…-baseline.yml`) with one matrix entry per module root, and `defaults.run.working-directory: ${{ matrix.module }}` so each module fails independently.
- Keep dependency freshness per module (the project-matrix freshness workflow loops module roots into one issue).
- Keep `go.work` out of committed module gates when it only exists for local cross-module development; CI runs each module standalone so it resolves its own `go.mod`/`go.sum`.

## Architecture Boundaries (free and configured)

- **`internal/` is compiler-enforced.** Code outside a module (or outside the parent of an `internal/` directory) cannot import it. Call this out as a structural gate that needs no tool.
- **Package import cycles are a compile error**, caught by `go build` — no third-party cycle detector is needed for package-level cycles.
- **`depguard`** adds finer rules on top: deny specific packages (e.g. `github.com/pkg/errors`), or restrict which packages a domain/contracts package may import. Configure per module in `.golangci.yml`.

## Generated and Vendored Code

Exclude generated outputs via `generated: strict` plus explicit paths: `*.pb.go`, `*_gen.go`, `mocks/`, `wire_gen.go`, `bindata.go`, and any `vendor/` tree. Keep excluded paths narrow and enumerated; do not relax global rules to accommodate generated files.

## No Frontend Surface

Unlike the TypeScript pack, a Go module has no frontend styling/component layer to gate. If a repository pairs a Go backend module with a separate frontend project, gate the frontend with the [TypeScript pack](../typescript/pack.md) at its own root and the Go module with this pack at its own root — one gate per deployable boundary.
