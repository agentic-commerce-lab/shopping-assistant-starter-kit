# PHP Quality Gate Policy

The PHP **bindings** for this gate: the concrete tools, thresholds-as-config, rule
coverage, and PHP-specific mechanics. The language-neutral pieces live in the shared docs
and are not restated here:

- Mechanical contract, modes, baseline mechanics, boundaries, freshness-as-visibility,
  hook split, override hygiene → [methodology.md](../../references/methodology.md).
- Coding conventions (code safety, logging, error handling, constants/env, persistence,
  shared-contract principle, directory/cohesion/coupling, design principles) →
  [conventions.md](../../references/conventions.md).

Use these defaults as strict recommendations for new PHP projects. Relax only when a
framework or generated code path makes a rule noisy, and scope the override to that path.

## Tool Responsibilities

- **Mago** (single Rust binary; no PHP runtime needed to run it): formatting
  (`mago fmt`), linting (`mago lint`, incl. cyclomatic complexity, parameter count,
  nesting depth, banned debug calls, unused imports), whole-program type/static analysis
  (`mago analyze`), and opt-in architecture boundaries (`mago guard`). Lint and analyze
  each carry their own baseline (`lint-baseline.toml`, `mago-analysis-baseline.toml`).
- **check_file_length.php**: dependency-free file-length gate (~400 LOC) — Mago has no
  module-length rule. Backs `quality:filesize`.
- **jscpd** (Rust): copy-paste / duplication detection. **Capped-score** shape — the
  `--threshold` percentage drives the exit code. Backs `quality:dupes`.
- **shipmonk/composer-dependency-analyser**: unused + missing/shadow Composer
  dependencies. Backs `quality:depcheck`.
- **composer audit**: dependency-vulnerability gate. Backs `quality:security`.
- **composer outdated**: non-blocking dependency-freshness report. Backs `quality:deps`.
- **phpcca** (Phauthentic Cognitive Code Analysis): **advisory only** — measures
  cognitive complexity, method length, and churn hotspots. Backs the non-blocking
  `quality:maintainability` / `quality:hotspots`.
- **CaptainHook**: Composer-installed Git hooks (pre-commit format+lint on staged PHP;
  pre-push whole-program analyze).
- **GitHub Actions**: the authoritative gate.

## Recommended Thresholds

These realize the shared threshold-parity targets ([pack-authoring.md](../../references/pack-authoring.md) §3) as PHP tool config:

- Cyclomatic complexity: 10 — Mago `cyclomatic-complexity` `threshold = 10`.
- Parameter count: 5 — Mago `excessive-parameter-list` `threshold = 5`.
- Nesting depth: 4 — Mago `excessive-nesting` `threshold = 4`.
- File/class length: ~400 — `check_file_length.php` (`quality:filesize`).
- Code duplication: jscpd `threshold` (percentage) — 3% default; raise to absorb legacy
  debt, lower over time.
- Type strictness: `mago analyze` — **no PHPStan-style level system**; core type-checking
  is always-on (≈ PHPStan `max`) and strictness is per-category `[analyzer]` toggles (see
  *Mago Configuration*). Plus `declare(strict_types=1)` via the Mago `strict-types` rule.
- Circular dependencies / boundaries: Mago `guard` (layered boundaries; opt-in). **Note:**
  this enforces forbidden back-edges, not arbitrary cycle detection — see *Capability Gaps*.
- Unused / missing dependencies: composer-dependency-analyser.
- **Method length (~75)** and **cognitive complexity**: measured by phpcca, **advisory**
  (not hard-gated) — see *phpcca is advisory*.

## phpcca is advisory

`phpcca analyse` exits non-zero only on pipeline errors, never on a metric breach, and its
"cognitive complexity" is a weighted composite, not a Sonar-style "12". Its baseline drives
*display* (Δ regressions) only. So phpcca **measures and surfaces** cognitive complexity and
method length but does **not** hard-fail CI. Run it as the non-blocking
`quality:maintainability` / `quality:hotspots`; never put it in the blocking `quality`
aggregate or a blocking CI step. Keep `showCyclomaticComplexity`/`showHalsteadComplexity`
`false` so it owns cognitive + `metrics.lineCount` (method length) only and does not
double-report against Mago's cyclomatic/nesting rules.

## Baseline Mechanics

Baseline shapes (methodology → *Baseline mechanics*):

- **Baseline-file** tools: `mago lint` (`lint-baseline.toml`), `mago analyze`
  (`mago-analysis-baseline.toml`), `mago guard` (its own baseline). Generate with
  `--generate-baseline --baseline <file>`; commit; treat as accepted debt.
- **Capped-score** tool: jscpd — no baseline file; raise `--threshold` to the current
  duplication percentage, then lower it back over time.
- **No baseline concept**: `mago fmt` (fix it), `check_file_length.php`, `composer audit`,
  `composer outdated`, composer-dependency-analyser (use its ignore config).
- phpcca's baseline is display-only and never gates — not a gating baseline.

Every exception documents the reason, scope, owner/tracking issue, and cleanup condition.
CI still fails on new regressions outside the accepted baseline.

## Convention Bindings (PHP)

