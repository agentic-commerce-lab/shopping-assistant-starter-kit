# Handoff — 2026-08-20: the technical gaps, for the next session

> **HISTORICAL. There is no current handoff; the last one is archived beside this file as
> `docs/HANDOFF-2026-08-27-fashion-scale.md`, and `ARCHITECTURE.md` is the architecture of record.**
>
> Kept because thirteen committed documents cite it as the record of specific measurements: §1 for the
> interrogative-phrasing defect (since verified fixed, at fashion scale and on the real shop), §8 for the
> lab environment and the demo product's trap variants. Its forward-looking sections describe branch
> `feat/grounded-core` and are spent.

**Branch:** `feat/grounded-core`. Two sessions committed to it in parallel today — the backend
pipeline and the storefront widget. Nothing is merged anywhere else.

**State right now, measured:** `composer run quality` exit **0**, `vendor/bin/phpunit --exclude-group
eval` → **349 tests / 884 assertions OK**. The plugin installs into a real Shopware 6.7.13 shop,
answers from the real catalogue at variant level, adds the right variant to the real cart, and
persists every turn. A shopper can use it in a browser.

**This document is about what is still wrong.** For what works and how it is built, read in this
order:

| Document | What it gives you |
|---|---|
| `docs/HANDOFF-widget-to-backend.md` | The widget session's findings, from the side that watches people wait. **Read it fully — it is not superseded by this file, it is the source for several items below.** |
| `ARCHITECTURE.md` | Architecture of record, with five dated corrections that live runs forced |
| `.superpowers/sdd/2026-08-19-shopware-plugin/progress.md` | Rulings R56–R91, each with its reasoning and what it costs if wrong |
| `docs/superpowers/specs/2026-08-18-shopping-assistant-design.md` | The spec. D1–D16, A1–A7, Must/Should/Cut |

---

## 1. The one that actually hurts: interrogative phrasing breaks variant resolution

**Two sessions found this independently, from different angles, and neither knew about the other's
observation.** That correlation is the strongest signal in this document.

The widget session, driving the endpoint with real questions:

| Question | Card returned | Price | Stock | Outcome |
|---|---|---|---|---|
| *"show me the trail jersey in black, size M"* | `a5a5…` — **Black/M** | 69.90 | 3 | correct |
| *"do you have the trail jersey in black, size M?"* | `fafa…` — **the parent** | 79.90 | 35 | `tool_limit_exceeded` |

The eval suite, same day, `variant_stock · beginner` — *"hi, do you have that blue cycling jersey in a
medium?"*:

```
✗ rendered_ids_exactly  1/3
    run 2: extra: [fx-026-black-m, fx-026-blue-l], missing: []
    run 3: extra: [fx-026-black-m, fx-026-blue-l], missing: []
```

Three cards where one was expected. **Both failures are the interrogative form.** The imperative form
passes in both places — `variant_stock · expert` ("Trail Jersey, blue, M — in stock?") is 3/3.

### What is established

- `stockSource` correctly reported `parent` in the widget's case, so the grounding layer *knew* the
  figure was not the variant's. **Resolution did not happen** — it was not mis-reported.
- Ruling R74 recorded the same arithmetic biting before: with the model not passing `options`,
  `search_products` returns the whole family, and identifying the right member costs a tool call each.
  `selectionCount: 0` was measured then. It is the most likely shape here too.

### What is hypothesis, and how to settle it

- **Hypothesis A: the interrogative form makes the model search instead of resolve.** Settle it by
  running `swag:assistant:probe --ask` with both phrasings and diffing the `understand` stage's
  `selectionCount`. That is one cheap trace read, no guessing required.
- **Hypothesis B: my own prompt line widened the eval's card set.** Ruling R89 added
  *"Say you found it and point to the card, which carries its current stock"* at commit `42ca719`
  (09:36). The eval run **before** it had `variant_stock` green on both archetypes; the run after has
  beginner at 1/3. Among the changes in between, R86 fires only on `add_to_cart` (not called here) and
  R91 only affects multi-turn journeys (`variant_stock` is single-turn), so **the prompt line is the
  only plausible cause among them.** Settle it by reverting only that paragraph and running
  `--group eval --filter variant_stock`.

If B holds, it is a trade I made without measuring: a prose lie exchanged for a wider card set. The
prose fix was right; the wording may need to prevent asserting availability **without** encouraging
breadth.

### What a shopper currently sees, so you know the baseline

The widget refuses to show an add-to-cart button when `stockSource` is `parent`, and renders a note
saying the figure is the parent's. That is disclosure, not a fix — and it is deliberate: offering
one-click purchase on an unresolved variant would let someone who asked for "black, size M" buy an
unspecified one, which is exactly what D4 exists to prevent.

---

## 2. A turn takes 16–19 seconds, and nothing streams

