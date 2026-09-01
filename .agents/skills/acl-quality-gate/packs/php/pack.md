# PHP Pack

> **Status: implemented.**

Concrete binding of the [quality gate methodology](../../references/methodology.md) and the
shared [coding conventions](../../references/conventions.md) to the PHP toolchain. Read the
methodology first (capability contract, modes, baseline mechanics, boundaries) and
conventions for the language-neutral coding policy; this pack supplies the tools, configs,
commands, and the PHP-specific bindings. It follows the
[pack-authoring contract](../../references/pack-authoring.md).

Prefer Composer + Mago. Recommend the tools below for new projects and projects adopting the
gate; never swap a toolchain a project already uses — wire the canonical command names to the
existing tool and add only the missing layers (see *Adopting onto an Existing Toolchain*).

## Capability → Tool

| Capability | Tool | Config asset |
|------------|------|--------------|
| Format | **Mago** `mago fmt` | `assets/configs/mago.toml` (`[formatter]`) |
| Lint (cyclomatic, params, nesting, banned debug calls, unused imports, strict-types) | **Mago** `mago lint` | `assets/configs/mago.toml` (`[linter]`) |
| Type / strictness check | **Mago** `mago analyze` (always-on ≈ PHPStan max; no level system) + `declare(strict_types=1)` | `assets/configs/mago.toml` (`[analyzer]`) |
| File / class length (~400) | **`check_file_length.php`** (`quality:filesize`) | `assets/configs/check_file_length.php` |
| Code duplication | **jscpd** (Rust; `--threshold` percentage cap) | `assets/configs/.jscpd.json` |
| Dependency hygiene (unused + missing/shadow) | **shipmonk/composer-dependency-analyser** | `assets/configs/composer-dependency-analyser.php` |
| Security | **composer audit** + Mago `no-debug-symbols`; opt-in Psalm taint / Semgrep / CodeQL | — |
| Architecture boundaries (opt-in) | **Mago `guard`** (Perimeter); deptrac documented alternative | `assets/configs/mago.toml` (`[guard]`) |
| Dependency freshness | **composer outdated** (weekly issue) | `assets/github/dependency-freshness-*.yml` |
| Hooks | **CaptainHook** (pre-commit fmt+lint staged; pre-push analyze) | `assets/configs/captainhook.json` |
| CI | GitHub Actions + `shivammathur/setup-php` + `nhedger/setup-mago` | `assets/github/quality-gate-*.yml` |
| Package manager / runtime | Composer; runtime PHP via `config.platform.php`; Mago target via `mago.toml` `php-version` | `assets/configs/composer-scripts.fragment.json` |
| Cognitive complexity, method length, hotspots | **phpcca** — **advisory, non-blocking** | `assets/configs/phpcca.yaml` |
| `AGENTS.md` | PHP-tuned template | `assets/agents/AGENTS.md` |

**Merged slots:** Mago fills *format*, *lint*, and *type/strictness* (three slots, one Rust
binary), plus the opt-in *boundaries* slot via `guard`.

**Baseline shapes** (methodology → *Baseline mechanics*): baseline-file tools are Mago lint
(`lint-baseline.toml`), Mago analyze (`mago-analysis-baseline.toml`), and Mago guard. jscpd
is the one capped-score tool (`--threshold`). phpcca is advisory and never gates. See
[adoption-workflow.md](adoption-workflow.md).

## Detailed References

- [quality-gate-policy.md](quality-gate-policy.md) — PHP tool bindings: thresholds-as-config,
  Mago rule coverage, the convention→rule mapping, baseline mechanics, the capability gaps,
  and why phpcca is advisory. Read before choosing thresholds or relaxing defaults.
- [adoption-workflow.md](adoption-workflow.md) — strict vs baseline rollout; Mago/jscpd
  baseline mechanics; baseline-blind local checks. Read before applying to a brownfield repo.
