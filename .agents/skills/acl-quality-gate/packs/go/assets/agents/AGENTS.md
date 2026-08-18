<!--
  ACL quality-gate template. Copy to the module root (or each gated module root in a
  multi-module repo) and MERGE into any existing AGENTS.md — do not overwrite.
  Fill every {{placeholder}} with the project's real choice and delete the guidance comments.
  Keep this file short: it loads into agent context on every task.
-->

# Project Conventions

This project runs the ACL quality gate ({{strict | baseline}} mode). Follow these conventions
when writing or refactoring code; CI enforces them. Full rationale lives in the quality-gate
policy, not here.

## Commands

- Format + lint a change: `mise run format:check && mise run lint`
- Add type checking for code changes: `mise run typecheck` (`go vet ./...` + `go build ./...`)
- Dead code / dependency / cleanup changes: `mise run quality:deadcode && mise run quality:tidy`
- Security check: `mise run quality:vuln`
- Broad refactor or gate change: `mise run quality`
- Run the narrowest useful check for the change; rely on CI as the authority.

## Go

- The linter set is the strictness. Do not bypass it with bare `interface{}`/`any` or unchecked type assertions outside isolated boundary code.
- Accept interfaces, return concrete types. Don't take a pointer to an interface.
- Keep functions under the configured length/complexity; prefer early returns over deep nesting.

## Logging

- Use the project logger ({{log/slog | zap | zerolog}}); never `fmt.Print*` or the legacy `log` package in application code (allowed in `cmd`/`main`/scripts/migrations).
- Structured fields, intentional levels, no secrets/PII; attach request/trace IDs when available.

## Error handling

- Return errors as values; wrap with `fmt.Errorf("...: %w", err)` and inspect with `errors.Is`/`errors.As`.
- Handle each error at most once — do not log *and* return the same error.
- Don't panic in library code; reserve panic for truly unrecoverable program state.
- Check every returned error; don't assign to `_` to silence it.

## Shared contracts

- Validate boundary data at runtime with {{go-playground/validator | generated protobuf/OpenAPI types}}; define request/response/event structs once in {{internal/contracts}}.
- Keep contract files small and domain-oriented; keep framework/DB/config imports out of the contracts package.

## Database & persistence

- Use the project's persistence stack ({{sqlc | GORM | ent | database/sql + migrations}}) and a real migration workflow ({{golang-migrate | goose | atlas}}); no ad-hoc schema changes in deployed environments.
- Ship schema, migration, generated-code, and application changes together; keep them in the database-owned package.

## Environment config

- Read config through a typed accessor ({{config struct loaded once at startup}}); don't scatter raw `os.Getenv` reads through the code.
- Keep `.env*` at the owning module root; commit a `.env.example` with safe placeholders; never commit real secrets.

## Structure & constants

- High cohesion, loose coupling: each package owns one related responsibility; depend on a package's public API, not its internals (`internal/` enforces this).
- Layout: `cmd/<binary>` for entrypoints, `internal/` for private packages, domain/feature packages over generic `utils`/`helpers`/`common` dumps.
- Before adding a repeated literal, URL, limit, timeout, flag key, or identifier, reuse the existing constant or typed config.
- Reuse before reinventing: prefer the stdlib, then existing deps, then a well-maintained new dependency — but don't add one for a few lines of clear code.

# Review Instructions

When reviewing code here (PR/branch review, or a requested "quality pass" or "clean up"), invoke the `acl-quality-gate` skill and follow its quality-pass workflow: run the in-scope checks from **Commands** above, then read the change against the conventions in this file — tools can't judge whether logging, error handling, contracts, or structure are *correct*, only a read can. Treat CI as the final authority; never relax a threshold to make a review pass — record genuine pre-existing debt as a baseline instead.
