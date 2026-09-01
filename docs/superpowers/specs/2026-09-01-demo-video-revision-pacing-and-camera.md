# Demo video revision — pacing, camera, and the code beat

Revision of record for the second pass on the Shopping Assistant demo video, after
the builder watched the first render.

**Supersedes** the timing and motion sections of
`2026-08-31-demo-video-shopping-assistant.md`. Everything else in that spec — the
fidelity rule, the data provenance, the honesty labelling, the token discipline —
still stands and is not reopened.

**Repo:** `~/Workspace/shopping-assistant-demo-video`, currently at `91d744c`,
97/97 tests green, first render shipped at 80.04s.

## The feedback

1. The keyboard sound is unrealistic and annoying.
2. Cursor and other animations are far too slow — speed them up.
3. Zoom effects are missing.
4. The programming scene is too little explained.
5. Cool transitions are missing.
6. **Overall it felt boring to watch** — engaging animation is missing.

Item 6 is the diagnosis; 1–5 are its symptoms. One governing principle for this
pass, in the builder's words: **"slow animations make everything feel boring."**
Every duration below is shorter than its predecessor, and nothing gets slower.

## What the first pass got wrong, and why

At design time the builder was offered a camera that zooms and pans to follow the
action, and chose a fixed browser-on-backdrop frame. That choice was made to read
as *"this is the real product, not marketing"* — and it does, but at 80 seconds a
fixed frame on replicated UI reads as inert. A 480px-wide assistant panel inside a
1600px viewport is also simply small on screen: the product cards the video's whole
argument depends on are rendered at a size a viewer squints at.

So the camera is not decoration here. It is how the video gets the cards big enough
to carry their own claim.

## Runtime and the music

**97.0 seconds — 2910 frames at 30fps.**

The track stays *Minimal Electronica* (Kulakovka, Pixabay Content License), 115 BPM,
bar 2.0870s. **The window moves from 9.0–89.0s to 1.5–98.5s of the source.**

That window was chosen by sliding a 97s frame across the whole 155s track and
scoring three things: arrangement-change strength where the Administration cut
lands, calmness of the opening, and tail presence under the end card. It scored
**7.50** against 5.98–6.39 for 84/88/92s alternatives, whose openings were also
materially worse (calm-open 1.45–2.13 versus 0.59).

Measured arrangement changes inside the chosen window, video-relative:

| Position | Novelty | Anchors |
|---|---|---|
| 15.5s | 4.79 | the answer arriving |
| 49.0s | 4.56 | the cut to the Administration |
| 65.5s | 4.29 | the cut to the code beat |
| 82.5s | 4.01 | the code paying off |

All four are load-bearing beat boundaries below. This is why the runtime is 97 and
not a round 90 or 100: the music has four strong changes and the video has four
structural cuts, and they line up.

## Revised beat sheet — 13 beats

The code beat splits into a reveal and a payoff so that 82.5s change can carry the
moment the added tool answers the question. Every other beat is shorter than before.

| # | Beat | Start | Dur | Was | Anchor |
|---|---|---|---|---|---|
| 1 | `coldOpen` | 0.0 | 3.0 | 4.0 | |
| 2 | `openPanel` | 3.0 | 2.5 | 3.0 | |
| 3 | `question` | 5.5 | 6.0 | 6.0 | |
| 4 | `thinking` | 11.5 | 4.0 | 5.0 | ends on 15.5 |
| 5 | `answer` | 15.5 | 10.5 | 10.0 | **starts on 15.5** |
| 6 | `claim` | 26.0 | 11.0 | 5.0 | |
| 7 | `cart` | 37.0 | 12.0 | 8.0 | ends on 49.0 |
| 8 | `turn` | 49.0 | 3.0 | 4.0 | **starts on 49.0** |
| 9 | `traceList` | 52.0 | 6.5 | 10.0 | |
| 10 | `traceDetail` | 58.5 | 7.0 | 10.0 | ends on 65.5 |
| 11 | `codeReveal` | 65.5 | 17.0 | 9.0 | **starts on 65.5** |
| 12 | `codePayoff` | 82.5 | 7.5 | — | **starts on 82.5** |
| 13 | `endCard` | 90.0 | 7.0 | 6.0 | |

Sums to exactly 97.0s. The Administration shrinks 24s → 16.5s (it was the slowest
stretch); the code beat grows 9s → 24.5s (it was the least explained).

## Camera

A new camera layer applies scale and translation to the whole composition around a
focal point, driven by a keyframe list in the same shape as the existing cursor
path — so the camera is data, reviewable and testable, not scattered transforms.

**Moves are fast.** A push-in completes in **8–14 frames** (0.27–0.47s), not the
one-second creep that would read as the same problem in a new costume. Held frames
carry an almost-imperceptible drift so no frame is ever completely static.