- [project-topologies.md](project-topologies.md) — split services, Composer monorepos,
  Symfony/Shopware bundles, `src` layout. Read before applying to anything with more than one
  project root.
- [development-usage.md](development-usage.md) — PHP binding of the shared
  [implementation-phase workflow](../../references/development-usage.md).

## Setup Flow

1. Inspect the project (per the core workflow) and **decide the mode** (methodology →
   *Mode Decision*). If unsure, run the gate once (step 8) — a wall of pre-existing failures
   means baseline mode; load [adoption-workflow.md](adoption-workflow.md).
2. Classify the topology with [project-topologies.md](project-topologies.md) before touching
   split services, monorepos, or Symfony/Shopware bundles.
3. Copy or merge assets: `mago.toml`, `.jscpd.json`, `composer-dependency-analyser.php`,
   `phpcca.yaml`, `captainhook.json`, `.editorconfig` to the gated project root;
   `check_file_length.php` to `scripts/`; the scripts + `require-dev` from
   `composer-scripts.fragment.json` merged into `composer.json`; GitHub workflows to
   `.github/workflows/`; editor assets to `.vscode/`. Set `[source] paths`/`src` to the real
   source root, and **pin `php-version` in `mago.toml`** to the project's target. When merging
   into `composer.json`, keep `require`/`require-dev` as JSON objects (`{}`) — an empty `[]`
   makes Composer reject the file ("Array value found, but an object is required").
