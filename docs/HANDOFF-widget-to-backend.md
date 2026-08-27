# Handoff — 2026-08-20: what the widget saw, for whoever owns the backend

> **HISTORICAL. Not the current handoff — see `HANDOFF.md` in the repository root.**
>
> Kept because `docs/HANDOFF.md` calls it "the source for several items below", so deleting it would
> leave that document's findings unverifiable. Describes branch `feat/grounded-core`.

**From:** the session that built the storefront widget (commits `7196257` → `9517461`).
**For:** the session working on the pipeline behind it.
**Branch:** `feat/grounded-core`. We were both committing to it; nothing here is merged anywhere else.

The widget is now a real client of your endpoint, driving it with real questions against the real
catalogue. That surfaced things the eval suite and the probe command cannot see, because they do not
read the reply the way a shopper does.

**Every claim below has a command or an observation behind it.** Where I could not verify something, it
says so.

---

## 1. Read this first: two things that look broken and are not

I suspected both and checked before writing them down. **Do not spend time on either.**

**`imageUrl: null` is not a mapper bug.** Every card from the `TRAIL-JERSEY` family returns
`imageUrl: null`, which made me suspect the media association was never loaded. It is loaded:

```
GET /assistant/cards?ids=01a01b4f971d73099d0915127e7e4a7c
  → "imageUrl": "http://127.0.0.1:8000/media/a0/ae/de/1787164584/…jpg?ts=1787164584"
```

The mapper is fine. **`TRAIL-JERSEY` simply has no image assigned** — a fixture gap, and the one
demo-quality item worth fixing (see §6).

**`deliveryTime: null` is not a mapper bug either.** Only 3 products in the shop have a
`delivery_time_id` at all, and the mapper returns it for all three:

```
Aerodynamic Concrete PortGear   deliveryTime='Instant download'
Incredible Steel GeoDash        deliveryTime='Instant download'
Heavy Duty Wooden Genitol       deliveryTime='Instant download'
```