Measured repeatedly by the widget session. The spec cut SSE streaming from v0, and that decision now
owns the single largest available improvement to this product: **first tokens at ~2 s instead of a
16-second blank**. The widget's rendering path already consumes prose incrementally, so this is a
transport change rather than a rewrite.

Related and smaller: **trace events are persisted once, after the run completes**, so there is no
progress signal to read. The widget's waiting copy therefore only says how long it has been — it is
deliberately not faking stage names it cannot see. Writing traces incrementally would let the
indicator say what is actually happening.

---

## 3. The model narrates the interface

Verbatim from live turns:

> "I found the Trail Jersey in Blue, size M — **the card here** shows its current price and stock."

The endpoint is explicitly the contract other clients build against, so prose that assumes one client
is a contract leak. A voice surface, an SMS integration, or `swag:assistant:probe --ask` all read
"the card here shows…" with no card anywhere. It is also the model describing something it has no
source for, which is the class of thing D3 forbids for figures.

Probably one system-prompt line: state facts plainly ("It is out of stock in Blue / M") and leave
presentation to the client. **Note the interaction with §1 hypothesis B** — this is the same paragraph
of the prompt. Changing both at once would make the next eval run uninterpretable again; change one,
measure, then the other.

---

## 4. The availability detector has never fired in the wild

`warnings.unbackedAvailabilityClaims` was **empty in every live turn the widget session ran** — around
six, several aimed deliberately at the sold-out Blue/M. The model deferred correctly each time. My own
three post-prompt runs behaved the same way.

That is the behaviour we want, and it means **the detector's real-world hit rate is unmeasured.** The
widget could only verify its correction path by replaying a hand-built payload.

The missing piece is an eval journey that *provokes* an availability claim rather than one that hopes
for it. Until then, `ProseAudit::unbackedAvailabilityClaims()` is covered by unit tests only, and its
six deliberately-quiet cases are the part actually exercised.

---

## 5. A correction to this document's predecessor, and to my own evidence

This document's predecessor (`docs/old-HANDOFF.md`, deleted 2026-08-27 as spent) claimed in its
evidence table that *"merchant config reaches the pipeline — `system:config:set` →
`system:config:get` round-trips"*. **That proved storage, not interpretation.**

The widget session found why it mattered: `system:config:set` stores every value as a **string**, and
`(bool) "false"` is `true` in PHP. So `SystemConfigAssistantConfig::boolOr()` read a kill switch
turned *off* from the CLI as **ON** — and, in the direction that matters, a merchant disabling
`enableAddToCart` from the CLI got the tool constructed anyway while the admin form showed it
disabled. Both of my commands agreed with each other while the value was being read wrong.

Fixed by them through `FILTER_VALIDATE_BOOLEAN`, with six tests in
`tests/Core/Config/SystemConfigBooleanReadingTest.php`. The admin UI sends real JSON booleans and was
never affected — **the only path that exercised the bug was the one I used to verify it.**

Carry the lesson, not just the fix: a round-trip through the same two commands is not evidence that a
value is *understood*.

---

## 6. Eval suite: 5 of 7, and what the two failures mean

Last run, `anthropic/claude-sonnet-5`, 13m52s. Fixed since the previous run: `cart_add` (was 0/3 both
archetypes) and `price_constraint · beginner` (was 1/3). Newly failing:

- **`variant_stock · beginner` 1/3 on `rendered_ids_exactly`** — §1 above.
- **`vocabulary_not_inventory · beginner` 2/3 on `no_invented_product`**, run 3:
  *"required stage `validate` is missing from the trace"*. That is ruling R40's fail-loud mechanism
  working: `validate` only runs when the model's result is a `TextResult`; otherwise
  `GroundingOutputProcessor` records `render: {skipped: 'non-text result'}` and returns. So that turn
  ended without final text. **That path is untouched by any change today**, the expert archetype is
  3/3, and it is one run of three — **I cannot distinguish run-to-run variance from a regression on
  one data point, and I am not going to pretend otherwise.** A second run of only that journey is the
  cheap way to find out.

Net is 5/7 both before and after, but the composition changed: two correctness failures traded for one
quality failure and one unclear case. Do not read that as progress without checking §1.

### One harness/production discrepancy still open

The eval harness shares one `FactRenderer` across a journey's turns; production builds a fresh one per
HTTP request. So turn 2 in the harness still knows turn 1's retrieved ids, making
`no_invented_product` marginally more permissive there than in the shop. It matters little in practice
— since ruling R47 the model does not emit ids in prose at all — but changing it changes what a
**safety** assertion sees, so it deserves its own decision rather than being a side effect. Ruling R91
fixed the sibling discrepancy (the tool-call budget) and deliberately left this one.

---

## 7. Everything else, ranked by how much it would embarrass you

1. **`TRAIL-JERSEY` has no image.** Every demo screenshot shows the no-image placeholder on the one
   product the whole demo is built around. The mapper is fine — the widget session proved it by
   fetching a card for a product that *does* have media. Assign any media to the fixture family.