4. Install the toolchain and dependencies:
   ```fish
   composer require --dev carthage-software/mago phauthentic/cognitive-code-analysis shipmonk/composer-dependency-analyser captainhook/captainhook captainhook/hook-installer
   composer install
   ```
   The `carthage-software/mago` Composer package is a thin wrapper: it provides
   `vendor/bin/mago` locally (the first run downloads the matching prebuilt Rust binary), so
   the canonical `composer run` scripts work with no extra local setup. In CI, install Mago
   via `nhedger/setup-mago` (faster than the wrapper's first-run download); `cargo install
   mago` / `brew install mago` are alternatives for a standalone global binary. jscpd is a
   non-PHP binary — install it via `cargo install jscpd` / `brew install jscpd` /
   `npm i -g jscpd@5` (not a Composer dependency).
5. Install the Git hooks:
   ```fish
   vendor/bin/captainhook install
   ```
   (The `captainhook/hook-installer` plugin also installs them on Composer operations.)
   The project must already be a Git repo — run `git init` **before** `composer install`,
   or the hook-installer plugin aborts the install with "git directory not found". The
   shipped `captainhook.json` invokes `vendor/bin/mago` (CaptainHook runs actions in a bare
   shell without `vendor/bin` on `PATH`, so a bare `mago` would be "command not found");
   if a project installs Mago globally instead of via the `carthage-software/mago` wrapper,
   adjust the hook paths accordingly.
6. Add GitHub Actions:
   - [assets/github/quality-gate-strict.yml](assets/github/quality-gate-strict.yml) for new projects.
   - [assets/github/quality-gate-baseline.yml](assets/github/quality-gate-baseline.yml) for existing projects with committed baselines.
   - [assets/github/quality-gate-project-matrix.yml](assets/github/quality-gate-project-matrix.yml) for multiple separately gated roots.
   - [assets/github/quality-gate-project-matrix-baseline.yml](assets/github/quality-gate-project-matrix-baseline.yml) for existing multi-project repos.
   - [assets/github/dependency-freshness-weekly.yml](assets/github/dependency-freshness-weekly.yml) for single-project repos; [assets/github/dependency-freshness-project-matrix-weekly.yml](assets/github/dependency-freshness-project-matrix-weekly.yml) for multi-project repos.
7. Create or merge `AGENTS.md` at each gated root from
   [assets/agents/AGENTS.md](assets/agents/AGENTS.md) — a required output. Fill every
   placeholder with the project's real choices (logger, validation library, persistence
   stack, env-config accessor, PHP version, strict vs baseline mode) and delete the guidance
   comments. Merge, do not overwrite; keep it short.
8. Run the gate:
   ```fish
   composer run quality
   ```
   - **Strict mode:** fix every failure before merging the baseline.
   - **Baseline mode:** do not chase a clean run on legacy debt. Generate and commit the Mago
     baselines (and raise the jscpd threshold) per [adoption-workflow.md](adoption-workflow.md),
     then rely on the baseline CI workflow. The local `quality:dupes`/`lint`/`typecheck` are
     baseline-blind — see [adoption-workflow.md](adoption-workflow.md) → *Local Checks in
     Baseline Mode*.

## Adopting onto an Existing Toolchain

The principle — adopt by capability slot, keep the tools the user wants kept, and wire the
canonical names to them — is in [methodology.md](../../references/methodology.md) → *Adopting
onto an Existing Toolchain* and SKILL.md → *Do Not Disturb an Existing Setup*. **The Mago
defaults are for new projects / fresh adoption only.** On a project that already has a
toolchain, never swap it for Mago unless explicitly asked:

| Slot | If the project already uses… | Action |
|---|---|---|
| Format | PHP-CS-Fixer / Pint | **Keep it.** Wire `format`/`format:check` to it; do not introduce `mago fmt`. |
| Lint + Type | **PHPStan** / Psalm (+ Larastan, phpstan-symfony, extensions, baseline) | **Keep it.** Wire `lint`/`typecheck` to it; do not introduce `mago analyze`. Keep their baseline. |
| Maintainability (complexity/length) | PHPMD / PHP Insights | **Keep it.** Wire `quality:maintainability` to it instead of phpcca. |
| Boundaries | deptrac / Arkitect | **Keep it.** Wire `quality:boundaries` to it instead of `mago guard`. |
| Missing structural slots | — | **Add only these:** duplication (jscpd), dependency hygiene (composer-dependency-analyser), file length (`check_file_length.php`), freshness (`composer outdated`), security (`composer audit`). They compose with any formatter/analyzer. |

- Decide per slot, and surface what you are and are not changing. Replacing a tool the project
  already uses requires an explicit request from the user.
- CI's "Verify composer scripts" step checks the canonical **names**, not which tool backs
  them — so a project keeping PHPStan still satisfies `typecheck`.

### Framework substitutions

- **Symfony / Shopware:** for a new gate, enable Mago's built-in Symfony integration; note its
  framework stubs are thinner than a PHPStan + `phpstan-symfony` setup — teams that want that
  depth keep/choose PHPStan via adopt-by-slot.
- **Laravel:** for a new gate, Pint (format) + a Laravel analyzer are reasonable; if the
  project already uses them, keep them and wire the canonical names to them.

## Asset Map

- `assets/configs/mago.toml`: Mago formatter + linter (thresholds) + analyzer (strictness
  toggles) + guard config. Pin `version` and `php-version`.
- `assets/configs/check_file_length.php`: dependency-free file-length gate (~400 LOC). Copy to
  `scripts/`; backs `quality:filesize`.
- `assets/configs/.jscpd.json`: duplication threshold + reporters (console + SARIF).
- `assets/configs/composer-dependency-analyser.php`: unused + missing/shadow dependency config.
- `assets/configs/phpcca.yaml`: advisory cognitive-complexity + method-length config (owns
  cognitive/LOC only; no Mago overlap).
- `assets/configs/captainhook.json`: pre-commit (Mago fmt + lint on staged PHP) and pre-push
  (Mago analyze) hooks.
- `assets/configs/.editorconfig`: cross-editor formatting contract (4-space PHP).
- `assets/configs/composer-scripts.fragment.json`: canonical scripts + `require-dev` to merge
  into `composer.json`.
- `assets/github/quality-gate-strict.yml`: CI for new projects.
- `assets/github/quality-gate-baseline.yml`: CI for legacy adoption with regression baselines.
- `assets/github/quality-gate-project-matrix.yml`: CI for multiple separately gated roots.
- `assets/github/quality-gate-project-matrix-baseline.yml`: baseline-aware CI for existing
  multi-project repos.
- `assets/github/dependency-freshness-weekly.yml`: scheduled freshness workflow that
  creates/updates one tracking issue.
- `assets/github/dependency-freshness-project-matrix-weekly.yml`: scheduled freshness issue
  with per-project sections.
- `assets/editor/vscode-settings.json`: VS Code workspace settings (Mago; no format-on-save).
- `assets/editor/vscode-extensions.json`: VS Code extension recommendations.
- `assets/agents/AGENTS.md`: placeholder-driven `AGENTS.md` template.

## Implementation Rules

- Mago is a single Rust binary covering format + lint + type-check + guard. It has **no
  PHPStan-style level system** — strictness is per-category `[analyzer]` toggles. Pin the Mago
  `version` and set `php-version` in `mago.toml` (Mago does not read it from `composer.json`).
- Keep `[source] includes = ["vendor"]` and keep `vendor` out of `excludes` (exclude only
  `vendor/bin`). Mago parses `includes` for symbols without linting or reporting on them; it is
  how the analyzer resolves dependency types. Excluding `vendor` instead makes every dependency
  class read as "not found", which passes on a project with no dependencies and fails the moment
  it has one. An exclude cancels the include, so the two must not overlap.
- Keep `no-debug-symbols` at `level = "error"` (its default is `note`, which would not gate
  `echo`/`var_dump`/`dd`). Keep `strict-types` with `allow-disabling = false`.
- phpcca is **advisory only**: `phpcca analyse` never exits non-zero on a metric breach, and
  its cognitive "score" is a weighted composite, not a hard "12". Run it via the non-blocking
  `quality:maintainability` / `quality:hotspots`; never put it in the blocking `quality`
  aggregate or a blocking CI step. Keep `show*Complexity` false so it owns cognitive + method
  length only and does not double-report against Mago.
- jscpd is a non-PHP binary; provision it explicitly in CI and locally (cargo/brew/npm). Its
  `--threshold` is the capped-score knob.
- Treat dependency freshness as weekly visibility by default, not a PR blocker. `composer
  audit` (security) is separate and blocking.
- Gate by deployable or package boundary. Do not merge unrelated services' debt into one
  baseline; do not split one integrated app into artificial sub-gates.
- For shared data contracts, define DTOs once and validate boundary data at runtime
  (Symfony Validator, webmozart/assert, cuyz/valinor); keep contract classes small. There is
  no dedicated contract structural gate (PHP's linter has no file-size cap) — `AGENTS.md` +
  review.
- Keep generated outputs excluded explicitly (protobuf `*_pb2`/proxies, `var/cache`, build,
  migration artifacts) rather than relaxing global rules. Keep the excluded paths narrow.
- For new projects, fail CI on strict thresholds immediately. For existing projects, commit
  baselines and fail CI only on regressions until debt is intentionally cleaned up.
- Allow baselines only as explicit debt decisions; document reason, scope, owner/tracking
  issue, and cleanup condition.
- Keep workflow steps backed by composer scripts. Every `composer run <task>` used by a
  workflow must exist in the target `composer.json` (the CI *Verify composer scripts* step
  enforces this).
- Keep tests out of this baseline unless the user explicitly asks to add test gates.

## Verification

After editing a target project, run:

```fish
composer install
composer run format:check
composer run lint
composer run typecheck
composer run quality:filesize
composer run quality:dupes
composer run quality:depcheck
composer run quality:security
```

For the non-blocking advisories, run separately (on demand):

```fish
composer run quality:maintainability
composer run quality:hotspots
composer run quality:deps
```
