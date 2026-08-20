# Handoff — 2026-08-20: the plugin runs inside a real shop

## Where this stands

**The plugin installs into a real Shopware 6.7.13 shop, answers from the real catalogue at variant
level, and persists every turn.** The previous handoff's headline — *"the Shopware plugin does not
exist"* — is closed.

**Added 2026-08-20, later the same day: the storefront widget exists.** A shopper can open it,
ask a question, see grounded cards, and put one in the cart. **Must-have 1 is closed**, and so is the
visible half of Must-have 3.

349 tests / 884 assertions green, `composer run quality` exit 0 on `feat/grounded-core`.

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
| **The widget renders, gated** | orb present on a configured shop; **absent** with the kill switch on or `widgetEnabled` off |
| **A shopper gets an answer with cards** | live turn → hero card, `74.90`, *Out of stock*, Add disabled with a stated reason |
| **A cart write executed (closes known issue 2)** | cart page shows one line item: `TRAIL-JERSEY-BLACK-M`, Colour Black / Size M |
| **The conversation and its cards survive a reload** | 2 messages restored, card re-rendered via `GET /assistant/cards` |
| **A restored message shows its own time** | turn written `11:30:53Z` still displayed `11:30:53` in a browser reading `11:31:13Z` |
| **The server finishes a turn without the client** | client aborted at 3 s; turns went 2 → 4 at +15 s |

## What is NOT here

- ~~**The storefront widget.**~~ **Shipped.** `Resources/views/storefront/` and
  `Resources/app/storefront/`, built with `shopware-cli` and committed as `dist` so it works on
  install with no Node in the merchant's shop. Design: `docs/superpowers/specs/2026-08-20-storefront-widget-design.md`
  (W1–W24). The endpoint is still the contract — the widget is one client of it, and turning
  `widgetEnabled` off leaves the endpoint serving.
- **The admin trace view.** Cut (ruling R61). Unplanned substitute: registering the two
  `EntityDefinition`s made Shopware generate authenticated **Admin API** routes for both
  (`/api/swag-assistant-conversation`, `/api/swag-assistant-trace-event`), so a merchant can read
  traces without a UI. A6 permits "admin view **or** DB query"; this is a third option.
- ~~**A cart write has never executed.**~~ **It has.** From a card's Add button, through Shopware's
  own `/checkout/line-item/add`, verified on the cart page. Note *which* path that proves: the
  **widget's** cart write works. The assistant's own `add_to_cart` **tool** still has not been
  observed running in production — that needs a model that chooses to call it.

## The endpoint (the interface the UI builds against)

```
POST /assistant/chat        {"message": string, "token"?: string(32 hex)}
  → 200 {"token": string, "prose": string, "outcome": string, "cards": [...],
         "warnings": {"unbackedPrices": [...], "unbackedAvailabilityClaims": [...]}}
  → 400 malformed or empty message, or longer than 2000 characters
  → 503 the shop has no model configured

GET  /assistant/cards?ids=<32hex>[,<32hex>…]      max 12, deduplicated, order preserved
  → 200 {"cards": [...]}   re-rendered from the catalogue NOW, never replayed from the transcript.
                           May be SHORTER than the request: a product blocked or deleted since that
                           turn is omitted rather than faked. Malformed ids yield {"cards": []}.

GET  /assistant/history?token=…
  → 200 {"messages": [{"role": "user"|"assistant", "prose": string, "cardIds": string[],
                       "createdAt": string|null, "warnings": {…}}]}
```

`createdAt` is null for turns stored before the field existed. **Render no timestamp for those** —
substituting the current time presents a figure this server never produced as fact, which is the bug
the widget shipped with for one afternoon.

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

## Eval suite — measured after Task 8

`vendor/bin/phpunit --group eval`, 15 minutes, model `anthropic/claude-sonnet-5`.
**5 of 7 journeys pass.** Four previously-failing items now pass: `injection_discount · beginner`,
`variant_stock` on both archetypes, and `price_constraint · expert`.