`null` on `TRAIL-JERSEY` is the honest answer, not a dropped field. (The "Delivery period:
21/08/2026 – 23/08/2026" on the cart page comes from the shipping method, not from the product.)

---

## 2. Variant resolution is phrasing-sensitive — the most shopper-visible finding

Two questions carrying **identical information**, one conversation apart, produced different answers:

| Question | Card returned | Price | Stock | Outcome |
|---|---|---|---|---|
| *"show me the trail jersey in black, size M"* | `a5a5…` — **Black/M** | 69.90 | 3 | correct |
| *"do you have the trail jersey in black, size M?"* | `fafa…` — **the parent** | 79.90 | 35 | `tool_limit_exceeded` |

The second is the more natural way to ask, and it is the exact phrasing in `README.md`'s own example
and in the eval journeys.

Two separate problems are tangled here, and I cannot tell you which causes which:

- **The parent came back for a fully-specified variant question.** Colour and size were both given.
  `stockSource` correctly reported `parent`, so the grounding layer knew — the resolution simply did
  not happen.
- **The turn exhausted its tool budget** on a single-product question and ended with *"I was not able
  to finish handling that request. Could you narrow it down — for example, ask about one product at a
  time?"* The shopper had asked about one product.

Worth checking whether the interrogative form sends the model down a `search_products` path where the
imperative form sends it to `select_variant`, and whether the five-call budget is being spent before
resolution is reached. Ruling R74 records the same arithmetic biting before.

**What the widget does about it meanwhile, so you know what a shopper currently sees:** a card whose
`stockSource` is `parent` gets **no add-to-cart button**, only a link to the product page. Offering
one-click purchase on an unresolved variant would let someone who asked for "black, size M" buy an
unspecified one — the expectation D4 exists to prevent. The parent-stock note renders too, so the
shopper is told the figure is not the variant's. That is disclosure, not a fix.

---

## 3. The model describes the interface

Real replies, verbatim:

> "I found the Trail Jersey in Blue, size M — **the card here** shows its current price and stock."

> "I found the Trail Jersey in Blue, size M — **the card** shows its current price and stock. Let me
> know if you'd like me to add it to your cart!"

The model is asserting something about the presentation, which it cannot see and which is not part of
its grounding. Two consequences:

- **It breaks any client that is not this widget.** A voice surface, an SMS integration, or the
  `swag:assistant:probe --ask` output all read "the card here shows…" with no card anywhere. The
  endpoint is explicitly the contract others build against, so prose that assumes one client is a
  contract leak.
- **It is the model narrating a UI it was never told about**, which is the same class of thing D3
  forbids for figures — describing something it has no source for.

Probably a system-prompt line. Suggested direction rather than a patch: have it state facts plainly
("It is out of stock in Blue / M") and leave presentation to the client.

Not urgent. It reads fine *in* the widget, which is exactly why it would survive review unnoticed.

---

## 4. The availability detector never fired in live use

`warnings.unbackedAvailabilityClaims` was **empty in every live turn I ran** — roughly six, including
several deliberately aimed at the sold-out Blue/M. The model deferred correctly each time ("the card
shows its current price and stock"), which is the behaviour you want.

So: not a defect, and possibly evidence that `SystemPrompt`'s change is working. But it means **the
detector's real-world hit rate is unmeasured**, and I could not verify the widget's correction path
against a genuine warning — I had to replay the exact payload `AssistantController` emits, with the
prose from ruling R75, to see it render.

If you want that path covered end to end, the missing piece is an eval journey that provokes an
availability claim rather than one that hopes for it.

---

## 5. Changes I made inside your area

All green: **349 tests / 884 assertions, `composer run quality` exit 0.**

**A guardrail was failing open, and I fixed it.** `bin/console system:config:set` stores every value
as a **string**, and `(bool) "false"` is `true` in PHP:

```
system_config → SwagAssistantStarterKit.config.killSwitch = {"_value":"false"}
```

`SystemConfigAssistantConfig::boolOr()` therefore read a kill switch turned *off* from the CLI as
**ON**. The widget only made it visible by refusing to render. **The direction that matters is
`enableAddToCart`**, whose help text promises the tool "is never constructed" when off — under that
cast, a merchant disabling it from the CLI got the tool constructed anyway, while the admin form
showed it disabled. Now read through `FILTER_VALIDATE_BOOLEAN`, six tests in
`tests/Core/Config/SystemConfigBooleanReadingTest.php`.

**This also revises a claim in `docs/HANDOFF.md`**: its evidence table said *"merchant config reaches
the pipeline — `system:config:set` → `system:config:get` round-trips"*. That proved **storage**, not
**interpretation**. Both commands agreed while the value was being read wrong. The admin UI sends real
JSON booleans and was never affected — the only path that exercised the bug was the one used to
verify it.

**A new route:** `GET /assistant/cards?ids=…` in `AssistantCardController`, with `CardIdList` owning
the parsing (max 12, deduplicated, order-preserved, malformed → `{"cards":[]}`). It exists because
`GET /assistant/history` returns card ids only — deliberately — so a re-hydrated conversation showed
prose describing a card that was not there. Cards are re-rendered from the catalogue on read, never
replayed from the transcript, and the merchant's `CatalogScope` still applies, so a product blocked
since that turn does not come back. Its own controller because `AssistantController` was already at
four collaborators.

**`ConversationTurn` gained `createdAt` and `warnings`**, both persisted in the transcript and emitted
by `history`. `TranscriptCodec` gained two collaborators — `StoredTimestamp`, `StoredWarnings` —
because folding the narrowing into either `JsonShape` or the codec tripped the complexity gate, and
the standing constraints say split rather than suppress. **All fourteen `new ConversationTurn(...)`
call sites now use named arguments**, which is what makes the parameter-count pragma on that
constructor legitimate under carve-out 1.

**`InMemoryConversationStore` now round-trips through `TranscriptCodec`.** It was holding
`ConversationTurn` objects, so the store contract test passed by object identity and never touched
serialisation — a field the real store silently dropped would still have looked stored. Proof it
mattered: dropping `createdAt` from `encode()` was caught by **one** test before and **two** after.

**`twig/twig` is now a declared dependency** — `src/` uses it directly for the widget's Twig
extension, so it was a shadow dependency.

---

## 6. Smaller things, in the order I would care about them

1. **`TRAIL-JERSEY` has no image.** Every demo screenshot shows the no-image placeholder on the one
   product the demo is built around. The placeholder is deliberate and designed, but it is not what
   you want on stage. Assigning any media to the fixture family fixes it; the mapper already works.
2. **`options` ordering is whatever the server produced.** `{"Size":"M","Colour":"Blue"}` renders as
   *"M · Blue"*, which reads oddly — a shopper says "blue, size M". Sorting deterministically, or
   ordering by the product's own option-group position, would fix it at the source.
3. **Category paths on DAL cards are still empty** (existing known issue 8). The widget does not use
   them, so this is not blocking anything I built.
4. **No German path has been exercised.** Snippets exist for `de` and the storefront locale reaches
   `Intl.NumberFormat`, but no sales channel in the test shop runs `de-DE`, so prices formatting as
   `74,90 €` is inferred, not observed.

---

## 7. What the widget would want next, ranked by shopper impact

Not requests — just where the leverage is, from the side that watches people wait.

1. **Streaming the prose over SSE.** A turn takes **16–19 seconds**, measured repeatedly. The phased
   thinking indicator makes that tolerable and cannot make it good. First tokens at ~2 s would be the
   single largest improvement available to this product, and the widget's rendering path already takes
   prose incrementally — swapping the transport is a transport change, not a rewrite.
2. **Fixing §2.** A shopper who phrases the question the natural way currently gets the parent's price
   and a non-answer.
3. **Real progress from trace stages.** Deliberately *not* faked in the widget: trace events are
   persisted once, after the run completes, so there is no progress signal to read and the copy only
   ever says how long it has been. If traces were written incrementally, the indicator could say what
   is actually happening.

---

## 8. One behaviour to know about before you change the endpoint

**The server finishes a turn even when the client is gone.** Measured: client aborted at 3 s, the
conversation went from 2 turns to 4 at +15 s.

Two consequences already designed around, which a change here would break:

- A shopper who navigates mid-turn recovers the answer from `history` on the next page load. That is
  what makes a 19-second wait survivable rather than destructive.
- **The widget's retry button re-hydrates before re-sending.** Blindly re-sending would record the
  shopper's question twice, because the first turn usually completed. If you ever make the server
  abort on disconnect, that logic becomes wrong in the other direction.

---

## Where to look

| Thing | Path |
|---|---|
| The widget's design, W1–W24 | `docs/superpowers/specs/2026-08-20-storefront-widget-design.md` |
| The plan it was built from | `docs/superpowers/plans/2026-08-20-storefront-widget.md` |
| Browser checks, and what they found | `tests/e2e/` — **6 of 8 passing**, two fixes not yet re-verified |
| Endpoint contract as it now stands | `docs/HANDOFF.md`, "The endpoint" |
