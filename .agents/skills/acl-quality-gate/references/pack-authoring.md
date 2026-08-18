# Pack Authoring Contract

What every language pack must satisfy so packs stay consistent instead of each becoming a different product. Read [methodology.md](methodology.md) (the mechanical contract) and [conventions.md](conventions.md) (the shared coding conventions) first; this document is the conformance spec that binds them to a language.

`packs/typescript/` is the reference implementation. New packs should match its shape.

## 1. Capability Coverage (no silent gaps)

Fill every slot in the methodology's capability contract: format, lint, type/strictness check, maintainability score, graph health / dead code, dependency freshness, hooks, CI, `AGENTS.md`. For each slot, the pack states the concrete tool. A slot may be:

- **filled** by a dedicated tool,
- **merged** into another tool (state which — e.g. "lint and format are both the same tool"), or
- **gapped** — explicitly marked "no standard option for this language," with the consequence noted.

Never drop a slot silently. Classify each baseline-capable tool as **capped-score** (a measured cap drives the exit code, no baseline file) or **baseline-file** (committed snapshot of accepted findings), per the methodology's *Baseline mechanics*.

## 2. Canonical Command Vocabulary

Every pack exposes the same **logical** task names, mapped onto the language's runner. This is what makes `AGENTS.md`, the CI templates, and the development-usage guide portable across languages — a developer or workflow runs `typecheck` without knowing the language.

| Logical name | Meaning |
|---|---|
| `format` | rewrite formatting in place |
| `format:check` | verify formatting, non-mutating (CI/pre-commit) |
| `lint` | run the linter |
| `lint:fix` | linter with autofix |
| `typecheck` | whole-program type/strictness check |
| `quality:<gate>` | one named sub-gate (maintainability, graph health, dead code, audit, contracts, …) |
| `quality` | the aggregate that runs all blocking gates in order |
| `quality:deps` | non-blocking dependency-freshness report |

A pack may add language-specific sub-gates under the `quality:` prefix, but must provide at least `format`/`format:check`, `lint`, `typecheck`, `quality`, and `quality:deps`. CI and hooks call these logical names only.

## 3. Threshold Parity

The gate should *mean the same thing* across languages. Adopt these shared targets; deviate only with a documented reason (language norm, framework constraint) in the pack and config:

- File length: ~400 lines
- Function/method length: ~75 lines
- Cyclomatic complexity: 10
- Cognitive complexity: 12
- Nesting depth: 4
- Parameter count: 5
- Project health score: ≥ 85 (or the tool's nearest equivalent), failing on high/critical findings
- Circular dependencies, unresolved imports, unlisted dependencies, stale suppressions: error

Express each as the relevant tool's config/flag in the pack; the numbers stay constant unless the pack documents an exception.

## 4. CI Template Set

Each pack ships the same logical workflows under `assets/github/` (or the project's CI system), language-specific in body but identical in role:

- strict gate (new projects)
- baseline gate (regression-only, for adopted debt)
- project-matrix gate (multiple gated roots in one repo)
- project-matrix baseline gate
- dependency-freshness (single-project) — scheduled, creates/updates one tracking issue
- dependency-freshness (project-matrix)

CI must call only the canonical command names and verify they exist before running (the TS strict workflow's "Verify package scripts" step is the model).

## 5. AGENTS.md Skeleton

Ship an `AGENTS.md` template under `assets/agents/` with the same section headers across languages, so the in-project file is predictable regardless of stack:

`Commands` · `<language> strictness` · `Logging` · `Error handling` · `Shared contracts` · `Database & persistence` · `Environment config` · `Structure & constants` (+ `Frontend` where the stack has one) · `Review Instructions`.

Placeholders for the project's real choices; guidance comments to delete on fill; merge-don't-overwrite; keep it short.

`Review Instructions` is the one section that is **not** language-specific: a fixed pointer that tells a reviewing agent to invoke the `acl-quality-gate` skill and follow its quality-pass workflow (defined once in [development-usage.md](development-usage.md) → *Running a Quality Pass*). Keep it to a short trigger — do not restate the workflow steps. It must ship in the in-project `AGENTS.md` because that file is the only artifact that travels into the gated repo and stays in agent context; the same wording is fine across packs.

## 6. Convention Bindings

Bind every section of [conventions.md](conventions.md) to the language. For each convention, **map it to a lint rule wherever the linter supports it** (so it becomes part of the mechanical `lint` gate) and to the `AGENTS.md` + review otherwise. The pack documents the mapping (which rule enforces logging, error throwing, code-safety, etc.). Where a convention has language mechanics (validation library for contracts, env-prefix convention, persistence stack), the pack supplies them; the principle stays in `conventions.md`.

## 7. Pack File Structure

```
packs/<language>/
  pack.md                 entry point (see template below)
  <topic>.md              detailed docs ONLY where the language diverges from
                          methodology/conventions (policy, adoption, topologies).
                          Do not restate the shared docs.
  development-usage.md    THIN binding of the shared references/development-usage.md:
                          only the runner, the pack's quality:* task names, language-
                          specific review items, and scoping notes. Do not restate the
                          neutral workflow.
  assets/
    configs/   github/   hooks/   editor/   agents/
```

Keep packs **thin**: bind the shared contract and conventions to tools; do not re-document the language-neutral policy that already lives in `methodology.md`, `conventions.md`, and `development-usage.md`.

### pack.md section template

1. Status banner (implemented, or "stub — not yet implemented; do not apply a partial gate").
2. One-line statement that it binds the methodology + conventions to the language; links to both.
3. **Capability → Tool** table (every slot; merges/gaps explicit; baseline shape per tool).
4. Detailed-reference links (only the docs this pack actually adds).
5. Setup flow.
6. Asset map.
7. Implementation rules / language-specific notes.
8. Verification commands (the canonical names).

## 8. Conformance Checklist

- [ ] Every capability slot filled, merged, or explicitly gapped — no silent drops.
- [ ] Canonical command names provided and used by CI + hooks.
- [ ] Shared thresholds adopted; deviations documented.
- [ ] Full CI template set shipped, calling canonical names and verifying they exist.
- [ ] `AGENTS.md` template with the standard section headers, including a `Review Instructions` pointer to the skill's quality-pass workflow.
- [ ] Every `conventions.md` section bound — to lint rules where possible, `AGENTS.md` otherwise.
- [ ] Pack stays thin (no restatement of methodology/conventions).
- [ ] SKILL.md available-packs list updated to mark the pack implemented.
