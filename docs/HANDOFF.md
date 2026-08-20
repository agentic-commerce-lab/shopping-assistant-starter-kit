# Handoff — 2026-08-20: the plugin runs inside a real shop

## Where this stands

**The plugin installs into a real Shopware 6.7.13 shop, answers from the real catalogue at variant
level, and persists every turn.** The previous handoff's headline — *"the Shopware plugin does not
exist"* — is closed.

276 tests / 792 assertions green, `composer run quality` exit 0, 8 tasks plus one unplanned task
complete on `feat/grounded-core`.

What is verified against the real shop, by command output rather than by argument:

| Claim | Evidence |
|---|---|
| Plugin installs and activates | `plugin:list` → Installed **Yes**, Active **Yes**; storefront still HTTP 200 |
| Merchant config reaches the pipeline | `system:config:set` → `system:config:get` round-trips; a real turn used stored credentials |
| Answers from the real catalogue | `swag:assistant:probe --search="Trail Jersey"` returns 7 real rows |
| **Variant-level stock and price (A1)** | Blue/M reports **stock 0, price 74.90, `stockSource: variant`** — not the parent's 35 at 79.90 |
| Never guesses a variant | `--option=Blue` alone → **`null`**, "resolution refuses to guess" |
| Schema matches the definitions | `bin/console dal:validate` → **"No errors found"** |
| **Every turn persists its trace (A6)** | one HTTP turn → **58 trace events across 14 stages** |
| Conversation survives across requests | `"is that one in stock?"` with only the token resolved to Blue/M |
| A trace can be read end to end | `swag:assistant:probe --ask=…` dumps one — closing the previous handoff's known-issue 8 |

## What is NOT here

- **The storefront widget.** Descoped mid-plan at Robin's request — he has separate plans for the
  interface. So **Must-have 1** ("chat widget in a real 6.7 storefront") and the visible half of
  **Must-have 3** ("the cart page proves it") are not demonstrable from this repo. The endpoint below
  is the contract that UI builds against.
- **The admin trace view.** Cut (ruling R61). Unplanned substitute: registering the two
  `EntityDefinition`s made Shopware generate authenticated **Admin API** routes for both
  (`/api/swag-assistant-conversation`, `/api/swag-assistant-trace-event`), so a merchant can read
  traces without a UI. A6 permits "admin view **or** DB query"; this is a third option.
- **A cart write has never executed.** `DalCartAdapter` is wired and unit-tested, but no `add_to_cart`
  has run against a real cart. It needs a model that chooses to call the tool — see known issue 1.

## The endpoint (the interface the UI builds against)

```
POST /assistant/chat        {"message": string, "token"?: string(32 hex)}
  → 200 {"token": string, "prose": string, "outcome": string, "cards": [...]}
  → 400 malformed or empty message, or longer than 2000 characters
  → 503 the shop has no model configured

GET  /assistant/history?token=…
  → 200 {"messages": [{"role": "user"|"assistant", "prose": string, "cardIds": string[]}]}
```

A card carries `id, name, description, price, currency, stock, stockSource, inStock, deliveryTime,
url, imageUrl, options`. **Every figure comes from a server-rendered card; nothing is parsed out of
the model's prose.** `stockSource` is exposed deliberately — it says whether a stock figure belongs to
the variant asked about or to its parent, which a client cannot infer and should not have to.

The token belongs in `sessionStorage`. Call `GET /assistant/history` on mount to re-hydrate: without
it, "add that to my cart" has no antecedent after a page reload.

## Read these first

| File | Why |
|---|---|
| `docs/superpowers/specs/2026-08-18-shopping-assistant-design.md` | The authority. D1–D16, A1–A7, Must/Should/Cut |
| `ARCHITECTURE.md` | Architecture of record, with four dated corrections that live runs forced |
| `.superpowers/sdd/2026-08-19-shopware-plugin/progress.md` | Rulings R56–R83, each with reasoning and cost-if-wrong |
| `.superpowers/sdd/2026-08-18-grounded-core/standing-constraints.md` | Binding constraints — pins, quality gate, the five pragma carve-outs |
| `docs/superpowers/plans/2026-08-19-shopware-plugin.md` | The plan this session executed |

## The environment

`/Users/R.Schulte/Workspace/shopping-assistant-test` — Shopware 6.7.13, storefront on
`http://127.0.0.1:8000`, `docker compose` (port 8080 remapped to 8081 in `compose.override.yaml`,
which also bind-mounts this repo at `plugin-src`). Installed via a **Composer path repository**, not a
`custom/plugins` symlink: for non-Composer-managed plugins Shopware registers only the plugin's own
PSR-4 namespaces, so Symfony AI would not be autoloadable (ruling R59).

Catalogue: 128 generated products plus one deliberately shaped product, `TRAIL-JERSEY`
(`fafa…fa`), whose whole purpose is to carry three traps:

| variant | id | stock | own price |
|---|---|---|---|
| **Blue/M** | `a2a2…a2` | **0** | **74.90** |
| Black/M | `a5a5…a5` | 3 | 69.90 |
| Blue/S, Blue/L, Black/S, Black/L | `a1/a3/a4/a6` | 7/12/4/9 | inherit 79.90 |
| parent | `fafa…fa` | 35, `available = 1` | 79.90 |

Rebuild it with `scripts/`-less Admin API calls if the shop is reset — the ledger's Task 0 section has
the exact payload shape.

## Known issues — a list, not a work queue

Every one is measured. Ordered by how visible it is to a shopper.

1. **The assistant tells a shopper a sold-out variant is available, inconsistently.** A live turn
   replied *"Yes, the Trail Jersey is available in Blue, size M"* beside a card reporting `stock 0`.
   The follow-up turn in the same conversation deferred correctly instead. The prose audit covers
   **currency figures only**, so nothing fires. **This is the most shopper-visible defect known**, and
   the fix is an availability-claim extractor beside `CurrencyFigureExtractor` (rulings R75, and the
   note beside the assertion table in `ARCHITECTURE.md`).
2. **No cart write has ever run.** Needs a model that calls `add_to_cart`. Task 5b removed the
   arithmetic obstacle (see below), so this is now worth retrying rather than assumed broken.
3. `injection_discount · beginner` and `variant_stock · beginner` fail on Tier-0 retrieval against
   synonyms ("metal bottle holder" vs "Alloy Bottle Cage"). Accepted no-vector-store limitation; the
   catalogue-vocabulary block did not fix it.
4. **DNS-rebind TOCTOU in the egress guard** (ruling R15) — parked, documented, and a stated blocker
   for a pilot rather than a demo.
5. `CatalogScope::$minDescriptionWords` is unmapped in the DAL: a word count is not a filterable
   field.
6. Category paths on DAL-sourced cards are **empty** — the category-tree association is not loaded.
7. Installing this plugin into a Flex project applies a `symfony/ai-generic-platform` recipe that
   writes `config/packages/ai_generic_platform.yaml` and **breaks the shop's kernel**. One line to
   delete, and it must be in the install docs before this branch is opened (ruling R66).
8. Spec §5 describes `fx-030` as having 40 variants; the shipped fixture has **four**. The catalogue
   has no variant-matrix stress case at all.

## What changed in the pipeline, and why it matters to the next reader

Two corrections came out of evidence rather than review, and both are the same shape as ruling R47 —
a defect blamed on the model that belonged to the architecture.

**Tools return `{id, name, options}`, not bare ids** (ruling R74). The first trace ever read end to
end showed the model receiving **seven opaque ids**, then spending one `get_product` call per id just
to find out which was which, and dying on `tool_limit_exceeded` having rendered the *parent*. With
opaque ids, identifying one of N candidates costs N tool calls — a seven-variant family against a
budget of five is arithmetically unanswerable. **This is the most likely real cause of the previous
handoff's `cart_add` 0/3**: the budget is spent identifying products before `add_to_cart` is
reachable. After the change: 3 tool calls instead of 6, the model passes both option values in its
first call, and the right card comes back. D3 is intact — none of the three fields is a figure, and
ruling R54 already put the whole catalogue vocabulary in the prompt.

**The search limit applies after variant resolution** (Task 2, ruling R60). `ProductQuery::retrievalLimit()`
carries the retrieve-versus-return distinction `ARCHITECTURE.md` recorded as needed but not done;
`MIN_LIMIT` is deleted rather than superseded. Honest limit: the defect is **not observable through
`SearchProductsTool` against the fixture catalogue**, because no fixture family exceeds four variants
and the old floor was five. The assertions that distinguish repaired from mitigated sit at the gateway
seam, and all three were mutation-checked.

## Three lessons that cost real time today

- **A command's own success output is not evidence that it did what was intended.** Three separate
  instances: `framework:demodata` exited 0 twice while refusing to run; a `git commit` chained after a
  `cd` landed in a *different repository* (undone); and a `python` string-replace reported "patched"
  while silently not matching, because a formatter had reshaped the target. Rule adopted mid-plan:
  edits to existing files use a tool that fails loudly on a non-matching string, and any patch whose
  effect matters is verified by reading the file back.
- **Verify the framework, not the spec's description of it.** Ruling R58 chose `entities.xml` custom
  entities because the spec said so; they are registered exclusively by `AppManager`, so they are an
  **App** feature and this is a plugin. Cost: an hour, and a reversal (R78). `ARCHITECTURE.md` had it
  right all along. The rule that would have prevented it — R6, read the installed `vendor/` — was
  being applied correctly everywhere else that day.
- **A green test proves the tree it ran on, and this branch keeps proving it.** The mapper's tests
  passed while every variant came back nameless, because the test fixture *set* a name and a real
  Shopware variant has none — it inherits one. Found in the first minute of real catalogue use, by the
  probe command, which is the entire argument for having built it.