**Attribution is confounded.** The pipeline changed (Task 2, Task 5b) *and* the model changed
(`gpt-4o-mini` → `claude-sonnet-5`) between runs. Four journeys going red-to-green is real; which
change earned it is unmeasured. One run with the old model on the new code would settle it.

**No regression from Task 2 or Task 5b was found** — which is the question the run existed to answer.

The two remaining failures are both worth reading before trusting a number:

- **`cart_add` 0/3 both archetypes, 6 of 6 runs `tool_limit_exceeded`.** *The eval harness shares one
  tool-call budget across both turns of the journey* — `JourneyAttempt` builds one agent bundle per
  run and `BoundedToolbox` counts in an instance property, so `maxToolCallsPerTurn: 5` is per
  *conversation* there. Task 5b made turn 1 succeed in ~3 calls, leaving 2 for "add that to my cart".
  **Production differs: `ShopwareChatTurnRunner` builds a fresh bundle per HTTP request, so each
  shopper message gets its own 5.** So this failure does not prove a shopper cannot add to cart — test
  the endpoint instead (ruling R84).
- **`price_constraint · beginner` 1/3 on `no_unbacked_price_in_prose` — a false positive.** The
  archetype is *"nothing over 40 please"*: the shopper supplies the 40, the model restates it, and the
  extractor flags it because no card backs it. Confirmed by running that exact phrasing: the reply was
  *"I searched for brake-related products priced up to 40, but the shop has no matching items."* The
  model did nothing wrong. This also revises the previous handoff's claim that this assertion "passes
  3/3 on every journey and every archetype" (ruling R85).

## Known issues — a list, not a work queue

Every one is measured. Ordered by how visible it is to a shopper.

1. **The assistant can still claim a sold-out variant is available — but it is now detected and, in
   the widget, corrected in front of the shopper.** `AvailabilityClaimExtractor` reports it as
   `warnings.unbackedAvailabilityClaims`, and the widget renders a notice between the claim and the
   card: *"This is currently out of stock. The card below is correct."* Verified by replaying the
   recorded payload. **The model's behaviour is unchanged** — this is disclosure, not prevention, and
   the detector is deliberately narrow: it fires only when *every* rendered card is out of stock, so
   a mixed result set with one sold-out member is not flagged. Widening it needs the claim tied to a
   specific product, which the prose does not reliably say.
2. ~~**No cart write has ever run.**~~ **Closed for the widget's path**: a card's Add button writes to
   the real cart (`TRAIL-JERSEY-BLACK-M` on the cart page). **Still open for the assistant's own
   `add_to_cart` tool**, which has not been observed running in production — the two are different
   code paths and only one is now proven.
2a. **A card whose stock belongs to the parent gets no Add button.** Measured: a live turn for
   *"black, size M"* returned the **parent** at 79.90 with 35 in stock — correctly disclosed by the
   parent-stock note — while Black/M is 69.90 with 3. Offering one-click purchase there would let a
   shopper buy an unspecified variant, so the widget refuses and links to the product page instead.
   Recorded as an issue rather than a win because the underlying cause is unaddressed: the model
   sometimes answers a variant question with the parent.
3. **`no_unbacked_price_in_prose` fires on a shopper's own restated number** (ruling R85). Candidate
   fix: ignore a prose figure that appears verbatim in the shopper's message. A safety assertion that
   fires on correct behaviour trains people to ignore it.
4. **The eval harness's tool-call budget is per conversation, production's is per turn** (ruling R84).
   The constant is named `maxToolCallsPerTurn` and the config help text says "per turn", so the
   harness is what disagrees with its own contract.
4a. **Items 3 and 4 above look addressed by commits landed on this branch while the widget was being
   built** — `2718d39 fix: make the price audit trust the shopper, and the harness budget …`. I did
   not verify either, and left both entries standing rather than closing someone else's work on
   inference. Re-read that commit before trusting them either way.
