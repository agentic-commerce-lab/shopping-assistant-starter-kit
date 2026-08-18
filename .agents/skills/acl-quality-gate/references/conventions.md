# Shared Coding Conventions

Language-neutral coding conventions the gate encourages. These are the **opt-in** layer: unlike the mechanical gate in [methodology.md](methodology.md), they are house-style judgments about what good code looks like. Apply them when the project already follows them or the user opts into the house style; do not impose them on a project with a different established style.

Each pack binds these to its language: which logger, which error type, which validation library, which env-prefix convention, and — importantly — **which of these a linter can enforce**. The rule for every pack: map each convention to a lint rule wherever the language's linter supports it (so it becomes part of the mechanical gate), and to the project `AGENTS.md` plus review otherwise. See [pack-authoring.md](pack-authoring.md).

## Code Safety

General rules worth keeping strict in any language. A pack enforces the ones its linter supports and leaves the rest to review.

- No untyped escape hatch (the language's `any`/`dynamic`/`interface{}` equivalent) except in deliberately isolated boundary code.
- Throw only the language's error/exception type or domain-specific subclasses — never strings, plain values, or nullables.
- Preserve the original error when wrapping (e.g. via `cause`); never swallow an error unless the fallback path is intentional, documented, and logged.
- No control-flow escape (`return`/`throw`/`break`/`continue`) from `finally`/ensure/defer blocks that hides the original flow.
- No coercing/loose equality where the language offers a strict form.
- Avoid nested ternaries/conditionals and deep nesting; prefer early returns and cohesive helpers.
- Prefer immutable bindings (`const`/`final`/`val`-equivalent) for values that are not reassigned; avoid reassigning parameters in application code.
- Handle all cases of a closed set (enum/union/sealed type) exhaustively.
- Avoid unsafe asynchronous/concurrent patterns: floating or unawaited work, awaiting non-awaitables, leaked goroutines/tasks, unbound method references.
- Avoid lossy or surprising value coercions (stringifying arbitrary objects, unsafe numeric/array operations, ambiguous truthiness).
- Keep imports/dependencies explicit and type-only where the language distinguishes them; avoid dynamic/runtime imports in statically-resolvable modules.

## Logging

Use the project/framework logger; never ad-hoc stdout (`console`/`print`/`fmt.Println`-style) in application code. Do not prescribe a logger package globally — use the runtime-native logger or the project's existing abstraction. If none exists, propose creating one and let the human decide.

| Concern | Do | Don't |
|---------|-----|-------|
| Transport | project/framework logger | ad-hoc stdout in app code |
| Shape | structured fields | string concatenation |
| Level | `debug`/`info`/`warn`/`error`, chosen intentionally | one level for everything |
| Secrets | redact | log tokens, credentials, full payloads, payment data, PII |
| Tracing | attach request/job/trace IDs when available | drop correlation context |
| Errors | log the error object or `cause` | log only the message |

Allow ad-hoc stdout only in scripts, CLIs, migrations, config files, and intentionally local tooling or debugging.

## Error and Exception Handling

Throw the language's error type or domain-specific subclasses only, and keep the throwing style consistent across the codebase.

- Do not throw strings, plain objects, booleans, numbers, or nullable values.
- Preserve the original error when wrapping, preferably through `cause` or the language equivalent.
- Do not swallow errors unless the fallback path is intentional, documented by the code, and logged.
- Use typed/domain errors for expected business failures; reserve generic errors for unexpected failures.
- Narrow untyped caught values before reading their properties.
- Do not escape control flow from `finally`/ensure/defer blocks — it hides the original control flow.

## Constants and Environment Configuration

Avoid duplicating configuration-like constants across files. When the same literal value, URL, timeout, limit, feature-flag key, storage key, external identifier, or business threshold appears in multiple places, move it to the smallest sensible shared owner:

- Use a project-local constants module for stable values that belong to the codebase.
- Use environment variables for values that differ by deployment, environment, tenant, infrastructure, credentials, or operational rollout.
- Use typed configuration accessors instead of reading raw environment variables throughout application code.
- Keep naming explicit and domain-oriented; avoid generic names such as `DEFAULT_VALUE`, `URL`, or `LIMIT` outside tiny local scopes.
- Do not introduce a shared global constants package for unrelated domains. Centralize at the nearest cohesive boundary first.

Review duplicated literals before adding new constants. A duplicate value is acceptable only when the concepts are intentionally independent and changing one should not change the other.

Environment file expectations:

- Keep environment files at the owning deployable or package root, not scattered inside feature folders or unrelated project roots.
- In split frontend/backend repositories, frontend and backend env files belong in their respective roots; in integrated apps, at the app root unless the repo has separate deployable packages.
- Commit an example file with safe placeholders; never commit real secrets.
- Keep client-public variables clearly separated from server-only variables, using the framework's public-prefix convention only for values safe to expose to clients.
- Document required variables close to the owning project, and remove stale variables when code no longer reads them.

## Database Persistence

When a project uses a database and the data model is likely to grow beyond a tiny single-table use case, recommend an ORM or typed query/schema layer plus a proper migration workflow before application code spreads raw SQL or ad hoc schema changes. Use the project's existing persistence stack when one exists; for new projects choose a tool that fits the runtime, framework, database, and team — do not prescribe one global stack for every repository.

- Keep schema definitions, generated database clients, migration files, and seed scripts in the owning backend/service/package/app root.
- Version schema changes as migrations. Do not rely on manual production changes, untracked SQL snippets, or runtime auto-sync in deployed environments.
- Make migrations reviewable, deterministic, and safe to run in CI/staging before production.
- Exclude generated ORM/database client output from formatting and maintainability/health gates through explicit generated-path ignores.
- Prefer typed access to records and query results; avoid scattering raw SQL unless the project intentionally uses a typed SQL/query-builder approach.
- Add migration commands to the project's task scripts when it owns a schema.
- Treat schema drift as a quality risk: ship schema, migration, generated-client, and application changes together.

## Shared Data Contracts

When two sides exchange typed payloads (frontend/backend, service/service, producer/consumer), define the contract once and validate boundary data at runtime — do not rely on static types alone for data crossing process, network, storage, queue, or user-input boundaries.

- Prefer a dedicated shared package or directory for contracts when more than one side consumes them; follow the project's existing validation/contract library.
- Define the schema once and derive static types from it, so both sides share one source of truth.
- Validate request, response, event, and shared payload boundaries with schemas.
- Keep contracts free of UI code, infrastructure code, database clients, framework request/response objects, secrets, and environment reads.
- Do not create one giant catch-all contract file; split by domain and operation into small cohesive files, and keep public exports intentional (no catch-all barrels that leak internals or create cycles).
- Version or deprecate contract changes deliberately when they can break older deployments.

(Each pack binds the concrete library and the structural gate it uses to keep contract files small.)

## Directory Structure, Cohesion, and Coupling

Aim for **high cohesion and loose coupling**: each module/directory owns one clearly related set of responsibilities, and depends on others through narrow, intentional interfaces rather than reaching into their internals. Keep directories cohesive, well named, and structured around ownership and domain intent. Do not let projects drift into flat `utils`, `helpers`, `components`, `services`, or `api` dumping grounds.

- Prefer domain or feature boundaries over technical buckets when code belongs to a business capability.
- Keep shared technical infrastructure explicit and narrow (e.g. `config`, `logger`, `database`, `http`, `auth`, `contracts`, `ui`).
- Name directories after what they own, not vague implementation details. Avoid generic names such as `common`, `misc`, `stuff`, `new`, `old`, `temp`.
- Keep index/barrel/package entry files small and intentional. Do not use them to hide circular dependencies or expose internals accidentally.
- Place code at the smallest cohesive boundary that owns it. Do not move unrelated code into global shared folders just because two files import it once.
- Keep coupling loose across boundaries: depend on a module's public entry point, not its internal files; let dependencies point inward toward domain logic, not outward into framework or infrastructure detail; prefer one-directional over mutual dependencies. (A graph/architecture tool is the mechanical backstop for cycles, but most coupling is a review judgment.)
- Keep directory breadth bounded. When a directory accumulates many files — even cohesive ones — split it into focused sub-modules by sub-domain, layer, or capability rather than letting one folder grow into a long flat list. A directory that needs scrolling to scan is a signal to split.
- When adding a directory, check existing naming and layering conventions first and extend them instead of inventing a parallel structure.
- Keep page/route/entry files thin and compose them from smaller cohesive units; avoid single large files that accumulate unrelated behavior (`service`, `controller`, `repository`, `routes`, `components` catch-alls).

## Dependencies and Reuse

Prefer reusing existing, well-maintained code over reinventing it — and prefer not adding a dependency at all for something trivial. Both directions matter.

- Before implementing non-trivial functionality (parsing, validation, retries, date/time math, auth, serialization, HTTP, crypto, etc.), search for an established library that already solves it. A maintained, widely-used library is usually better than a bespoke implementation you then own, debug, and maintain.
- Look in this order: the language's standard/runtime library, then the project's existing dependencies and internal shared modules, then a new external dependency.
- When evaluating a candidate, weigh fit, maintenance health (recent releases, active issues), adoption, security history, and license. Avoid unmaintained or abandoned packages — apply the same maintenance bar the gate applies to its own toolchain.
- Do not pull in a dependency for something the standard library or a few lines of clear code already cover; every dependency is a long-term cost (supply chain, upgrades, breakage).
- Build a custom implementation only when no suitable library exists or none fits the constraints — and say why (a one-line note on what was evaluated and why it didn't fit).
- Prefer one well-chosen library over several overlapping ones, and remove a dependency once its last use is gone (the graph/dead-code and dependency-freshness gates help surface this).

## Design Principles

Use SOLID as review heuristics, not as a reason to add ceremony. The mechanical gate already covers the measurable symptoms (file/function size, complexity, coupling, cycles, unsafe typing, dead code); human review covers what tools cannot.

- Check each module has one clear reason to change.
- Check boundaries expose narrow interfaces.
- Prefer composition where it would be clearer than inheritance.
- Isolate infrastructure dependencies behind adapters/ports where that reduces coupling.
- Do not introduce abstract base classes, generic service layers, or dependency wrappers speculatively.
- Add an abstraction only when it removes real duplication, protects a boundary, or makes testing/replacement meaningfully easier.
