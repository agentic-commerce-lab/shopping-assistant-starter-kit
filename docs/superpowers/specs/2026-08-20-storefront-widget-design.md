# Storefront assistant widget — design

**Date:** 2026-08-20
**Status:** approved, ready for planning
**Builds on:** `docs/superpowers/specs/2026-08-18-shopping-assistant-design.md` (D1–D16, A1–A7),
`ARCHITECTURE.md`, `docs/HANDOFF.md`

This closes **Must-have 1** ("chat widget in the storefront of a real 6.7 shop") and the visible half
of **Must-have 3** ("the cart page proves it"), both of which the 2026-08-19 plan descoped.

Decisions are numbered **W1…** to avoid colliding with the core spec's D-series.

---

## 1. What already exists

The backend is done and measured. This design consumes it and must not re-litigate it.

```
POST /assistant/chat        {"message": string, "token"?: string(32 hex)}
  → 200 {"token", "prose", "outcome", "cards": [...],
         "warnings": {"unbackedPrices": [...], "unbackedAvailabilityClaims": [...]}}
  → 400 malformed, empty, or > 2000 characters
  → 503 the shop has no model configured

GET  /assistant/history?token=…
  → 200 {"messages": [{"role", "prose", "cardIds"}]}     ← carries no warnings (W24)
```

> **`warnings` landed on `feat/grounded-core` in commit `42ca719` while this design was being
> written, and `docs/HANDOFF.md` does not yet document it.** The code is the authority here; the
> handoff's endpoint section and its known-issue 1 are both three commits stale. Verified by reading
> `AssistantController`, `AssistantTurn`, `AvailabilityClaimExtractor` and `FactRenderer` at `HEAD`.

A card carries `id, name, description, price, currency, stock, stockSource, inStock, deliveryTime,
url, imageUrl, options`. **Every figure comes from a server-rendered `ProductCard`; nothing is parsed
out of the model's prose** (D3, ruling R47). The widget inherits that guarantee and must not weaken
it — no figure in the UI may originate from `prose`.

### Measured facts this design is built on

Each was obtained by running the thing, not by reading about it.

| Fact | How it was established | Consequence |
|---|---|---|
| **A real turn takes ~19 s** | one `curl` against `POST /assistant/chat` | the loading state is the single most important element in the widget (§6) |
| `imageUrl` is `null` for fixture products | same response | the no-image state is a designed surface, not an edge case (§5.4) |
| `deliveryTime` is `null` | same response | the row is omitted, never rendered as `—` or `null` |
| **`unbackedAvailabilityClaims` fires only when *every* rendered card is out of stock** (or there are no cards) | `FactRenderer::unbackedAvailabilityInProse()` doc block at `HEAD` | the signal is narrow by design, so when it fires the true state is **known** — the UI can state a specific fact, not a vague hedge (W24) |
| **No per-message timestamp exists** | `ConversationTurn` is `{role, prose, cardIds, outcome}`; the transcript is a single `JsonField` (`ConversationDefinition:65`) | timestamps require a backend change (W20) |
| Plugin JS is collected from `dist/storefront/js/<name>/<name>.js` | `StorefrontPluginConfigurationFactory:131` | a committed `dist` makes the plugin work on install (W3) |
| `window.PluginBaseClass` is a documented global | `plugin.manager.js:799` + developer docs | no framework import, so no bundler-resolution requirement |
| SCSS compiles in PHP via `ScssPhpCompiler` | `vendor/shopware/storefront/Theme/` | styles need `theme:compile` only, never Node |
| The theme already ships **Inter Variable** (100–900) | `_theme.scss:28`, `dist/assets/font/` | brand-compliant text type costs nothing (W9) |
| `.scroll-up-button` is `fixed; bottom: $spacer-lg; right: $spacer-lg` | `layout/_scroll-up.scss` | it occupies the orb's corner and must be displaced (W6) |
| Storefront snippets auto-load from `Resources/snippet` | `SnippetFileLoader:158` | i18n needs no PHP |

---

## 2. Delivery and repository shape

### W1 — Standard Shopware storefront layout: authored `src/`, built `dist/`

Evidence, not preference. **SwagPayPal**, Shopware's own first-party plugin, ships:

```
src/Resources/app/storefront/
├── src/main.js                 ← auto-detected entry
├── src/{checkout,page}/*.js    ← one file per feature
├── src/scss/base.scss
└── dist/storefront/js/swag-pay-pal/
    ├── swag-pay-pal.js         ← entry
    └── swag-pay-pal.*.js       ← 11 hashed chunks
```