How the shared [conventions.md](../../references/conventions.md) map onto PHP enforcement.
Where a rule exists, the convention is part of the mechanical `lint`/`typecheck` gate;
otherwise it is an `AGENTS.md` + review convention.

| Convention | PHP enforcement |
|---|---|
| No untyped escape hatch (`mixed`) | `mago analyze` strict; boundary `mixed` only, deliberately isolated |
| `declare(strict_types=1)` | Mago lint `strict-types` rule (`allow-disabling = false`) |
| Logging (no `echo`/`var_dump`/`print_r`/`dd`) | Mago lint `no-debug-symbols` (set `level = "error"`; default is `note`); PSR-3 logger; scoped allowance for scripts/CLIs/migrations |
| Throw only `Throwable`, preserve previous, narrow caught | `mago analyze` + review |
| Exhaustive `match` | `mago analyze` |
| Function complexity, params, nesting | Mago `cyclomatic-complexity`, `excessive-parameter-list`, `excessive-nesting` |
| File length (~400) | `check_file_length.php` (`quality:filesize`) |
| Method length / cognitive complexity | phpcca (advisory, not gated) |
| Constants/env | typed config accessors over raw `getenv()`/`$_ENV`; review |
| Reuse over reinvention | review / `AGENTS.md`; search Packagist for a maintained library before building non-trivial functionality |

Items the toolchain cannot hard-enforce (review / `AGENTS.md` only): preferring `readonly`
for immutable bindings, member ordering, and the structural file-size cap for shared
contracts (PHP has no file-size lint rule — same as Python).

## Capability Gaps (no silent drops)

PHP has no single repo-graph-intelligence tool equivalent to the TypeScript pack's Fallow.
These are deliberately gapped or advisory, not silently dropped:

| Gap | Status | Opt-in if a project needs it |
|---|---|---|
| Cognitive complexity | measured by phpcca, **advisory** (no exit-code gate) | a CI report-parser gate on phpcca's SARIF/JUnit output |
| Method length (LOC) | measured by phpcca, **advisory** | as above |
| NPath complexity | review only | — |
| Cross-file unused public API | review only (Mago analyze is private/flow-scoped) | shipmonk/dead-code-detector — but it requires PHPStan (excluded here) |
| Maintainability-index single score (0–100 cap) | review only | none maintained (PHPMetrics is report-oriented + slowing) |
| Repo health single score | review only | none |
| True circular-dependency detection | deviation — Mago guard does layered boundaries, not arbitrary cycles | — (the only direct tools are abandoned) |

`jscpd` closes duplication, composer-dependency-analyser closes dependency hygiene, and
`check_file_length.php` closes file length — gaps the Python pack leaves open.

## Generated and Framework Code

Exclude generated outputs explicitly rather than relaxing global gates: protobuf/gRPC
output, build/dist/var/cache, generated ORM proxies/migration artifacts, and vendored
clients. Keep the excluded paths enumerated and narrow in the Mago `[source] excludes`,
the jscpd `ignore`, and the file-length script paths.

## Tasks

Expose the canonical command names ([pack-authoring.md](../../references/pack-authoring.md)
§2) as Composer scripts (merge `assets/configs/composer-scripts.fragment.json` into
`composer.json`). The blocking `quality` aggregate runs, in order: `format:check`, `lint`,
`typecheck`, `quality:filesize`, `quality:dupes`, `quality:depcheck`, `quality:security`.
`quality:boundaries` is opt-in (configure Mago guard layers first). `quality:maintainability`,
`quality:hotspots`, and `quality:deps` are non-blocking advisories — never in the aggregate.
Do not add test commands to `quality`; tests are out of scope unless the user opts in.

## Local Hooks

Keep local Git hooks fast (the pre-commit/pre-push split rationale is in
[methodology.md](../../references/methodology.md) → *Local Hooks vs CI*):

- Pre-commit: `mago fmt` + `mago lint` on **staged** PHP files via CaptainHook
  (`{$STAGED_FILES|of-type:php}`).
- Pre-push: `mago analyze` (whole-program; cannot be scoped to staged files).
- jscpd, composer-dependency-analyser, composer audit, the file-length gate, dependency
  freshness, and phpcca run in CI by default.

## Mago Configuration

Merge `assets/configs/mago.toml` into the gated project and set `[source] paths` to the
real source root.

- **No level system** — strictness is per-category `[analyzer]` toggles. The shipped config
  enables `find-unused-parameters`, `analyze-dead-code`, `check-property-initialization`,
  `check-missing-override`, `check-use-statements`, and `strict-array-index-existence`, and
  keeps `check-throws` on but tamed via `unchecked-exceptions` + a path-scoped `ignore` for
  `tests/`.
- `no-debug-symbols` ships at `level = "error"` (its default is `note`, which would not
  gate).
- Pin `version` (Mago) and set `php-version` (target PHP) in `mago.toml` — Mago does **not**
  read the PHP version from `composer.json`.

## Dependency Freshness

Use `composer outdated --direct` to make stale direct dependencies visible (the
weekly-visibility-not-a-blocker policy is in
[methodology.md](../../references/methodology.md) → *Dependency Freshness as Visibility*).
Run it weekly, create/update one tracking issue, prefer patch/minor first and treat majors
as migration work. `composer audit` (the security slot) is separate and blocking.