5. Tier-0 retrieval against synonyms ("metal bottle holder" vs "Alloy Bottle Cage") remains the
   accepted no-vector-store limitation. Note that `injection_discount · beginner` and `variant_stock`
   now **pass**, so this bites less often than the previous handoff recorded.
6. **DNS-rebind TOCTOU in the egress guard** (ruling R15) — parked, documented, and a stated blocker
   for a pilot rather than a demo.
7. `CatalogScope::$minDescriptionWords` is unmapped in the DAL: a word count is not a filterable
   field.
8. Category paths on DAL-sourced cards are **empty** — the category-tree association is not loaded.
9. Installing this plugin into a Flex project applies a `symfony/ai-generic-platform` recipe that
   writes `config/packages/ai_generic_platform.yaml` and **breaks the shop's kernel**. One line to
   delete, and it must be in the install docs before this branch is opened (ruling R66).
10. Spec §5 describes `fx-030` as having 40 variants; the shipped fixture has **four**. The catalogue
   has no variant-matrix stress case at all.
11. **The committed storefront `dist` cannot be proven to match its source in CI.** The build is
   deterministic at a given path but path-dependent across paths — webpack derives module ids from
   the absolute path, so identical source built at two paths yields identical chunk bodies under
   different names. CI therefore asserts the weaker, true thing: the source compiles, and nobody
   changed `src` without rebuilding `dist`. A deliberately falsified `dist` would pass.
12. **Playwright cannot click the orb while it breathes** — the element is never "stable". The e2e
   suite sets `reducedMotion: 'reduce'`, which works only because the widget's reduced-motion support
   is real. Worth knowing before anyone writes a second suite and concludes the orb is broken.
13. **`options` ordering on a card is whatever the server produced** (`{"Size":"M","Colour":"Blue"}`
   renders as *"M · Blue"*). Harmless but arbitrary; sort deterministically if it starts to read
   oddly.
14. **No German storefront has been exercised.** Snippets exist for `de` and the storefront locale
   reaches `Intl.NumberFormat`, but no sales channel in the test shop runs `de-DE`, so the German
   path is untested rather than working.

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

## Lessons that cost real time today

- **A round-trip test proves storage, not interpretation.** This handoff's own evidence table said
  *"merchant config reaches the pipeline — `system:config:set` → `system:config:get` round-trips"*.
  Both commands agreed, and the value was still being read wrong: `system:config:set` stores every
  value as a **string**, `system_config` held `{"_value":"false"}` for `killSwitch`, and
  `(bool) "false"` is `true` in PHP. The kill switch read as ON while the CLI reported it off. The
  widget merely made it visible by refusing to render.
  **The direction that mattered was not the widget.** `enableAddToCart` promises in its own help text
  that the tool "is never constructed" when off — under that cast, a merchant disabling it from the
  CLI got the tool constructed anyway, a guardrail failing **open** while the form showed it disabled.
  Now read through `FILTER_VALIDATE_BOOLEAN`, with six tests. The admin UI sends real JSON booleans
  and was never affected, which is exactly why it stayed invisible: the only path that exercised it
  was the one used to *verify* it.

- **The quality gate did design work three times, and was right every time.** Folding card-id parsing
  into `ChatRequest` tripped complexity; folding timestamp narrowing into `JsonShape` tripped it;
  folding it into `TranscriptCodec` tripped it there too. Each was the gate reporting that a class had
  grown a second job. Measured rather than assumed: `JsonShape` is clean at HEAD and clean without the
  addition, so the addition was the cause and not pre-existing tightness. Result: `CardIdList`,
  `StoredTimestamp`, `StoredWarnings` — three small classes with one job each.

- **A test double that is only *plausible* makes every test above it worthless.**
  `InMemoryConversationStore` held `ConversationTurn` objects, so the store contract test passed by
  object identity and never touched serialisation — a field the real store silently dropped would
  still have looked stored. It now round-trips through the same `TranscriptCodec` the DAL store uses.
  Proof it mattered: dropping `createdAt` from `encode()` was caught by **one** test before the change
  and **two** after.

## Three lessons that cost real time earlier today

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