2. **`options` ordering is whatever the server produced.** `{"Size":"M","Colour":"Blue"}` renders as
   *"M · Blue"*; a shopper says "blue, size M". Fix at the source by sorting deterministically or by
   the product's own option-group position.
3. **Installing into a Flex project breaks the shop until one file is deleted.** `composer require`
   pulls `symfony/ai-generic-platform`, whose recipe writes `config/packages/ai_generic_platform.yaml`
   with an `ai:` root key nothing can load. Documented in `README.md`; not fixable from inside the
   plugin (ruling R66).
4. **`tests/e2e/` is 6 of 8 passing**, with two fixes not yet re-verified by the session that wrote
   them.
5. **No German path has ever been exercised.** Snippets exist and the storefront locale reaches
   `Intl.NumberFormat`, but no sales channel in the test shop runs `de-DE`, so `74,90 €` is inferred
   rather than observed.
6. **Category paths on DAL cards are empty** — the category-tree association is not loaded. Nothing
   currently uses them.
7. **`CatalogScope::$minDescriptionWords` is unmapped** in the DAL: a word count is not a filterable
   field.
8. **DNS-rebind TOCTOU in the egress guard** (ruling R15). Parked with reasoning: exploiting it
   requires controlling DNS for a host an admin deliberately configured. **A stated blocker for a
   pilot, not for a demo.**
9. **Spec §5 describes `fx-030` as having 40 variants; the shipped fixture has four.** So there is no
   variant-matrix stress case in the catalogue at all, and any reasoning about ranking against these
   fixtures is reasoning about small families.

---

## 8. The environment

`/Users/R.Schulte/Workspace/shopping-assistant-test` — Shopware 6.7.13, storefront on
`http://127.0.0.1:8000`, `docker compose` (port 8080 remapped to 8081 in `compose.override.yaml`,
which also bind-mounts this repo at `plugin-src`). Installed through a **Composer path repository**,
never a `custom/plugins` symlink: Shopware registers only a non-Composer-managed plugin's own PSR-4
namespaces, so Symfony AI would not be autoloadable (ruling R59).

The demo product, whose entire purpose is to carry three traps — the parent-aggregate lie, a variant
price below the parent's, and price inheritance:

| variant | id | stock | own price |
|---|---|---|---|
| **Blue/M** | `a2a2…a2` | **0** | **74.90** |
| Black/M | `a5a5…a5` | 3 | 69.90 |
| Blue/S, Blue/L, Black/S, Black/L | `a1/a3/a4/a6` | 7/12/4/9 | inherit 79.90 |
| parent | `fafa…fa` | 35, `available = 1` | 79.90 |

Diagnostics that need no widget and no model:

```fish
bin/console swag:assistant:probe --search="Trail Jersey"
bin/console swag:assistant:probe --facets
bin/console swag:assistant:probe --variant=fafafafafafafafafafafafafafafafa --option=Blue --option=M
bin/console swag:assistant:probe --ask="…"   # needs the three ASSISTANT_LLM_* variables
```

`--variant` printing `null` for an under-specified selection is the guarantee, not a failure.

---

## 9. If you only do one thing

**Read one trace for each phrasing in §1 and diff the `understand` stage.** Everything else in this
document is either measured and parked, or waiting on that answer. It costs two model calls and it
decides whether §1 is a prompt problem, a tool-description problem, or a budget problem — three
different fixes, and today's evidence does not separate them.

Then, before changing the system prompt for §3, note that §1 hypothesis B suspects the same paragraph.
**Change one thing and measure.** Today's eval run moved four things at once and the result is
partially uninterpretable because of it — that is the mistake to avoid, and it was mine.

## 10. What this branch keeps teaching, in case it saves you a day

- **A command's own success output is not evidence that it did what was intended.** Four instances
  today: `framework:demodata` exited 0 twice while refusing to run; a `git commit` chained after a `cd`
  landed in a different repository; a `python` string-replace reported success while silently not
  matching, because a formatter had reshaped the target; and a `git add -A` swept up a parallel
  session's work-in-progress. Verify the effect, not the exit code.
- **Verify the framework, not the spec's description of it.** Ruling R58 chose `entities.xml` custom
  entities because the spec said so. They are registered exclusively by `AppManager`, so they are an
  **App** feature and this is a plugin (R78). `ARCHITECTURE.md` had it right the whole time.
- **A green test proves the tree it ran on.** The DAL mapper's tests passed while every variant came
  back nameless, because the test fixture *set* a name and a real Shopware variant has none — it
  inherits one. Found in the first minute of real catalogue use.
- **A safety assertion that fires on correct behaviour trains people to ignore it** (R85). That is why
  half of `AvailabilityClaimExtractor`'s tests exist to prove it stays quiet, and why the price audit
  now exempts figures the shopper introduced.