| Beat | Move |
|---|---|
| 1 | frame settles from a slight scale-down; disc rises |
| 2 | quick push toward the panel as it opens |
| 3 | held on the composer, tight |
| 5 | push in on the card carousel as the cards land — this is where the cards must become legible |
| 6 | hard push to the Trail Helmet's price and `In stock`, then a fast lateral move to the Gravel Helmet's `Low stock` |
| 7 | pull out fast to full frame, then push to the basket total as it ticks |
| 8 | pull back through the transition |
| 9 | push in on the clicked grid row |
| 10 | push in on the `waiting on the model` rows |
| 11 | push in on each callout as it fires |
| 12 | held on the answered question |
| 13 | static |

**Constraint:** the camera must never scale past the point where replicated UI goes
soft. Cap the push-in so 1600×900 source content is never magnified beyond roughly
1.6×, and verify legibility on rendered frames rather than by eye in the studio.

## Speed changes

Every one of these gets faster. Existing values are the "was".

| What | Was | Now |
|---|---|---|
| Cursor travel between targets | ~0.6s | ~0.28s, with a slight settle |
| Composer typing | 12 cps | 20 cps |
| Reply streaming | 60 cps | 95 cps |
| Card stagger interval | 0.12 of beat | 0.05 of beat |
| Panel open | 18 frames | 10 frames |
| Caption fade in/out | 8 frames | 5 frames |
| Annotation reveal | 16 frames | 8 frames |
| Phase-timeline row reveal | over ~8s | over ~4.5s |

The cursor keeps its existing eased curve and its hold through the reading beats —
holding still is not slowness, and a drifting pointer was already fixed once.

## Transitions

Currently one cross-dissolve. Add variety, but **only three kinds** — more reads as
a template:

- **Cross-dissolve** (existing, 24 frames → **12 frames**) for beat 8's storefront →
  Administration cut, on the 49.0s change.
- **Scale-through**: the outgoing beat pushes in and blurs out as the incoming beat
  scales down into place. Use for beat 10 → 11 (Administration → code) on 65.5s, and
  beat 11 → 12 (code → the answered panel) on 82.5s.
- **Fast wipe** for beat 12 → 13 into the end card.

Every transition sits inside its own beat's frame range and must not shorten the
timeline — the beat boundaries are fixed by the music.

## The code beat, rebuilt

Beat 11 (17s) reveals the snippet, then annotates it with callout labels; beat 12
(7.5s) is the payoff.

Callouts, each with a fast push-in and a short label:

1. on `implements GroundedToolFactoryInterface` — *"one interface"*
2. on the `handler` line — *"your own data source"*
3. on the DI tag, revealed last — *"one tag and it's live"*

Then beat 12 cuts to the panel where *"will these pads fit my 2019 frame?"* is
answered, captioned *"Your own tools. Your own prompt. Your own model."*

Callout labels use `videoType.annotation` and the same light plate treatment as
`Caption` and `Annotation`, so the video's on-screen text stays one system.

**Kept from the first pass:** the tool is a compatibility tool because `VISION.md`
names compatibility as the question the assistant *must refuse* unless the merchant
modelled it. The beat shows a real capability being added, not a syntax demo.

## Sound effects

**The keystroke sound is the worst thing in the audio and gets rebuilt.** The
current cue is a single 45ms Kenney tick fired at a fixed interval — mechanically
regular, identical every time, and too loud, which is exactly why it reads as
unrealistic and annoying.

Requirements:
- **Irregular timing.** Human typing is not periodic. Jitter each cue's frame.
- **Varied gain.** No two keystrokes at the same level.
- **Quieter.** It sits under a music bed; it should be texture, not percussion.
- **Fewer.** Not every character needs a tick.

If a CC0 sample cannot be made to sound plausible under those constraints, synthesise
one (the project already has `scripts/synth-whoosh.py` as precedent) and record it in
`ATTRIBUTION.md`. Determinism matters: no `Math.random()` at render time — derive the
jitter from the cue index so every render is identical.

The code beat's per-line cues follow the same rules. The builder has not heard those
yet; they may need removing entirely, which stays a one-line revert.

## What is NOT reopened

The fidelity rule; all captured data and its provenance labelling; the token system
and the SCSS-parsing gate; the four-size widget scale versus the separate video
scale; the beat 9 / beat 10 conversation data; the Administration replica's
measurements; the licence position on music, SFX and imagery.

## Verification

1. Per-beat stills for all 13 beats, compared against `reference/` where a beat
   replicates real UI.
2. **Legibility check on the push-ins**: rendered frames at maximum zoom, confirming
   replicated UI has not gone soft.
3. Studio pass for motion and transition timing.
4. Audio pass for cue placement — noting that no one in this pipeline can judge how
   the keystrokes *sound*; that is the builder's call on the render.
5. Full render, `ffprobe`-verified at ≈97.0s / 1920×1080 / 30fps.
