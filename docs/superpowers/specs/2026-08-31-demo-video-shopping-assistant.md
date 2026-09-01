# Demo video — "Shopping Assistant"

Design of record for an ~80-second Remotion demo video of this plugin, Shopware-branded,
with every interface element rebuilt as React components ported from this repository's own
source and verified against the live demo shop.

**Status:** approved design, not yet implemented.
**Deliverable repo:** `~/Workspace/shopping-assistant-demo-video` (sibling, not this repo).
**Deliverable file:** `out/shopping-assistant-1080p30.mp4`, 1920×1080 @ 30fps, H.264.

## Purpose

Reproduce, as a self-contained video, the demo the builder would give a customer live.

Two audiences, one cut:

| Audience | What earns their attention | Beats |
|---|---|---|
| Merchants — it works out of the box | A shopper gets a real answer, and the merchant can see what happened | 1–10 |
| Partners / agencies — it extends in code | A tool factory is a class and a DI tag | 11 |

It is a teaser, not a manual. Success is that a viewer who has never seen the plugin
understands three claims: the answers are grounded in the merchant's catalogue, every
conversation is observable in the merchant's Administration, and the whole thing is
extensible in code.

## Constraints that shaped this

1. **~80 seconds.** Everything below competes for 2400 frames. The shot list is the
   contract; a beat that overruns takes the time from a named other beat, never silently.
2. **No narration.** Music plus SFX plus on-screen captions. The builder narrates live over
   it in a call, and it still reads on mute in Slack.
3. **Rebuilt, not recorded.** Every UI element is a React component. Nothing is a
   screenshot pasted into a timeline.
4. **Ported, not eyeballed.** See "The fidelity rule".
5. **Real, not invented.** The conversation, its trace, the prices, the stock states and the
   timings all come from one actual run on the live demo shop, captured 2026-08-31.

## What is deliberately not in it

| Omitted | Why |
|---|---|
| A refusal / escalation beat | `VISION.md` calls the honest refusal the differentiator, and at 80 seconds in front of a merchant it still lost the trade-off. Explicitly rejected, not overlooked. Reopening it costs another beat 4 seconds. |
| Shop-information retrieval (RAG) | Needs MariaDB 11.7+ and two extra packages; a teaser that shows it implies it is always on. |
| Product comparison, page context awareness | Good features, no seconds left. |
| Config / settings screens | Nobody was ever convinced by a settings form. |
| A 9:16 vertical cut | The Administration data grid does not survive the crop, and that beat is 25% of the video. |

## The shop on screen: Sidepath

The demo shop is real and live: `https://shoppingassistan-rschulte.eu-core-1.shopdev.de`.
Brand assets are in this repository at `docs/demo-catalog/brand/{logo,logo-inverse,mark}.svg`.

| Token | Hex |
|---|---|
| Paper | `#f2f0ed` |
| Ink | `#17191b` |
| Teal | `#0e8c88` |
| Rust | `#c4491f` |
| Hairline | `#d5d0c8` |

Type: Barlow Condensed 700/800 uppercase (display), IBM Plex Mono (meta), Inter (body) —
all OFL, bundled with the video project.

The live home page, captured as reference, carries: the Sidepath lockup, a search field, a
basket reading `€0.00` in teal, a ten-item nav (Home · Brakes · Tyres · Maintenance ·
Helmets · Merch · Accessories · Apparel · Restricted · Components), a dark gravel-road hero
reading **PARTS FOR THE ROADS / NOT ON THE MAP**, a stat band (*REAL STOCK COUNTS — NO
BACK-ORDER THEATRE · DISPATCH 1–3 DAYS — FROM ONE WAREHOUSE · 28 PRODUCTS — AND NOT ONE
FILLER*), and a **THE AISLES / 09 DEPARTMENTS** chip row.

### The widget wears Sidepath's colour — confirmed, not assumed