and the developer docs state that `Resources/app/storefront/src/main.js` is auto-detected as the
entry point.

**A rejected earlier proposal is recorded here because its reasoning was wrong.** A no-build variant
was considered — hand-authored browser JS placed directly at the `dist` path — on the grounds that
Shopware collects exactly one script file per plugin, forcing either a ~700-line file (against
AGENTS.md's ~400-line guidance) or a hand-rolled dynamic-import scheme. That premise is half true:
one file is the *entry*, but the build emits sibling chunks that load at runtime, which is what
SwagPayPal's 11 hashed files are. **Code splitting already solves the file-size problem**; the
no-build variant was reinventing it badly.

### W2 — Lazy registration, so page-load cost is near zero

`main.js` contains registrations and nothing else, each lazy, exactly as SwagPayPal does:

```javascript
PluginManager.register('SwagAssistantOrb', () => import('./assistant/orb.plugin'),
    '[data-swag-assistant-orb]');
PluginManager.register('SwagAssistantPanel', () => import('./assistant/panel.plugin'),
    '[data-swag-assistant-panel]');
```

This produces the split that governs every motion decision in §6:

| Layer | Downloaded | Motion technique |
|---|---|---|
| Orb — idle breathing, blink, hover | every page | **CSS keyframes only**, zero JS |
| Panel, thinking choreography, rendering | on first orb click | lazy chunk; a motion library is affordable |

### W3 — Commit `dist/`, and verify it in CI

Committing `dist` is ecosystem practice: it is why SwagPayPal works on install. Without it the
plugin activates and silently renders no widget until someone installs Node.

The CI drift check is **not** ecosystem practice — it is this repo's own standard applied to a new
artefact type. `docs/HANDOFF.md` records the lesson *"a command's own success output is not evidence
that it did what was intended"*; a committed artefact that can disagree with its source is the same
failure shape. So:

- `composer run build:storefront` wraps `shopware-cli project storefront-build`
- CI rebuilds and fails on a dirty `git diff -- dist/`

### W4 — Dependency policy

| Dependency | Verdict | Reason |
|---|---|---|
| `@shopware-ag/meteor-icon-kit` | **adopt** | the brand spec asks for Meteor icons; this is the sanctioned source rather than approximations |
| `motion` (Motion One) | **adopt only if needed** | start with CSS + WAAPI. Add it only if the §6 choreography genuinely fights us. It lives in the lazy panel chunk, so page load is unaffected |
| `gsap` | **reject** | roughly five times the weight of `motion` for capabilities this widget does not use (timeline scrubbing, ScrollTrigger, morphing) |
| React / shadcn / Tailwind | **reject** | the storefront is Twig + Bootstrap 5 + vanilla JS. shadcn means shipping React into a Bootstrap theme, running a second rendering paradigm inside it, and letting Tailwind's reset collide with the merchant's theme. Its proportions and restraint can be borrowed without a byte of its code |

Every dependency a starter kit ships is one every merchant inherits. Default is none.

---

## 3. Architecture

```
src/Resources/
├── views/storefront/base.html.twig               ← extends @Storefront, appends into base_body_inner
├── views/storefront/component/assistant/
│   ├── orb.html.twig
│   └── panel.html.twig
├── app/storefront/src/
│   ├── main.js                                   ← lazy registrations only
│   ├── assistant/orb.plugin.js                   ← always loaded, CSS-driven
│   ├── assistant/panel.plugin.js                 ← open/close, focus, keyboard, a11y
│   ├── assistant/transport.js                    ← chat · history · cart
│   ├── assistant/render.js                       ← messages, cards, timestamps
│   ├── assistant/thinking.js                     ← the 19 s choreography
│   └── scss/base.scss + components/*
├── app/storefront/dist/…                         ← committed, CI-verified (W3)
└── snippet/{en_GB,de_DE}/                        ← auto-discovered, no PHP
```

### W5 — The widget renders on all storefront pages, from the base template

Appended inside `base_body_inner` via `{{ parent() }}`. Excluding checkout is a one-block Twig
override, **documented as the extension point rather than decided for the merchant** — the argument
cuts both ways and it is not ours to settle.

### W6 — The widget displaces the theme's scroll-up button

`.scroll-up-button` is fixed to the same corner. Our SCSS lifts it by the orb's height plus a gap,
scoped so it applies only when the widget actually renders. Needs verification against a
non-default theme (§11).

### W7 — Server-side additions are one class and one config card

- **`AssistantWidgetExtension`** (Twig extension) exposing `swag_assistant_widget_enabled()`,
  delegating to the existing `SystemConfigLlmSettings::isConfigured()` and the `killSwitch` field.
  The rule for "is this shop configured" already exists in PHP; re-deriving it in Twig would
  duplicate a contract, which AGENTS.md forbids. **No orb renders on a shop that would answer 503.**
- **A `config.xml` card** — `widgetEnabled` (bool, default true), `assistantName` (text, default
  "Shopping Assistant"), `greeting` (textarea).

### W8 — Configuration reaches the browser as data attributes

Route URLs via `path()`, plus `assistantName`, the storefront locale (for `Intl.NumberFormat`), and
`enableAddToCart`.

**On that last flag:** the card's Add-to-cart button posts to Shopware's *own* cart route, so
`enableAddToCart` — which gates whether the assistant's `add_to_cart` **tool** is constructed — does
not technically govern it. It gates the button anyway. A merchant who switched off "allow the
assistant to add items to the cart" will not accept a plugin that still shows add buttons. The
config means what it says.

---

## 4. Visual system

### W9 — Type: Inter Variable, three steps

The theme ships Inter Variable with the full 100–900 axis, so brand-compliant text type is free.

| Step | Size / weight | Used for |
|---|---|---|
| Meta | 12 / 500 | timestamps, stock labels, the variant-stock note |
| Body | 16 / 400 · 600 name · 650 price | AI prose, user bubble, card name, price |
| Heading | 20 / 600, −0.01em | panel title |

Ratios are 1.33 and 1.25. An earlier six-value scale (16 / 15 / 14.5 / 14 / 11.5) was exactly the
"flat type hierarchy" anti-pattern and is rejected.

Prices use `font-variant-numeric: tabular-nums`. A price that shifts as digits change reads as
unreliable, which is the one impression this product cannot afford.

**Poppins is not used**, though the brand spec reserves it for headlines. One 20px heading in a
420px panel does not justify pushing a font file onto every storefront page, and Google Fonts is a
GDPR problem for German shops. Self-hosting it for a single string is disproportionate. **Recorded
deviation, not drift.**

**Inter is also on the anti-slop "overused faces" list.** Overridden: Inter is Shopware's brand text
font and is already in the theme. That axis is not free.

### W10 — Colour: brand palette only

| Role | Value | Note |
|---|---|---|
| Panel surface | `#ffffff` | |
| Primary text | `#00153e` Night Blue | |
| Meta | `#a3a6b5` Light Gray | |
| Accent / interactive | `#0870ff` SW 500 | links, send, focus ring |
| Brand presence | `#189eff` Bright Blue | orb only |
| User bubble fill | `#f0f6ff` SW 50 | |
| Hairline | `#e6e9f0` | the one derived value |
| In stock | `#57d998` Teal | |
| Low stock | `#f88138` Orange | |
| Out of stock | `#838489` Dark Gray | |

**The palette contains no red.** Out-of-stock is therefore muted grey rather than alarming, and
error states use Orange. Naming the constraint rather than quietly inventing a red.

**One accent colour** (SW 500). Bright Blue is confined to the orb. Teal / Orange / Dark Gray are
semantic status colours, not accents.

**Stock is never signalled by colour alone** — always dot **plus** label.

### W11 — Surfaces: one decision each, deliberately opposite

- **Panel: elevation only.** Real offset, neutral blur, **no border**.
- **Cards: 1px border only.** **No shadow.**

Both follow from "hairline border + wide diffuse shadow together — commit to one". The panel floats,
so elevation is the truthful signal; cards sit in the flow, so a defined edge is. A scroll row of
shadowed cards reads as floating debris.

### W12 — Radii: the tighter option throughout

| Element | Radius |
|---|---|
| Panel | 12 |
| Card | 8 |
| Button, input | 6 |
| User bubble | 12, with a 4px bottom-right corner |
| Orb | circle |

The bubble's asymmetric corner is the one chat affordance kept: a uniformly-radiused rectangle stops
reading as speech. The orb is exempt as a signet-like mark, not a control surface.

### W13 — Exactly one gradient and one coloured shadow in the whole widget, both on the orb

The anti-slop list bans coloured glow shadows outright — "zero-offset chromatic halo" is named as the
loudest tell — and the brand spec bans gradients on buttons and cards while permitting them for "a
larger brand moment such as a hero, illustration, or background treatment".

The orb takes both anyway, because the brief is a luminous orb: the glow *is* the specification, not
a reflex reach for the median. The rulebook's own caveat governs — "reaching for one when the axis
was free means you weren't deciding", and this axis is not free.

**Everywhere else in the widget: flat fills, neutral shadows.** The exception is bounded to one
element so that it stays an exception.

### W14 — No Shopware wordmark; the signet appears only on the orb

The widget runs in a *merchant's* storefront. The standalone signet is an approved brand variant, and
at 16px on the sphere it matches the brief. It is wrapped in its own Twig block so a merchant can
swap or remove it. No lock-up, no wordmark, nowhere.

---

## 5. Components

### 5.1 Orb — 60px

```
        ╭─────────────╮        1.5px Bright Blue rim @ 50%
      ╱                 ╲      outer glow 6px @ 10% (W13)
     │    ▮       ▮      │     eyes 7×14, r3.5, white, 11px apart
     │                   │
     │            ◗      │     Shopware signet, 16px @ 40%
      ╲                 ╱
        ╰─────────────╯
   radial-gradient SW 500 → SW 600, highlight top-left
```

Anchored bottom-right, 20px inset. 56px on mobile.

### 5.2 Panel — 420 × min(640px, 100vh − 7rem)

```
┌────────────────────────────────────────┐  r12, elevation only
│ ●  Shopping Assistant             ✕   │  56px header, orb docked at 28px
├────────────────────────────────────────┤
│ Hi — I can look things up in this      │  AI: full width, no container
│ shop's catalogue.                      │
│ 09:41                                  │
│                  ┌───────────────────┐ │
│                  │ show me the trail │ │  user: contained, right, SW 50
│                  │ jersey in blue, M │ │
│                  └───────────────────┘ │
│                              09:41     │
│ I found the Trail Jersey in Blue,      │
│ size M.                                │
│ ┌────────────────────────────────────┐ │  hero card
│ │ ▢  Trail Jersey                    │ │
│ │    Blue · M                        │ │
│ │    74,90 €                         │ │
│ │    ● Out of stock                  │ │
│ │    [ View product ]   [ Add → ]    │ │
│ └────────────────────────────────────┘ │
│ 09:42                                  │
├────────────────────────────────────────┤
│ ┌────────────────────────────┐  ┌────┐ │
│ │ Ask about this shop…       │  │ ↑  │ │
│ └────────────────────────────┘  └────┘ │
└────────────────────────────────────────┘
```

Non-modal on desktop, no page scrim: the storefront stays visible because "show me that in blue" is
about the page behind the panel. Full-screen sheet on mobile.

A 16px body in a 420px panel yields a ~45ch measure, below the 65–75ch ideal. That is inherent to a
side panel, whose width was fixed by the placement decision; the measure target assumes a page
column. Recorded rather than papered over by shrinking type.

### W15 — The user/AI contrast carries the whole hierarchy

**User is contained; AI is unbounded.** User messages are right-aligned bubbles with an SW 50 fill.
Assistant messages are full-width text with no container at all. That reads as the shop speaking
rather than a peer in a group chat — no avatar or accent rule is needed, and none is added.

### 5.3 Cards — adaptive by count

- **1 card** → a hero card: 72px image, name, options, price, stock, both CTAs.
- **2+ cards** → a horizontally scrollable row of compact cards.

This matches how the assistant behaves: a resolved variant is one answer, a search is a shortlist.
The row scrolls inside its own container; the panel never scrolls horizontally.

### 5.4 Card states, all driven by real payload fields

| Field | Rendering |
|---|---|
| `imageUrl: null` | 72px SW 50 tile with a Meteor image glyph in SW 200. A designed absence, never a broken `<img>` |
| `stockSource: "parent"` | hairline note: *"Stock shown for the product, not this variant."* |
| `deliveryTime: null` | row omitted entirely |
| `price` + `currency` | `Intl.NumberFormat` with the storefront locale; we receive `74.9` and `"EUR"`, not a formatted string |
| `inStock: false` | Dark Gray dot, explicit label, Add button disabled **with a stated reason** |

**The `stockSource` note is D4 made visible to a shopper**, and nothing else in the product says it.

### 5.5 The `warnings` field

### W24 — When the prose contradicts the cards, the UI corrects it in words

`POST /assistant/chat` returns `warnings.unbackedPrices` and
`warnings.unbackedAvailabilityClaims` — the phrases in the reply that the rendered cards contradict.
The controller's own comment states the intent: *"The cards are always authoritative; this says when
the sentence beside them is not, so the interface can annotate it, de-emphasise it, or drop it."*
Ignoring it would leave the handsomest part of the product carrying the ugliest known defect.

**`unbackedAvailabilityClaims` non-empty** → a correction notice renders **between the prose and the
cards**:

> ⚠ *This is currently out of stock. The card below is correct.*

The notice can be this specific because the signal is narrow: it fires only when *every* rendered
card is out of stock, so the true state is not a guess. Orange, icon plus text label, never colour
alone.

**`unbackedPrices` non-empty** → a quieter note in the same slot: *"The prices on the cards are the
ones that apply."*

Three things this deliberately does **not** do:

- **It does not edit or delete the prose.** Rewriting a reply to hide a mistake is how a product
  loses the right to be trusted, and phrase-level surgery would mangle sentences.
- **It does not reduce the prose's opacity.** "De-emphasise" is one of the options the comment
  offers, but dimming body text fails the contrast floor in §8. Emphasis is added to the correction,
  not subtracted from the text.
- **It does not highlight the offending phrase inline.** Underlining the model's error mid-sentence
  draws the eye to a failure and undermines confidence in every other sentence.

**`GET /assistant/history` does not return `warnings`, so a reloaded conversation loses the
correction** and the misleading sentence returns unannotated. That is unacceptable for the most
shopper-visible defect in the product, and the fix sits on exactly the seam W20 already opens: the
transcript is where both `createdAt` and `warnings` belong. **W20 is therefore extended to persist
and re-emit warnings alongside the timestamp** — same file, same mapping, one change instead of two.

---

## 6. Motion

### W16 — The 19-second wait is handled with phases, not a loop

A 2-second loop played ten times is where "cute" dies. The thinking state is phased on elapsed time:

| Elapsed | Orb behaviour | Copy |
|---|---|---|
| 0–3 s | pops in at 32px, blinks twice, pupils begin scanning L→R; three SW 200 dots wave below | *Thinking…* |
| 3–8 s | tilts side to side while a thin SW 100 line sweeps beneath it; eyes track the sweep | *Thinking…* |
| 8–15 s | one eye squints, slow bob | *Still looking. Give me a sec.* |
| 15 s+ | slow pulse, eyes half-close, a small "…" drifts upward | *This one's taking a moment.* |
| arrival | one quick hop, eyes widen, then shrinks away | prose fades up from 4px below; cards stagger 40 ms apart |

**The shopper sees state changing, which reads as progress — while the copy claims nothing about
server work we cannot observe.** Genuine staged narration ("searching the catalogue…") was rejected
because trace events are persisted once after the run completes, so there is no progress signal to
read; inventing one would put unbacked claims about server work into a product whose entire thesis is
that the UI never states what the server did not produce.

Streaming the prose over SSE is the larger win and is **explicitly out of scope** (§10). The
rendering path takes prose incrementally so that swapping the transport later is a transport change,
not a rewrite.

### W17 — Idle and open

**Idle**, CSS only, on every page:
- `breathe` — 4 s, scale 1 → 1.025, glow expands with it
- `blink` — eyes `scaleY` to 0.1 for 140 ms on a ~7 s loop, delay randomised per page load through a
  custom property so two shoppers never blink in lockstep
- hover — scale 1.06, glow intensifies, eyes look up slightly, 150 ms
- **first visit only**, after 4 s: a label slides out — *"Ask me anything about this shop"* — auto-hides
  after 6 s, once per session. Discoverability without nagging

**Open** — the panel expands from the orb's position with `border-radius` interpolating 30 → 12 and
`scale(.92) → 1`, 320 ms `cubic-bezier(.2,.9,.25,1)`. The corner orb fades as the 28px header orb
fades in: one continuous object without a brittle FLIP morph. Content staggers — header 0, messages
60 ms, composer 120 ms.

### W18 — Three motion rules that constrain implementation

- **Nothing sits at `opacity: 0` at rest.** Rehydrated history renders visible immediately; only
  genuinely new messages animate. JS enhances an entrance, it never gates existence.
- **No layout-property animation.** `transform`, `opacity`, `border-radius` only.
- **No decorative pulse.** The thinking dots are honest (a request really is in flight). Stock dots
  are static.

Easing is exponential ease-out throughout. The arrival hop is character animation on a mascot, which
is not the banned "bounce easing on a UI element".

### W19 — `prefers-reduced-motion` is a first-class state

Orb static; no sweep, no bob, no hop. **The copy still changes at the same thresholds**, so the
reassurance survives without the motion. Not a nice-to-have.

---

## 7. Behaviour and state

Single source of state: `sessionStorage.swagAssistantToken`. `GET /assistant/history` rehydrates on
mount. Panel open/closed is **not** persisted — a shopper who closed it wanted it closed.

### W20 — Per-message timestamps are added server-side

No per-message timestamp exists today: the transcript is one `JsonField` and `ConversationTurn` is
`{role, prose, cardIds, outcome}`. Since the spec requires a timestamp under every message and a
conversation survives page loads, the timestamp must survive with it.

- `ConversationTurn` gains `?\DateTimeImmutable $createdAt` **and the turn's `warnings`** (W24)
- the transcript JSON stores both per turn; deserialisation tolerates existing rows without either
- both `POST /assistant/chat` and `GET /assistant/history` emit them

Warnings ride along with the timestamp because they need the identical change — the same DTO, the
same transcript mapping, the same history payload. Splitting them would mean touching
`DalConversationStore` twice for one seam.

A client-side alternative (timestamps in `sessionStorage` beside the token, which has an identical
lifetime) was rejected: message times would exist only in one browser tab and be invisible to a
merchant reading the conversation through the Admin API, in a product whose selling point is that
every turn is recorded and inspectable.

### W21 — Two paths to the cart, and neither is the other

| Path | Route | Guarded by |
|---|---|---|
| Card button | `POST /checkout/line-item/add` (Shopware's own) | `enableAddToCart` (W8), `inStock` |
| *"add that to my cart"* | the assistant's `add_to_cart` tool | the tool's own guardrails |

The shop keeps ownership of cart rules; we add no cart logic. There is no CSRF layer in 6.7, so the
button needs no token. On success the shop's cart count refreshes.

### W22 — Every state has a designed form

| State | What renders |
|---|---|
| Empty | the configured greeting, timestamped, no cards |
| Loading | the phased orb (W16) |
| `503` not configured | **the orb never renders** — gated by the Twig extension (W7) |
| `400` too long | inline counter turns Orange past 2000 characters, send disabled **before** the request |
| `429` / kill switch | *"The assistant is unavailable right now."* Input disabled, orb stays |
| Network failure | the shopper's message stays in the log with a retry affordance; nothing is silently lost |
| Cart add failed | inline on the card, not a toast |
| Prose contradicts the cards | correction notice between prose and cards (W24) |

### W23 — No cancel affordance

An `AbortController` would abort the client's fetch while the server completes and persists the turn
anyway — so the answer would reappear on the next page load and the button would have lied. Better to
ship no cancel than a dishonest one. Open item (§11).

---

## 8. Accessibility

- Message list is `role="log"` with `aria-live="polite"`. Thinking-copy changes go through it,
  **throttled to the four W16 updates** — not a stream.
- Panel is `role="dialog"`, `aria-modal="false"` on desktop (it is genuinely non-modal) and `"true"`
  for the mobile sheet. `Escape` closes; focus returns to the orb.
- Focus ring: 2px SW 500, 2px offset, on every interactive element including the orb.
- Contrast ≥ 4.5:1 body and ≥ 3:1 large, **in every state**, including placeholders, disabled
  buttons and focus rings.
- Stock, and every other status, carries a text label — never colour alone.
- The orb is a `<button>` with an accessible name from a snippet, not a `<div>`.

---

## 9. Testing

**There is no JS test infrastructure in this repo, and this design does not introduce one.** Jest or
Vitest would mean a second toolchain, a second CI lane, and tests that largely restate DOM
construction — "tests mirroring the implementation", which pass because they repeat the code.

| Layer | Tool | What it covers |
|---|---|---|
| PHP | PHPUnit (existing) | the Twig extension's gating logic; the W20 timestamp end to end through store → controller → payload; the history payload shape |
| Widget | Playwright against the running shop | orb renders; panel opens; a real turn completes; history survives a reload; a card Add-to-cart lands in the real cart |
| Rendering functions | **nothing** | they would pass by restating themselves |

The Playwright layer is the only one that can catch what actually breaks, and the shop at
`http://127.0.0.1:8000` is already running.

`composer run quality` must stay exit 0 and the existing 276 tests must stay green. W20 touches a
readonly DTO and the transcript mapping, so some will need updating — updating an assertion because
a contract deliberately changed is legitimate; loosening one to get green is not.

---

## 10. Out of scope

- **SSE / token streaming.** The largest perceived-latency win and a backend change (controller,
  runner, incremental trace writes). W16 keeps the rendering path incremental so it drops in later.
- **Real staged progress from trace events.** Needs incremental trace writes plus a polling endpoint.
- **Widening the availability-claim detector.** The extractor landed in commit `42ca719` and is
  deliberately narrow: a mixed result set with one in-stock and one sold-out card does not flag a
  claim about the sold-out one, because the prose does not reliably say which product it means. The
  widget consumes the signal as given (W24) and does not attempt to improve it. Widening it needs the
  claim tied to a specific product — a grounding change, not a UI one.
- **Markdown rendering in prose.** Prose is escaped and rendered as plain text with paragraph breaks.
  A parser is an XSS surface for no established benefit.
- **Admin trace UI.** Cut in ruling R61; the Admin API already serves it.
- **Voice, avatar, streaming video, headless storefronts.**

---

## 11. Open items and unverified claims

Recorded as open rather than asserted, because this repo's standing lesson is that a claim without a
command behind it is not evidence.

1. **Does PHP complete the turn after a client disconnect?** If it does, a shopper who navigates
   mid-turn recovers the answer from history on the next page load, which makes the 19 s wait
   survivable rather than destructive. **Unverified — must be tested, not assumed.**
2. **The `.scroll-up-button` offset (W6)** is verified against the default theme only.
3. **No cancel affordance (W23)** — revisit if streaming ever lands, since an abort would then mean
   something.
4. **Whether `motion` is needed at all (W4).** Start with CSS + WAAPI; add it only if the
   choreography fights us. Decide with the code in front of us.
5. **`options` ordering.** The payload is a map (`{"Size":"M","Colour":"Blue"}`); rendering order is
   whatever the server produced. If it proves unstable, sort deterministically.
6. **The branch was moving while this was written.** `warnings` landed in `42ca719` mid-design;
   `AssistantRunner`, `FactRenderer` and `ProseAudit` had **uncommitted edits in the working tree**;
   and `DalConversationStore` — which W20 must modify — changed in `36ada74`. Every line reference in
   this document was read at that `HEAD`. **Re-read these files at implementation time instead of
   trusting the references here**, and confirm the `warnings` shape has not moved again. This is the
   same failure class as the handoff's *"a green test proves the tree it ran on"*.

---

## 12. Anti-slop compliance

Audited against `1 Work/3 Resources/Design/How to kill AI slop`.

**Direction, in one line:** *a merchant's storefront assistant — Shopware-brand-restrained panel
chrome, with all the personality concentrated in one luminous character.*

| Rule | Status |
|---|---|
| Flat type hierarchy | **fixed** — three steps, 1.33 / 1.25 (W9) |
| Body < 14px | **fixed** — 16px (W9) |
| Hairline border + diffuse shadow together | **fixed** — one per surface, opposite answers (W11) |
| Coloured glow shadow | **override, bounded to the orb** (W13) |
| Overused face (Inter) | **override** — brand font, already in the theme (W9) |
| Gradient after gradient | one gradient in the widget (W13) |
| Eyebrow / kicker | none |
| Nested cards | none — cards appear only inside an assistant message, never as structure |
| Gradient text | none |
| Emoji as icon system | none — Meteor Icon Kit (W4) |
| One accent colour, one radius system | SW 500; W12 (W10) |
| Bounce easing | exponential ease-out; the arrival hop is character animation (W18) |
| Pulsing dot not tied to live data | none — thinking dots are honest, stock dots static (W18) |
| `opacity: 0` at rest revealed by JS | forbidden (W18) |
| Layout-property animation | forbidden (W18) |
| Buzzwords, em-dash saturation | copy reviewed; microcopy in W16 rewritten |
| Real states: hover, focus, disabled, loading, empty, error | all specified (W22, §8) |

Two overrides, both on axes the brief had already decided. Everything else complies.