The live shop runs `entryPointStyle: icon` (the neutral flat disc with a masked chat glyph,
the plugin's default) in Sidepath Teal. This was a design proposal until the live capture
confirmed it is the actual configuration.

The disc's own config label is *"Chat icon — neutral, takes your colours"*, so rendering it
in the merchant's colour demonstrates the theming claim at zero cost in screen time.
Shopware's brand owns the frame, the backdrop and the end card; the merchant's brand owns
the shop and the widget inside it — which is also the accurate picture of who owns what.

### Decision: the panel header reads "Shopping Assistant"

The live shop names its assistant **"Gustav"** via `widgetName`. The video renames it to
"Shopping Assistant".

Rationale: the video is titled Shopping Assistant and runs 80 seconds. A different name in
the panel header is a second thing to explain, and there is no time to explain it. The
white-label capability is real but is not worth the ambiguity here.

## The conversation, verbatim from a live run

Captured 2026-08-31 on the live demo shop. One conversation serves **both** the storefront
beats and the Administration beats, so the video is one continuous story rather than two
unrelated demos.

**Shopper:** *"I only ride trails. Show me every helmet you have."*

**Assistant** (real reply, trimmed for pacing — see below):

> For riding trails, I would recommend the Trail Helmet. It is built specifically with an
> extended rear shell, an adjustable visor, 22 vents, and is rated for trail and gravel use
> across all seasons. As alternatives from the helmet range:
> - Gravel Helmet: Also suited for trail and gravel riding with deeper rear coverage, but
>   without the adjustable visor.
> - Road Helmet Aero: Designed specifically for road riding in summer conditions with an
>   adjustable cradle.

The real reply continues with a *Commuter Helmet* bullet, a *Kids Helmet* bullet and
*"What size or colour would you prefer?"*. The full text is ~450 characters; at a readable
reveal rate that is over 15 seconds of screen time against a 10-second beat. **It is trimmed
to the first sentence plus two alternative bullets (~220 characters), which streams in 3.7s
at 60 chars/sec — a realistic LLM streaming rate.** Trimming the model's real words for
pacing is an edit; writing different words would be a fabrication, and is not done.

### The cards, verbatim

Cards render as a **horizontal carousel** — side by side with a scrollbar beneath, not a
vertical stack. This was wrong in the first draft of this spec and is corrected from the
live capture.

| Product | Spec line | Delivery | Price | Stock | Photo on live shop |
|---|---|---|---|---|---|
| Trail Helmet | All-season · Gravel | Delivery: 1-3 days | €89.00 | In stock (green) | **yes** — `sask-sk-101.png` |
| Gravel Helmet | All-season · Gravel | — | €109.00 | Low stock (orange) | no — placeholder |
| Road Helmet Aero | Summer · Road | — | €129.00 | Low stock (orange) | no — placeholder |
| Commuter Helmet | White · All-season | Delivery: 1-3 days | €64.50 | In stock (green) | **yes** — `sask-sk-102.png` |

Note the delivery line appears only on in-stock items — real behaviour, reproduced.

Two different real stock states visible side by side (`In stock` green, `Low stock` orange)
is stronger evidence for beat 6's grounding claim than a single card could be.

## Shot list

30fps. Frame numbers are indicative; `timeline.ts` holds the authority and gets re-tuned
once the music track exists.

| # | Time | Frames | Beat | On screen |
|---|---|---|---|---|
| 1 | 0:00–0:04 | 0–120 | Cold open | Browser frame fades up on the Sidepath home page. The teal chat disc rises bottom-right; nudge reads *"Ask me anything about this shop"*, then retracts. Shopware lockup settles bottom-left of the backdrop. |
| 2 | 0:04–0:07 | 120–210 | Open the panel | Cursor travels, click plus ripple. Panel expands from the right on `$swag-assistant-ease-out`. Header: teal glyph, "Shopping Assistant", `+` and `×`. Greeting *"Hi. I can look things up in this shop's catalogue."* bottom-anchored, with the three real suggestion chips. |
| 3 | 0:07–0:13 | 210–390 | The question | Teal focus ring on the composer. Character-by-character typing with keystroke SFX: *"I only ride trails. Show me every helmet you have."* Click the icon send button. |
| 4 | 0:13–0:18 | 390–540 | Thinking | User bubble slides in right-aligned. The disc's three quiet dots plus *"Thinking…"* — the plugin's real indicator. |
| 5 | 0:18–0:28 | 540–840 | Grounded answer | Reply streams at 60 chars/sec with auto-scroll. The card carousel animates in: four cards, real photos on two, real prices, `In stock` and `Low stock` pills. |
| 6 | 0:28–0:33 | 840–990 | The claim | Leader lines to €89.00 / `In stock` on the Trail Helmet and to `Low stock` on the Gravel Helmet: *"Rendered from the catalogue. The model only returns a product ID."* |
| 7 | 0:33–0:41 | 990–1230 | Real cart | Cursor clicks `Add to cart` on the Trail Helmet. Button becomes `Added`. Sidepath's own header total ticks €0.00 → €89.00, basket pulses. Caption: *"Their real cart. Your normal checkout."* |
| 8 | 0:41–0:45 | 1230–1350 | Turn | URL pill morphs `/` → `/admin`. Dissolve. Caption: *"And you see every conversation."* |
| 9 | 0:45–0:55 | 1350–1650 | Trace list | Administration: sidebar, smart bar "Assistant conversations", the outcome filter card reading *All 107 conversations* with `Export traces`, and the data grid. Cursor clicks the top row. |
| 10 | 0:55–1:05 | 1650–1950 | Trace detail | `Turn 1 · 14.8 s · shop 263 ms · model 14.5 s`. Phase timeline draws in row by row; the `waiting on the model` row lands last and largest. Caption: *"Including the time you don't control."* |
| 11 | 1:05–1:14 | 1950–2220 | Code beat | Dissolve to a dark editor. `FindCompatiblePartsToolFactory` reveals line by line with its `swag_assistant.grounded_tool_factory` tag. Hard cut back to the panel: *"will these pads fit my 2019 frame?"* now gets a grounded answer. Caption: *"Your own tools. Your own prompt. Your own model."* |
| 12 | 1:14–1:20 | 2220–2400 | End card | Shopware lockup plus "Shopping Assistant". Three lines: *Grounded in your catalogue · Observable in your admin · Extensible in your code.* Footer: *Research preview · Agentic Commerce Lab.* |

Verified: beats are contiguous, no gaps or overlaps, every timecode matches its frame count,
total exactly 2400 frames = 80.0s.

### Why this question

*"I only ride trails. Show me every helmet you have."* is a real question that produced a
real answer on the live shop. It carries a constraint ("I only ride trails") that no keyword
search can act on — the assistant leads with the Trail Helmet *because* of it, and names the
alternatives it rejected and why. That is reasoning over a catalogue, not retrieval from it.

Using the conversation whose trace is also captured means beats 9–10 open the exact
conversation the viewer just watched happen.

### Why beat 10 features the waiting row

`phases.js` states that the most useful thing the trace detail does is **name the gaps**:
nothing is recorded while the model thinks, so its latency exists only as the space between
events. The page renders this as a dedicated `is--wait` row.

The captured turn measures **shop 263 ms · model 14.5 s** — a starker version of the
48ms/8.07s in that file's own comment. It is a number a merchant cannot get anywhere else
and it is honest about where the time goes, which beats any highlight sweep.

### Why beat 11 is a compatibility tool

`VISION.md` names compatibility as the clearest case of a question the plugin must refuse:
Shopware has no native compatibility concept, so if the merchant has not modelled it, the
assistant cannot answer *"does this fit my 2019 model"* without inventing something.

That makes it the ideal thing for the extension beat to fix. A merchant who **has** that
data adds `FindCompatiblePartsToolFactory` — an implementation of
`GroundedToolFactoryInterface` tagged `swag_assistant.grounded_tool_factory` — and the
question becomes answerable. The beat therefore shows a real capability being added, not a
syntax demonstration, and it stays consistent with the plugin's own honesty about limits
rather than contradicting it.

**Reveal method:** the snippet reveals line by line, not character by character. Roughly 10
lines of PHP in under 5 seconds is not typeable at a believable speed; per-line reveal with
keystroke SFX texture reads as writing code. Beat 3's composer typing stays
character-by-character, because a short sentence at human speed is exactly what a shopper does.

## Architecture

### Project shape

```
src/
  Root.tsx              one <Composition id="ShoppingAssistant"> — 2400 frames @ 30fps
  timeline.ts           every beat boundary as a named frame constant
  theme/tokens.ts       ported 1:1 from src/Resources/app/storefront/src/scss/components/_tokens.scss
  theme/sidepath.ts     the demo shop's palette and type stack
  theme/admin.ts        Meteor tokens for the Administration replica
  ui/widget/            Disc, Nudge, Panel, Header, Message, CardCarousel, ProductCard, Composer, Thinking
  ui/shop/              SidepathHeader, Hero, StatBand, AisleChips, CartTotal
  ui/admin/             SwPage, SmartBar, Sidebar, OutcomeFilter, DataGrid, TurnCard, PhaseTimeline, RawTrace
  ui/chrome/            BrowserFrame, Backdrop, Cursor, Caption, Annotation, EndCard, CodePane
  scenes/               one component per beat — 12 files
  data/catalogue.ts     the four real cards, verbatim from the live capture
  data/conversation.ts  the real question and the trimmed real reply
  data/trace.ts         the real trace: timings, phases, stage labels, card ids
  audio/schedule.ts     SFX cue list, derived from the same data that drives the animation
public/
  assets/products/      sk-101 and sk-102 real photos; generated matches for the rest
  assets/audio/         music.wav (supplied) + CC0 SFX + ATTRIBUTION.md
  assets/brand/         Shopware logo SVGs + Sidepath logo SVGs
  assets/fonts/         Inter, Barlow Condensed, IBM Plex Mono
reference/              the 2026-08-31 live captures, for fidelity diffing
out/
```

### The fidelity rule

**Every replicated component is ported from this repository's source and checked against the
live capture, never traced from memory. If a value is not in the source or the capture, it
does not go on screen.**

| Video component | Ported from |
|---|---|
| `theme/tokens.ts` | `scss/components/_tokens.scss` — colours, the four-step type scale, radii 12/8/6/999, the two-layer neutral shadows, the three easing curves |
| `Disc`, `Nudge` | `_orb.scss` (`swag-assistant-disc` mixin, `__glyph` mask), `orb.html.twig` |
| `Panel`, `Header`, `Composer` | `panel.html.twig`, `_panel.scss`, `_composer.scss` + live capture (icon send button, `+` reset, bottom-anchored log) |
| `Message` | `_message.scss`, `render.js`, `markdown.js` (the reply renders as a markdown bullet list) |
| `ProductCard`, `CardCarousel` | `card.js`, `_card.scss` + live capture (horizontal carousel, delivery line only when in stock) |
| `Thinking` | `thinking.js`, `_thinking.scss` |
| All widget copy | `src/Resources/snippet/swag-assistant.en.json` — verbatim |
| `DataGrid` columns | live capture: **Started · Sales channel · User · Turns · Outcome · Question · Reply · Duration**. `Duration` renders as `22.5 s`; `Outcome` as `product_shown`; `User` as `Guest user` |
| `TurnCard`, `PhaseTimeline`, `RawTrace` | `swag-assistant-trace-detail.html.twig`, `phases.js`, `facts.js`, `payload.js` + live capture |
| Sidepath shop chrome | `docs/demo-catalog/brand/` + its README, and the live home-page capture |

### Timing is data

`timeline.ts` exports named frame constants. Every scene is a `<Sequence>` reading from it,
and every animation is expressed relative to its own sequence start. Re-timing the whole cut
to a music track is editing numbers in one file, not re-choreographing scenes.

Beat boundaries are quantised to bars once the track's tempo is known.

### Cursor and typing are data, not animation

One global `<Cursor>` driven by a keyframe list of `{frame, x, y, click?}` with eased
interpolation; it owns the click ripple. The UI state changes caused by a click read the
**same** keyframe entry, so the cursor structurally cannot be somewhere other than the
element that just reacted.

Typing is `useTypewriter(text, startFrame, cps)`. The keystroke SFX schedule is derived from
the same call, so audio and animation align by construction rather than by hand.

### Audio

| Layer | Source |
|---|---|
| Music bed | **Pixabay**, whose Content License permits commercial use and requires no attribution. `<Audio>` across the composition with a tail fade |
| SFX | CC0 / public-domain downloads only, licence verified per file and recorded in `assets/audio/ATTRIBUTION.md`. Any sound without a verifiable CC0 equivalent is synthesised rather than sourced ambiguously |

Cues: keystroke ticks (beats 3, 11), UI clicks (beats 2, 7, 9), a soft whoosh on the beat 8
transition, one discreet confirmation on the cart tick.

#### The track

**Selected: *Minimal Electronica* by Kulakovka**, `https://pixabay.com/music/upbeat-minimal-electronica-274978/`
— 2:35 total, 256kbps, **115 BPM** (bar = 2.0870s).

**Use the window 9.0s → 89.0s of the source.** Five candidates were scored by sliding an
80-second window across each and rating three things: how strong an arrangement change falls
41–45s into the window (beat 8's cut to the Administration), how calm the opening is, and
whether the tail still has presence under the end card. This track scored 7.23 against a
next-best of 4.59, with a cut-strength of 4.08 versus 2.38.

Inside the chosen window the arrangement changes fall at:

| Window position | Novelty | Serves |
|---|---|---|
| 8.0s | 4.68 | beat 2→3 boundary — the panel opening |
| **41.5s** | **4.44** | **beat 8 — the cut to the Administration, 0.5s from the target** |
| 58.0s | 4.17 | an accent inside beat 10 — the `waiting on the model` row can land here |
| 75.0s | 3.96 | just after the end card appears |

Boundaries snap to bars with a maximum shift of 0.96s; 80s is 38.33 bars, and 38 bars =
79.30s. Exact per-beat bar assignments are computed in `timeline.ts`.

**Runner-up, if the selected track is rejected on taste:** *Soft Circuit Flow* by
MindfulLiving, `https://pixabay.com/music/ambient-soft-circuit-flow-modern-ambient-background-music-469975/`
— 3:21, **100.0 BPM** (bar = exactly 2.40s, the cleanest quantisation of the five), window
9.5s → 89.5s. Calmer opening, but a weaker cut (2.38) and a thinner tail (0.56).

**Not usable, and why.** Both YouTube candidates were Infraction/Inaudio. Their "No Copyright
Music" free tier is conditional on an attribution link **in a YouTube description** — a
condition an MP4 played in a sales call, embedded in Linear, or shown at a booth cannot
satisfy. A YouTube rip is also a double-lossy re-encode of the only audio layer in the video.
Of the two, *Have Fun* was additionally disqualified as 76s (shorter than the video), 97 BPM,
lo-fi hip hop, with weak structure in the cut window; *Upbeat* was musically viable (93s,
150 BPM, strongest change at 44.5s) and remains a purchase option via inaudio.org if the
Pixabay track is rejected.

**Brief, as revised by the analysis.** The original asked for 100–115 BPM; testing a 150 BPM
candidate showed tempo matters far less than structure. Requirements are: longer than 80s so
a window can be chosen, instrumental with no vocal chops, sparse low-mids, no risers or
drops, and **one clear arrangement change 41–45s into the chosen window** — the only hard
requirement.

### Product photography

Two of the four cards have real photos on the live shop; two render the placeholder, which
would read as broken in a demo.

**Approach: hybrid.** The real photos are downloaded (`sask-sk-101.png`, `sask-sk-102.png`,
both 1024²). The two gaps are generated to match, using the real photo as the style brief:

> 1024² square. Seamless warm off-white ground, Paper `#f2f0ed`, no horizon line. Subject
> centred at roughly 80% of frame width, three-quarter view facing left. Soft diffuse
> overhead-front light, gentle contact shadow directly beneath and slightly left. No props,
> no text, no logos.

This is not a guess — the Sidepath brand README specifies Paper as the product-photo
background precisely so shots have no visible cut-out edge, and the real photo confirms it.

**Cost, measured via `get_cost` preflight (no credits spent):** `gpt_image_2` at 1k/low =
**0.5 credits**, 1k/medium = **1.5**, 2k/high = **8.5**. Two images at 2k/high is ~17
credits against a balance of 1216. Cost is not a constraint on quality.

## Verification

The live demo shop is reachable and captured (2026-08-31): storefront home, panel open, panel
with the real card carousel, trace list at 1920px, and trace detail. These live in
`reference/` in the video project.

1. **Per-beat stills** — render the centre frame of each of the 12 beats as PNGs and place
   them beside the matching live capture. Fidelity is reviewed here, before any full encode.
2. **Studio pass** — Remotion Studio for motion and timing, which stills cannot show.
3. **Audio pass** — confirm every SFX cue lands on its frame and the beat 8 cut sits on the
   track's transition.
4. **Full render** — only after 1–3.
5. **Re-capture if the shop changes** — the live UI already diverged from this repository's
   older screenshots once (carousel, icon send button, `+` reset). The captures are dated for
   that reason.

## Risks

| Risk | Mitigation |
|---|---|
| The Administration replica is the largest single cost and the easiest to get subtly wrong — Meteor has specific row heights, header weights and border colours, and a daily admin user feels an error before they can name it | The 1920px live captures, plus a dedicated still-comparison round on beats 9 and 10 |
| A beat overruns its frame budget | The shot list is the contract. Time is taken from a named other beat, with the builder informed — never silently |
| The supplied music has no usable arrangement change in 0:38–0:46 | Ask for 2–3 WAV candidates and pick against the cut, rather than cutting to whatever arrives. Analysis tooling for tempo and change-point detection already exists and is proven |
| Generated product photos do not match the real ones | The real photo is the style brief, and only 2 images are needed. Regeneration costs single-digit credits |
| The live shop changes again before the render | Captures are dated; step 5 above re-captures if the replica and the shop disagree |

## Open items

| Item | Owner | Status |
|---|---|---|
| Music track | — | **Done** — *Minimal Electronica* (Kulakovka), Pixabay, 115 BPM, window 9–89s. There is no Epidemic or Artlist seat; Pixabay's licence covers the use |
| Listen to the selected track and confirm on taste | Builder | Awaiting. The analysis proves it fits the cut structurally; whether it *sounds* right for Shopware is a judgement the analysis cannot make |
| Administration reference capture | — | **Done** 2026-08-31 |
| Higgsfield account with sufficient credits | — | **Done** — 1216 credits |
| Per-image cost | — | **Done** — 0.5 to 8.5 credits depending on tier |
| Generate the two missing product photos | Claude | Ready to run on approval |
