# Shopping Assistant Demo Video Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an 80-second, 1920×1080 @30fps Remotion video that reproduces the live Shopping Assistant demo — shopper conversation, grounded product cards, real cart, Administration trace, and a code extension beat — using React components ported from the plugin's own source.

**Architecture:** One Remotion composition of exactly 2400 frames. All beat boundaries live in a single `timeline.ts` so re-timing to music is one file edit. Design tokens are ported verbatim from the plugin's SCSS and held honest by a test that parses the real SCSS file. A global cursor and a typewriter hook are driven by data, and the SFX schedule is derived from that same data so audio and animation cannot drift apart.

**Tech Stack:** Remotion 4 (`remotion`, `@remotion/media`, `@remotion/transitions`, `@remotion/google-fonts`), React 19, TypeScript, Vitest + `@testing-library/react`, npm.

**Spec:** `docs/superpowers/specs/2026-08-31-demo-video-shopping-assistant.md`

## Global Constraints

- **Deliverable repo:** `~/Workspace/shopping-assistant-demo-video`. Never add Node tooling to `~/Workspace/shopping-assistant-starter-kit`.
- **Composition:** exactly `2400` frames, `30` fps, `1920` wide × `1080` high.
- **The fidelity rule:** every replicated component is ported from the plugin's source or the dated live capture. If a value is not in the source or the capture, it does not go on screen.
- **Plugin source path:** `PLUGIN_ROOT` defaults to `../shopping-assistant-starter-kit`.
- **Widget tokens (from `_tokens.scss`), verbatim:** surface `#ffffff`, text `#00153e`, meta `#596782` (= `mix(#00153e,#fff,65%)`, resolved — 5.70:1 on white, matching the figure the SCSS comment claims), meta-quiet `#a3a6b5`, accent `#0870ff`, accent-light `#4a8fff`, accent-dark `#005cd7`, brand `#189eff`, bubble `#f0f6ff`, tint `#f7faff`, hairline `#e6e9f0`, in-stock `#57d998`, low-stock `#f88138`, out-of-stock `#838489`.
- **Widget type scale — only these four sizes may appear:** `11px` micro, `12px` meta, `16px` body, `20px` heading.
- **Widget radii:** panel `12px`, card `8px`, control `6px`, pill `999px`.
- **Widget easing:** ease `cubic-bezier(0.2,0.9,0.25,1)`, ease-out `cubic-bezier(0.16,1,0.3,1)`, spring `cubic-bezier(0.34,1.56,0.64,1)`. The spring curve is scoped to the creature only and **must not appear in this video**, which uses the neutral icon entry point.
- **Widget elevation:** two-layer neutral shadows only. `lift-2 = 0 2px 6px rgba(0,21,62,.08), 0 12px 28px rgba(0,21,62,.12)`; `lift-3 = 0 4px 12px rgba(0,21,62,.10), 0 24px 64px rgba(0,21,62,.18)`. A surface takes elevation **or** a border, never both.
- **Sidepath palette:** Paper `#f2f0ed`, Ink `#17191b`, Teal `#0e8c88`, Rust `#c4491f`, Hairline `#d5d0c8`.
- **The widget renders in Sidepath Teal `#0e8c88`**, not the shipped accent — this is the live shop's actual configuration.
- **Panel header copy:** `Shopping Assistant`. The live shop says "Gustav"; the video deliberately renames it.
- **All widget copy comes verbatim from** `PLUGIN_ROOT/src/Resources/snippet/swag-assistant.en.json`.
- **Music:** *Minimal Electronica* (Kulakovka, Pixabay), **115 BPM**, bar `2.0870s`, source window **9.0s → 89.0s**.
- **SFX:** CC0 / public-domain only, one `ATTRIBUTION.md` entry per file. No ambiguous sources.
- **Always pass `premountFor` to every `<Sequence>`.**
- **`<Audio>` imports from `@remotion/media`, never from `remotion`.**
- **Use `<Img>` from `remotion`, never a bare `<img>`** — rendering must block on image load.

---

## File Structure

| File | Responsibility |
|---|---|
| `src/Root.tsx` | The single `<Composition>` registration |
| `src/Video.tsx` | Composes the 12 scene `<Sequence>`s plus the global cursor and audio |
| `src/timeline.ts` | Every beat boundary in frames; the single source of timing truth |
| `src/theme/tokens.ts` | Widget tokens ported from `_tokens.scss` |
| `src/theme/sidepath.ts` | Sidepath palette and type stack |
| `src/theme/admin.ts` | Meteor/Administration tokens from the live capture |
| `src/lib/typewriter.ts` | `useTypewriter` and its pure `typedLength` helper |
| `src/lib/cursor.ts` | Cursor keyframe type and `cursorAt(frame)` interpolation |
| `src/lib/sfx.ts` | Derives the SFX cue list from the cursor and typewriter data |
| `src/lib/phases.ts` | Groups trace events into phases and wait rows |
| `src/data/catalogue.ts` | The four real product cards |
| `src/data/conversation.ts` | The real question and trimmed real reply |
| `src/data/trace.ts` | The real trace: timings, phases, card ids |
| `src/ui/chrome/*` | `Backdrop`, `BrowserFrame`, `Cursor`, `Caption`, `Annotation`, `EndCard`, `CodePane` |
| `src/ui/shop/*` | `SidepathHeader`, `Hero`, `StatBand`, `AisleChips`, `CartTotal` |
| `src/ui/widget/*` | `Disc`, `Nudge`, `Panel`, `PanelHeader`, `Message`, `ProductCard`, `CardCarousel`, `Composer`, `Thinking` |
| `src/ui/admin/*` | `AdminShell`, `Sidebar`, `SmartBar`, `OutcomeFilter`, `DataGrid`, `TurnCard`, `PhaseTimeline` |
| `src/scenes/Scene01..Scene12.tsx` | One file per beat |
| `reference/` | The dated 2026-08-31 live captures |
| `scripts/stills.mjs` | Renders the 12 beat-centre stills for fidelity review |

---

## Task 1: Project scaffold, tokens, and the test that keeps them honest

**Files:**
- Create: `~/Workspace/shopping-assistant-demo-video/` (whole project)
- Create: `src/theme/tokens.ts`, `src/theme/sidepath.ts`
- Test: `src/theme/tokens.test.ts`

**Interfaces:**
- Consumes: nothing
- Produces: `tokens` object with keys `surface, text, meta, metaQuiet, accent, accentLight, accentDark, brand, bubble, tint, hairline, inStock, lowStock, outOfStock, font{micro,meta,body,heading}, radius{panel,card,control,pill}, ease{standard,out}, lift{1,2,3}, orbSize, orbInset, panelWidth`; `sidepath` object with keys `paper, ink, teal, rust, hairline`.

- [ ] **Step 1: Scaffold the project**

```bash
cd ~/Workspace
npx create-video@latest shopping-assistant-demo-video --blank
cd shopping-assistant-demo-video
npm i
npx remotion add @remotion/media
npx remotion add @remotion/transitions
npx remotion add @remotion/google-fonts
npm i -D vitest @testing-library/react @testing-library/dom jsdom @vitejs/plugin-react
git init && git add -A && git commit -m "chore: scaffold remotion project"
```

- [ ] **Step 2: Add the vitest config**

Create `vitest.config.ts`:

```ts
import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  test: { environment: "jsdom", globals: true },
});
```

Add to `package.json` scripts: `"test": "vitest run"`.

- [ ] **Step 3: Write the failing token-fidelity test**

This test parses the plugin's real SCSS and asserts our TypeScript copy matches. It is the mechanical enforcement of the fidelity rule.

Create `src/theme/tokens.test.ts`:

```ts
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { tokens } from "./tokens";

const PLUGIN_ROOT = process.env.PLUGIN_ROOT ?? "../shopping-assistant-starter-kit";
const SCSS = readFileSync(
  join(PLUGIN_ROOT, "src/Resources/app/storefront/src/scss/components/_tokens.scss"),
  "utf8",
);

const scssValue = (name: string): string => {
  const m = SCSS.match(new RegExp(`^\\$${name}:\\s*([^;]+);`, "m"));
  if (!m) throw new Error(`token $${name} not found in _tokens.scss`);
  return m[1].trim();
};

describe("tokens are ported from the plugin's SCSS, not invented", () => {
  it.each([
    ["swag-assistant-surface", "surface"],
    ["swag-assistant-text", "text"],
    ["swag-assistant-meta-quiet", "metaQuiet"],
    ["swag-assistant-accent", "accent"],
    ["swag-assistant-accent-light", "accentLight"],
    ["swag-assistant-accent-dark", "accentDark"],
    ["swag-assistant-brand", "brand"],
    ["swag-assistant-bubble", "bubble"],
    ["swag-assistant-tint", "tint"],
    ["swag-assistant-hairline", "hairline"],
    ["swag-assistant-in-stock", "inStock"],
    ["swag-assistant-low-stock", "lowStock"],
    ["swag-assistant-out-of-stock", "outOfStock"],
  ] as const)("$%s matches tokens.%s", (scssName, tsKey) => {
    const expected = scssValue(scssName).toLowerCase();
    expect((tokens as Record<string, string>)[tsKey].toLowerCase()).toBe(expected);
  });

  it("uses only the four sanctioned font sizes", () => {
    expect(Object.values(tokens.font)).toEqual(["11px", "12px", "16px", "20px"]);
  });

  it("carries the SCSS easing curves verbatim", () => {
    expect(tokens.ease.standard).toBe(scssValue("swag-assistant-ease"));
    expect(tokens.ease.out).toBe(scssValue("swag-assistant-ease-out"));
  });

  it("never exposes the creature's spring curve — this video uses the neutral icon", () => {
    expect(JSON.stringify(tokens)).not.toContain("1.56");
  });

  it("resolves the computed meta colour, and keeps it above the 4.5:1 floor", () => {
    // $swag-assistant-meta: mix(#00153e, #ffffff, 65%)
    expect(SCSS).toContain("mix(#00153e, #ffffff, 65%)");
    expect(tokens.meta.toLowerCase()).toBe("#596782");

    const lin = (c: number) => {
      const s = c / 255;
      return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };
    const [r, g, b] = [89, 103, 130];
    const L = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
    expect(1.05 / (L + 0.05)).toBeCloseTo(5.7, 1);
  });
});
```

- [ ] **Step 4: Run it to confirm it fails**

Run: `npm test -- src/theme/tokens.test.ts`
Expected: FAIL — `Cannot find module './tokens'`.

- [ ] **Step 5: Write the token modules**

Create `src/theme/tokens.ts`:

```ts
// Ported verbatim from PLUGIN_ROOT/src/Resources/app/storefront/src/scss/components/_tokens.scss.
// tokens.test.ts parses that file and fails if these drift.
export const tokens = {
  surface: "#ffffff",
  text: "#00153e",
  // mix(#00153e, #ffffff, 65%) resolved: 65*night + 35*white per channel.
  // Verified: 5.70:1 on white, the exact figure _tokens.scss claims for it.
  meta: "#596782",
  metaQuiet: "#a3a6b5",
  accent: "#0870ff",
  accentLight: "#4a8fff",
  accentDark: "#005cd7",
  brand: "#189eff",
  bubble: "#f0f6ff",
  tint: "#f7faff",
  hairline: "#e6e9f0",
  inStock: "#57d998",
  lowStock: "#f88138",
  outOfStock: "#838489",
  font: { micro: "11px", meta: "12px", body: "16px", heading: "20px" },
  radius: { panel: "12px", card: "8px", control: "6px", pill: "999px" },
  ease: {
    standard: "cubic-bezier(0.2, 0.9, 0.25, 1)",
    out: "cubic-bezier(0.16, 1, 0.3, 1)",
  },
  lift: {
    1: "0 1px 2px rgba(0, 21, 62, 0.06), 0 4px 12px rgba(0, 21, 62, 0.06)",
    2: "0 2px 6px rgba(0, 21, 62, 0.08), 0 12px 28px rgba(0, 21, 62, 0.12)",
    3: "0 4px 12px rgba(0, 21, 62, 0.10), 0 24px 64px rgba(0, 21, 62, 0.18)",
  },
  orbSize: 60,
  orbInset: 20,
  panelWidth: 480,
} as const;
```

Create `src/theme/sidepath.ts`:

```ts
// From PLUGIN_ROOT/docs/demo-catalog/brand/README.md and the 2026-08-31 live capture.
export const sidepath = {
  paper: "#f2f0ed",
  ink: "#17191b",
  teal: "#0e8c88",
  rust: "#c4491f",
  hairline: "#d5d0c8",
} as const;

export const sidepathType = {
  display: "'Barlow Condensed', system-ui, sans-serif",
  meta: "'IBM Plex Mono', ui-monospace, monospace",
  body: "Inter, system-ui, sans-serif",
} as const;
```

- [ ] **Step 6: Run the test to confirm it passes**

Run: `npm test -- src/theme/tokens.test.ts`
Expected: PASS, 17 assertions.

- [ ] **Step 7: Copy the reference captures in**

```bash
mkdir -p reference
cp /private/tmp/claude-502/*/*/scratchpad/ref/*.png reference/ 2>/dev/null || true
ls reference/
```

Expected: `storefront-home.png`, `storefront-panel-open.png`, `storefront-panel-cards.png`, `admin-trace-list-1920.png`, `admin-trace-detail-1920.png`.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(theme): port widget and Sidepath tokens, enforced against the plugin's SCSS"
```

---

## Task 2: The timeline, and the composition that runs on it

**Files:**
- Create: `src/timeline.ts`, `src/Video.tsx`
- Modify: `src/Root.tsx`
- Test: `src/timeline.test.ts`

**Interfaces:**
- Consumes: nothing
- Produces: `FPS = 30`, `TOTAL_FRAMES = 2400`, `BPM = 115`, `BAR_SECONDS`, `BEATS: readonly Beat[]` where `Beat = { id: number; name: string; from: number; durationInFrames: number }`, and `beat(id: number): Beat`.

- [ ] **Step 1: Write the failing timeline test**

Create `src/timeline.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { BAR_SECONDS, BEATS, FPS, TOTAL_FRAMES, beat } from "./timeline";

describe("timeline", () => {
  it("is exactly 80 seconds at 30fps", () => {
    expect(FPS).toBe(30);
    expect(TOTAL_FRAMES).toBe(2400);
  });

  it("has twelve beats", () => {
    expect(BEATS).toHaveLength(12);
  });

  it("is contiguous with no gaps or overlaps", () => {
    let cursor = 0;
    for (const b of BEATS) {
      expect(b.from).toBe(cursor);
      expect(b.durationInFrames).toBeGreaterThan(0);
      cursor = b.from + b.durationInFrames;
    }
    expect(cursor).toBe(TOTAL_FRAMES);
  });

  it("derives one bar from 115 BPM", () => {
    expect(BAR_SECONDS).toBeCloseTo(2.087, 3);
  });

  it("snaps every boundary to within one second of a bar line", () => {
    for (const b of BEATS) {
      const seconds = b.from / FPS;
      const bars = seconds / BAR_SECONDS;
      const drift = Math.abs(bars - Math.round(bars)) * BAR_SECONDS;
      expect(drift).toBeLessThanOrEqual(1.0);
    }
  });

  it("puts the admin cut on the track's 41.5s arrangement change", () => {
    const admin = beat(8);
    expect(admin.name).toBe("turn");
    expect(admin.from / FPS).toBeGreaterThanOrEqual(40.5);
    expect(admin.from / FPS).toBeLessThanOrEqual(42.5);
  });

  it("looks up beats by id", () => {
    expect(beat(1).name).toBe("coldOpen");
    expect(beat(12).name).toBe("endCard");
    expect(() => beat(13)).toThrow();
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npm test -- src/timeline.test.ts`
Expected: FAIL — `Cannot find module './timeline'`.

- [ ] **Step 3: Write the timeline**

Create `src/timeline.ts`:

```ts
export const FPS = 30;
export const TOTAL_FRAMES = 2400; // 80.0s

// Minimal Electronica (Kulakovka, Pixabay), source window 9.0s -> 89.0s.
export const BPM = 115;
export const BAR_SECONDS = (4 * 60) / BPM; // 2.08695...
export const MUSIC_TRIM_BEFORE_SECONDS = 9.0;

export type Beat = {
  id: number;
  name: string;
  from: number;
  durationInFrames: number;
};

const s = (seconds: number) => Math.round(seconds * FPS);

// Boundaries in seconds, from the spec's shot list. Each is within 1s of a bar
// line at 115 BPM; the arrangement changes at 8.0s, 41.5s, 58.0s and 75.0s of the
// chosen window are what these are aligned to.
const BOUNDARIES = [0, 4, 7, 13, 18, 28, 33, 41, 45, 55, 65, 74, 80] as const;

const NAMES = [
  "coldOpen",
  "openPanel",
  "question",
  "thinking",
  "answer",
  "claim",
  "cart",
  "turn",
  "traceList",
  "traceDetail",
  "codeBeat",
  "endCard",
] as const;

export const BEATS: readonly Beat[] = NAMES.map((name, i) => ({
  id: i + 1,
  name,
  from: s(BOUNDARIES[i]),
  durationInFrames: s(BOUNDARIES[i + 1]) - s(BOUNDARIES[i]),
}));

export const beat = (id: number): Beat => {
  const found = BEATS.find((b) => b.id === id);
  if (!found) throw new Error(`no beat with id ${id}`);
  return found;
};
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `npm test -- src/timeline.test.ts`
Expected: PASS, 7 tests.

- [ ] **Step 5: Wire the composition**

Replace `src/Root.tsx`:

```tsx
import { Composition } from "remotion";
import { FPS, TOTAL_FRAMES } from "./timeline";
import { Video } from "./Video";

export const RemotionRoot: React.FC = () => (
  <Composition
    id="ShoppingAssistant"
    component={Video}
    durationInFrames={TOTAL_FRAMES}
    fps={FPS}
    width={1920}
    height={1080}
  />
);
```

Create `src/Video.tsx` — scenes are added by later tasks; the placeholder proves the composition renders:

```tsx
import { AbsoluteFill } from "remotion";
import { sidepath } from "./theme/sidepath";

export const Video: React.FC = () => (
  <AbsoluteFill style={{ backgroundColor: sidepath.paper }} />
);
```

- [ ] **Step 6: Verify the studio opens and reports the right length**

Run: `npx remotion studio`
Expected: one composition `ShoppingAssistant`, 1920×1080, 2400 frames, 30fps. Close it.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(timeline): 12 contiguous beats over 2400 frames, bar-aligned to 115 BPM"
```

---

## Task 3: Cursor, typewriter and the SFX schedule derived from both

**Files:**
- Create: `src/lib/cursor.ts`, `src/lib/typewriter.ts`, `src/lib/sfx.ts`
- Test: `src/lib/cursor.test.ts`, `src/lib/typewriter.test.ts`, `src/lib/sfx.test.ts`

**Interfaces:**
- Consumes: `FPS` from `../timeline`
- Produces:
  - `type CursorKey = { frame: number; x: number; y: number; click?: boolean }`
  - `cursorAt(keys: CursorKey[], frame: number): { x: number; y: number; clickAge: number | null }`
  - `typedLength(text: string, frame: number, startFrame: number, cps: number): number`
  - `useTypewriter(text: string, startFrame: number, cps: number): string`
  - `type SfxCue = { frame: number; sound: "key" | "click" | "whoosh" | "cart" }`
  - `sfxFromCursor(keys: CursorKey[]): SfxCue[]`
  - `sfxFromTyping(text: string, startFrame: number, cps: number, everyNth?: number): SfxCue[]`

- [ ] **Step 1: Write the failing cursor test**

Create `src/lib/cursor.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { cursorAt, type CursorKey } from "./cursor";

const keys: CursorKey[] = [
  { frame: 0, x: 100, y: 100 },
  { frame: 30, x: 200, y: 300, click: true },
  { frame: 60, x: 200, y: 300 },
];

describe("cursorAt", () => {
  it("holds the first position before the first keyframe", () => {
    expect(cursorAt(keys, -5)).toMatchObject({ x: 100, y: 100 });
  });

  it("holds the last position after the last keyframe", () => {
    expect(cursorAt(keys, 999)).toMatchObject({ x: 200, y: 300 });
  });

  it("interpolates between keyframes", () => {
    const mid = cursorAt(keys, 15);
    expect(mid.x).toBeGreaterThan(100);
    expect(mid.x).toBeLessThan(200);
  });

  it("reports how many frames ago the click happened, for the ripple", () => {
    expect(cursorAt(keys, 29).clickAge).toBeNull();
    expect(cursorAt(keys, 30).clickAge).toBe(0);
    expect(cursorAt(keys, 38).clickAge).toBe(8);
  });

  it("forgets a click once the ripple has finished", () => {
    expect(cursorAt(keys, 200).clickAge).toBeNull();
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npm test -- src/lib/cursor.test.ts`
Expected: FAIL — `Cannot find module './cursor'`.

- [ ] **Step 3: Implement the cursor**

Create `src/lib/cursor.ts`:

```ts
import { interpolate } from "remotion";

export type CursorKey = { frame: number; x: number; y: number; click?: boolean };

export const RIPPLE_FRAMES = 18;

export const cursorAt = (
  keys: CursorKey[],
  frame: number,
): { x: number; y: number; clickAge: number | null } => {
  const sorted = [...keys].sort((a, b) => a.frame - b.frame);
  const frames = sorted.map((k) => k.frame);

  const at = (axis: "x" | "y") =>
    sorted.length === 1
      ? sorted[0][axis]
      : interpolate(frame, frames, sorted.map((k) => k[axis]), {
          extrapolateLeft: "clamp",
          extrapolateRight: "clamp",
        });

  const lastClick = sorted
    .filter((k) => k.click && k.frame <= frame)
    .map((k) => k.frame)
    .pop();

  const age = lastClick === undefined ? null : frame - lastClick;

  return {
    x: at("x"),
    y: at("y"),
    clickAge: age !== null && age < RIPPLE_FRAMES ? age : null,
  };
};
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `npm test -- src/lib/cursor.test.ts`
Expected: PASS, 5 tests.

- [ ] **Step 5: Write the failing typewriter test**

Create `src/lib/typewriter.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { typedLength } from "./typewriter";

describe("typedLength", () => {
  it("types nothing before the start frame", () => {
    expect(typedLength("hello", 10, 20, 12)).toBe(0);
  });

  it("types at the given characters per second", () => {
    // 12 cps at 30fps = 0.4 chars per frame; 30 frames in = 12 chars.
    expect(typedLength("a".repeat(50), 50, 20, 12)).toBe(12);
  });

  it("never exceeds the text length", () => {
    expect(typedLength("hi", 9999, 0, 12)).toBe(2);
  });

  it("is monotonic", () => {
    const text = "a".repeat(40);
    let prev = 0;
    for (let f = 0; f < 200; f++) {
      const n = typedLength(text, f, 0, 12);
      expect(n).toBeGreaterThanOrEqual(prev);
      prev = n;
    }
  });
});
```

- [ ] **Step 6: Run it to confirm it fails**

Run: `npm test -- src/lib/typewriter.test.ts`
Expected: FAIL — `Cannot find module './typewriter'`.

- [ ] **Step 7: Implement the typewriter**

Create `src/lib/typewriter.ts`:

```ts
import { useCurrentFrame } from "remotion";
import { FPS } from "../timeline";

export const typedLength = (
  text: string,
  frame: number,
  startFrame: number,
  cps: number,
): number => {
  const elapsed = frame - startFrame;
  if (elapsed <= 0) return 0;
  return Math.min(text.length, Math.floor((elapsed / FPS) * cps));
};

export const useTypewriter = (
  text: string,
  startFrame: number,
  cps: number,
): string => text.slice(0, typedLength(text, useCurrentFrame(), startFrame, cps));
```

- [ ] **Step 8: Run the test to confirm it passes**

Run: `npm test -- src/lib/typewriter.test.ts`
Expected: PASS, 4 tests.

- [ ] **Step 9: Write the failing SFX test**

The point of this module: the cue list is *derived* from the same data that drives the animation, so a keystroke sound cannot land where no character was typed.

Create `src/lib/sfx.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import type { CursorKey } from "./cursor";
import { sfxFromCursor, sfxFromTyping } from "./sfx";

describe("sfxFromCursor", () => {
  it("emits one click cue per clicking keyframe and nothing else", () => {
    const keys: CursorKey[] = [
      { frame: 0, x: 0, y: 0 },
      { frame: 20, x: 1, y: 1, click: true },
      { frame: 40, x: 2, y: 2 },
      { frame: 60, x: 3, y: 3, click: true },
    ];
    expect(sfxFromCursor(keys)).toEqual([
      { frame: 20, sound: "click" },
      { frame: 60, sound: "click" },
    ]);
  });
});

describe("sfxFromTyping", () => {
  it("emits a keystroke cue only on frames where a character actually appears", () => {
    const cues = sfxFromTyping("abcd", 0, 12, 1);
    expect(cues).toHaveLength(4);
    expect(cues.every((c) => c.sound === "key")).toBe(true);
    // Strictly increasing frames — no two characters on the same frame.
    const frames = cues.map((c) => c.frame);
    expect([...new Set(frames)]).toHaveLength(4);
    expect([...frames].sort((a, b) => a - b)).toEqual(frames);
  });

  it("thins dense typing so 50 characters do not become 50 clicks", () => {
    const all = sfxFromTyping("a".repeat(50), 0, 12, 1);
    const thinned = sfxFromTyping("a".repeat(50), 0, 12, 3);
    expect(all).toHaveLength(50);
    expect(thinned.length).toBeLessThan(all.length);
    expect(thinned.length).toBeGreaterThan(10);
  });

  it("offsets every cue by the start frame", () => {
    const cues = sfxFromTyping("ab", 100, 12, 1);
    expect(cues[0].frame).toBeGreaterThanOrEqual(100);
  });
});
```

- [ ] **Step 10: Run it to confirm it fails**

Run: `npm test -- src/lib/sfx.test.ts`
Expected: FAIL — `Cannot find module './sfx'`.

- [ ] **Step 11: Implement the SFX derivation**

Create `src/lib/sfx.ts`:

```ts
import type { CursorKey } from "./cursor";
import { typedLength } from "./typewriter";

export type SfxSound = "key" | "click" | "whoosh" | "cart";
export type SfxCue = { frame: number; sound: SfxSound };

export const sfxFromCursor = (keys: CursorKey[]): SfxCue[] =>
  [...keys]
    .sort((a, b) => a.frame - b.frame)
    .filter((k) => k.click)
    .map((k) => ({ frame: k.frame, sound: "click" as const }));

/**
 * Walks the same `typedLength` the animation uses and emits a cue on each frame
 * where the character count increased. `everyNth` thins the result: at 12 cps a
 * tick on every character is a machine-gun, not a keyboard.
 */
export const sfxFromTyping = (
  text: string,
  startFrame: number,
  cps: number,
  everyNth = 3,
): SfxCue[] => {
  const cues: SfxCue[] = [];
  let previous = 0;
  let emitted = 0;
  const lastFrame = startFrame + Math.ceil((text.length / cps) * 30) + 2;

  for (let frame = startFrame; frame <= lastFrame; frame++) {
    const n = typedLength(text, frame, startFrame, cps);
    if (n > previous) {
      if (emitted % everyNth === 0) cues.push({ frame, sound: "key" });
      emitted += 1;
      previous = n;
    }
  }
  return cues;
};
```

- [ ] **Step 12: Run the whole suite**

Run: `npm test`
Expected: PASS — tokens, timeline, cursor, typewriter, sfx.

- [ ] **Step 13: Commit**

```bash
git add -A
git commit -m "feat(lib): data-driven cursor, typewriter, and an SFX schedule derived from both"
```

---

## Task 4: The real data — catalogue, conversation, trace

**Files:**
- Create: `src/data/catalogue.ts`, `src/data/conversation.ts`, `src/data/trace.ts`
- Test: `src/data/catalogue.test.ts`, `src/data/trace.test.ts`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `type Stock = "in" | "low" | "out"`
  - `type Card = { id: string; name: string; spec: string; variant?: string; delivery?: string; price: string; stock: Stock; image?: string }`
  - `CARDS: readonly Card[]` — the four real helmet cards
  - `QUESTION: string`, `REPLY_PARAGRAPH: string`, `REPLY_BULLETS: readonly string[]`, `GREETING: string`, `SUGGESTIONS: readonly string[]`
  - `type TraceRow = { at: string; kind: "phase" | "wait"; label: string; fact?: string; factValue?: string; took: string }`
  - `TURN: { index: number; total: string; shop: string; model: string; shopper: string; assistant: string; cardIds: readonly string[]; rows: readonly TraceRow[] }`
  - `LIST_ROWS: readonly { started: string; salesChannel: string; user: string; turns: number; outcome: string; question: string; reply: string; duration: string }[]`
  - `LIST_COLUMNS: readonly string[]`

- [ ] **Step 1: Write the failing catalogue test**

Create `src/data/catalogue.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { CARDS } from "./catalogue";

describe("the four cards are the live capture, verbatim", () => {
  it("has four cards in carousel order", () => {
    expect(CARDS.map((c) => c.name)).toEqual([
      "Trail Helmet",
      "Gravel Helmet",
      "Road Helmet Aero",
      "Commuter Helmet",
    ]);
  });

  it("carries the real prices", () => {
    expect(CARDS.map((c) => c.price)).toEqual([
      "€89.00",
      "€109.00",
      "€129.00",
      "€64.50",
    ]);
  });

  it("carries the real stock states", () => {
    expect(CARDS.map((c) => c.stock)).toEqual(["in", "low", "low", "in"]);
  });

  it("shows a delivery line only on in-stock items, as the live widget does", () => {
    for (const c of CARDS) {
      if (c.stock === "in") expect(c.delivery).toBe("Delivery: 1-3 days");
      else expect(c.delivery).toBeUndefined();
    }
  });

  it("gives every card an image, so no placeholder reaches the render", () => {
    for (const c of CARDS) expect(c.image).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npm test -- src/data/catalogue.test.ts`
Expected: FAIL — `Cannot find module './catalogue'`.

- [ ] **Step 3: Write the catalogue**

Create `src/data/catalogue.ts`:

```ts
// Captured from the live demo shop on 2026-08-31 by reading the rendered
// .swag-assistant-card nodes. Prices, spec lines, delivery lines and stock
// states are the shop's own output, not written here.
export type Stock = "in" | "low" | "out";

export type Card = {
  id: string;
  name: string;
  spec: string;
  variant?: string;
  delivery?: string;
  price: string;
  stock: Stock;
  image?: string;
};

export const CARDS: readonly Card[] = [
  {
    id: "sk-101",
    name: "Trail Helmet",
    variant: "Black · M",
    spec: "All-season · Gravel",
    delivery: "Delivery: 1-3 days",
    price: "€89.00",
    stock: "in",
    image: "products/sk-101-trail-helmet.png",
  },
  {
    id: "sk-106",
    name: "Gravel Helmet",
    variant: "Olive · M",
    spec: "All-season · Gravel",
    price: "€109.00",
    stock: "low",
    image: "products/sk-106-gravel-helmet.png",
  },
  {
    id: "sk-109",
    name: "Road Helmet Aero",
    variant: "White · S",
    spec: "Summer · Road",
    price: "€129.00",
    stock: "low",
    image: "products/sk-109-road-helmet-aero.png",
  },
  {
    id: "sk-102",
    name: "Commuter Helmet",
    variant: "White · All-season",
    spec: "White · All-season",
    delivery: "Delivery: 1-3 days",
    price: "€64.50",
    stock: "in",
    image: "products/sk-102-commuter-helmet.png",
  },
] as const;

export const CART_TOTAL_BEFORE = "€0.00";
export const CART_TOTAL_AFTER = "€89.00"; // Trail Helmet added
```

Create `src/data/conversation.ts`:

```ts
// The question and reply are from one real run on the live demo shop, 2026-08-31.
// The reply is TRIMMED for pacing (the full text is ~450 characters, which is over
// 15s of readable screen time against a 10s beat). Trimming the model's own words
// is an edit; writing different words would be a fabrication.
export const QUESTION = "I only ride trails. Show me every helmet you have.";

export const REPLY_PARAGRAPH =
  "For riding trails, I would recommend the Trail Helmet. It is built specifically " +
  "with an extended rear shell, an adjustable visor, 22 vents, and is rated for " +
  "trail and gravel use across all seasons. As alternatives from the helmet range:";

export const REPLY_BULLETS = [
  "Gravel Helmet: Also suited for trail and gravel riding with deeper rear coverage, but without the adjustable visor.",
  "Road Helmet Aero: Designed specifically for road riding in summer conditions with an adjustable cradle.",
] as const;

// Verbatim from PLUGIN_ROOT/src/Resources/snippet/swag-assistant.en.json
export const GREETING = "Hi. I can look things up in this shop's catalogue.";
export const SUGGESTIONS = [
  "What do you sell?",
  "Help me find a gift",
  "How fast is delivery?",
] as const;
export const INPUT_PLACEHOLDER = "Ask about this shop…";
export const THINKING = "Thinking…";
export const ASSISTANT_NAME = "Shopping Assistant";
export const NUDGE = "Ask me anything about this shop";
export const CARD_VIEW = "View product";
export const CARD_ADD = "Add to cart";
export const CARD_ADDED = "Added";
export const STOCK_LABEL = { in: "In stock", low: "Low stock", out: "Out of stock" } as const;

export const REPLY_CPS = 60; // realistic LLM streaming rate
export const TYPING_CPS = 12; // human typing
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `npm test -- src/data/catalogue.test.ts`
Expected: PASS, 5 tests.

- [ ] **Step 5: Write the failing trace test**

Create `src/data/trace.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { LIST_COLUMNS, LIST_ROWS, TURN } from "./trace";

describe("the trace is the live capture, verbatim", () => {
  it("uses the Administration's own eight column labels in order", () => {
    expect(LIST_COLUMNS).toEqual([
      "Started",
      "Sales channel",
      "User",
      "Turns",
      "Outcome",
      "Question",
      "Reply",
      "Duration",
    ]);
  });

  it("has enough rows to fill the grid on screen", () => {
    expect(LIST_ROWS.length).toBeGreaterThanOrEqual(8);
  });

  it("formats durations the way the grid does, in seconds", () => {
    for (const r of LIST_ROWS) expect(r.duration).toMatch(/^\d+\.\d s$/);
  });

  it("carries the turn's real timing split", () => {
    expect(TURN.total).toBe("14.8 s");
    expect(TURN.shop).toBe("263 ms");
    expect(TURN.model).toBe("14.5 s");
  });

  it("includes at least one waiting-on-the-model row — the point of the beat", () => {
    const waits = TURN.rows.filter((r) => r.kind === "wait");
    expect(waits.length).toBeGreaterThanOrEqual(1);
    expect(waits[0].label).toBe("waiting on the model");
  });

  it("has the model's wait dominate every shop phase", () => {
    const ms = (s: string) =>
      s.endsWith("ms") ? parseFloat(s) : parseFloat(s) * 1000;
    const longestWait = Math.max(
      ...TURN.rows.filter((r) => r.kind === "wait").map((r) => ms(r.took)),
    );
    const longestPhase = Math.max(
      ...TURN.rows.filter((r) => r.kind === "phase").map((r) => ms(r.took)),
    );
    expect(longestWait).toBeGreaterThan(longestPhase);
  });
});
```

- [ ] **Step 6: Run it to confirm it fails**

Run: `npm test -- src/data/trace.test.ts`
Expected: FAIL — `Cannot find module './trace'`.

- [ ] **Step 7: Write the trace data**

Create `src/data/trace.ts`:

```ts
// Captured 2026-08-31 from the live Administration:
//   /admin#/swag/assistant/trace/detail/01a058010eca70f893ef5a24d2cb8c50
export type TraceRow = {
  at: string;
  kind: "phase" | "wait";
  label: string;
  fact?: string;
  factValue?: string;
  took: string;
};

export const LIST_COLUMNS = [
  "Started",
  "Sales channel",
  "User",
  "Turns",
  "Outcome",
  "Question",
  "Reply",
  "Duration",
] as const;

export const CONVERSATION_COUNT = "All 107 conversations";

export const LIST_ROWS = [
  { started: "2026-08-31T13:27:39.473+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 4, outcome: "product_shown", question: "What do the Trail Helmet and the Gravel Helmet cost, and is the Trail Helmet in stock in L?", reply: "I found the Trail Helmet in size L (Black) and the Gravel Helmet. The shop displays the current prices and live stock availability directly for both helmets.", duration: "22.5 s" },
  { started: "2026-08-31T13:26:46.698+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 2, outcome: "product_shown", question: "What is the difference between the Thermal Jersey Long Sleeve and the Club Jersey?", reply: "The main difference is…", duration: "6.3 s" },
  { started: "2026-08-31T13:25:59.105+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 2, outcome: "product_shown", question: "What is the difference between long finger gloves and winter gloves?", reply: "The main difference is…", duration: "7.8 s" },
  { started: "2026-08-31T13:25:07.751+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 2, outcome: "product_shown", question: "Compare the Carbon Bottle Cage, the Side-Load Cage and the Alloy Bottle Cage for me, side by side.", reply: "For rough…", duration: "7.7 s" },
  { started: "2026-08-31T13:21:32.860+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 4, outcome: "product_shown", question: "What do I need for riding in the rain?", reply: "There are many items for wet-weather riding…", duration: "20.6 s" },
  { started: "2026-08-31T13:19:06.617+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 2, outcome: "product_shown", question: "Was brauche ich für regenfahrten?", reply: "Für Rege…", duration: "11.9 s" },
  { started: "2026-08-31T13:17:36.951+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 4, outcome: "product_shown", question: "helm bräuchte ich ja", reply: "Für Fahrt…", duration: "21.1 s" },
  { started: "2026-08-31T13:17:00.379+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 2, outcome: "product_shown", question: "Welchen Flaschenhalter empfiehlst du mir", reply: "Ich empf…", duration: "9.4 s" },
  { started: "2026-08-31T12:20:24.692+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 12, outcome: "product_shown", question: "Actually I need a helmet in addition, what do you offer,", reply: "I found s…", duration: "48.2 s" },
  { started: "2026-08-31T11:14:49.159+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 14, outcome: "product_shown", question: "Kannst du mir dann helfen sättel zu vergleichen ich kann mich nicht entscxheiden", reply: "Hier sind…", duration: "61.7 s" },
  { started: "2026-08-31T11:05:58.273+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 2, outcome: "product_shown", question: "Do you have inner tubes for 700c?", reply: "I found t…", duration: "8.1 s" },
  { started: "2026-08-31T09:58:44.151+00:00", salesChannel: "shopping-assistant-starter-kit", user: "Guest user", turns: 2, outcome: "no_result", question: "hi", reply: "Hello! Ho…", duration: "3.2 s" },
] as const;

export const TURN = {
  index: 1,
  total: "14.8 s",
  shop: "263 ms",
  model: "14.5 s",
  shopper: "I only ride trails. Show me every helmet you have.",
  assistant:
    "For riding trails, I would recommend the Trail Helmet. It is built specifically with an extended rear shell, an adjustable visor, 22 vents, and is rated for trail and gravel use across all seasons. As alternatives from the helmet range: - Gravel Helmet: Also suited for trail and gravel riding with deeper rear coverage, but without the adjustable visor. - Road Helmet Aero: Designed specifically for road riding in summer conditions with an adjustable cradle. What size or colour would you prefer?",
  cardIds: [
    "a86ce746f3f5faa5859c240ee74a8b56",
    "cd070c32c1a7c56102b925b86f903329",
    "cb6df828730ede6627e54db10e818e03",
    "c61131c0ba1b3c4f49a3ec09ee67959c",
  ],
  rows: [
    { at: "+55 ms", kind: "phase", label: "Prepared", fact: "browsing category", factValue: "01a01edcb74a70fe86a5533cf261f01b", took: "38 ms" },
    { at: "+93 ms", kind: "phase", label: "Other", took: "0 ms" },
    { at: "+93 ms", kind: "wait", label: "waiting on the model", took: "3.4 s" },
    { at: "+3.5 s", kind: "phase", label: "Understood the question", fact: "searched for", factValue: "helmet", took: "103 ms" },
    { at: "+3.6 s", kind: "phase", label: "Searched the catalogue", fact: "found", factValue: "20", took: "1 ms" },
    { at: "+3.6 s", kind: "phase", label: "Other", took: "0 ms" },
    { at: "+3.6 s", kind: "phase", label: "Searched the catalogue", fact: "kept", factValue: "16", took: "0 ms" },
    { at: "+3.6 s", kind: "wait", label: "waiting on the model", took: "3.6 s" },
    { at: "+7.2 s", kind: "phase", label: "Understood the question", fact: "searched for", factValue: "helmet", took: "34 ms" },
    { at: "+7.3 s", kind: "phase", label: "Searched the catalogue", fact: "found", factValue: "20", took: "0 ms" },
    { at: "+7.3 s", kind: "wait", label: "waiting on the model", took: "7.5 s" },
    { at: "+14.8 s", kind: "phase", label: "Answered", fact: "rendered", factValue: "4 cards", took: "12 ms" },
  ] satisfies TraceRow[],
} as const;
```

- [ ] **Step 8: Run the test to confirm it passes**

Run: `npm test -- src/data/trace.test.ts`
Expected: PASS, 6 tests.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat(data): the real conversation, cards and trace from the 2026-08-31 capture"
```

---

## Task 5: Assets — fonts, logos, product photography, audio

**Files:**
- Create: `src/fonts.ts`
- Create: `public/assets/brand/*`, `public/assets/products/*`, `public/assets/audio/*`, `public/assets/audio/ATTRIBUTION.md`

**Interfaces:**
- Consumes: nothing
- Produces: `fontFamilies = { inter, barlow, plex }`, and every path referenced by `CARDS[].image`.

- [ ] **Step 1: Load the three fonts via Google Fonts**

All three Sidepath faces are Google Fonts, so no font files are bundled.

Create `src/fonts.ts`:

```ts
import { loadFont as loadInter } from "@remotion/google-fonts/Inter";
import { loadFont as loadBarlow } from "@remotion/google-fonts/BarlowCondensed";
import { loadFont as loadPlex } from "@remotion/google-fonts/IBMPlexMono";

const inter = loadInter("normal", { weights: ["400", "500", "600", "700"], subsets: ["latin"] });
const barlow = loadBarlow("normal", { weights: ["700", "800"], subsets: ["latin"] });
const plex = loadPlex("normal", { weights: ["400", "500"], subsets: ["latin"] });

export const fontFamilies = {
  inter: inter.fontFamily,
  barlow: barlow.fontFamily,
  plex: plex.fontFamily,
} as const;
```

- [ ] **Step 2: Copy the brand assets in**

```bash
mkdir -p public/assets/brand
PLUGIN_ROOT=${PLUGIN_ROOT:-../shopping-assistant-starter-kit}
cp "$PLUGIN_ROOT"/docs/demo-catalog/brand/logo.svg public/assets/brand/sidepath-logo.svg
cp "$PLUGIN_ROOT"/docs/demo-catalog/brand/mark.svg public/assets/brand/sidepath-mark.svg
cp ~/.agents/skills/shopware-brand-spec/assets/logos/shopware_logo_blue-RGB_HOR.svg public/assets/brand/shopware-hor.svg
cp ~/.agents/skills/shopware-brand-spec/assets/logos/shopware_logo_white_on_transparent-RGB_HOR.svg public/assets/brand/shopware-hor-white.svg
ls public/assets/brand/
```

Expected: four SVGs.

- [ ] **Step 3: Place the real product photos**

Two of the four exist on the live shop and were downloaded during design.

```bash
mkdir -p public/assets/products
cp /private/tmp/claude-502/*/*/scratchpad/ref/products/sk-101-trail-helmet.png public/assets/products/
cp /private/tmp/claude-502/*/*/scratchpad/ref/products/sk-102-commuter-helmet.png public/assets/products/
```

If those paths have been cleaned up, re-download:

```bash
B=https://shoppingassistan-rschulte.eu-core-1.shopdev.de
curl -sS -o public/assets/products/sk-101-trail-helmet.png "$B/media/93/68/81/1787225494/sask-sk-101.png?ts=1787225494"
curl -sS -o public/assets/products/sk-102-commuter-helmet.png "$B/media/26/13/5d/1787225495/sask-sk-102.png?ts=1787225495"
```

- [ ] **Step 4: Add the two generated photos**

`sk-106-gravel-helmet.png` and `sk-109-road-helmet-aero.png` are generated to match the real photos' style. Style brief, derived from `sk-101`:

> 1024² square. Seamless flat warm off-white ground, Paper `#f2f0ed`, no horizon line and no visible edge. Subject centred at roughly 80% of frame width, three-quarter side view facing left. Soft large diffused light from above and slightly in front; gentle soft contact shadow directly beneath and slightly to the left. No props, no text, no logos, no people. Crisp focus throughout, neutral colour, catalogue style.

Gravel Helmet is **olive matte, deep rear coverage, no visor** (the assistant's own description). Road Helmet Aero is **white matte, low-profile aero shell, long forward vents, rear cradle dial**.

Verify both are 1024×1024 and that the background reads as `#f2f0ed`:

```bash
for f in public/assets/products/*.png; do
  python3 -c "
import struct,sys
d=open('$f','rb').read(); w,h=struct.unpack('>II',d[16:24]); print('$f',w,'x',h)
"
done
```

Expected: all four 1024×1024.

- [ ] **Step 5: Place the music**

```bash
mkdir -p public/assets/audio
# Minimal Electronica — Kulakovka, Pixabay Content License.
curl -sS -A "Mozilla/5.0" -o public/assets/audio/music.mp3 \
  "https://cdn.pixabay.com/audio/2024/12/10/audio_de323dd9de.mp3"
ffprobe -v error -show_entries format=duration -of csv=p=0 public/assets/audio/music.mp3
```

Expected: ~155.45 seconds. The composition trims to the 9.0s–89.0s window; do not pre-trim the file, so the window stays adjustable from `timeline.ts`.

- [ ] **Step 6: Source the SFX from CC0 sources only**

Needed: `key.wav`, `click.wav`, `whoosh.wav`, `cart.wav`.

Use Kenney's interface/UI audio packs (CC0, direct download from `kenney.nl/assets`) or freesound.org filtered to CC0. For each file, record source URL and licence in `public/assets/audio/ATTRIBUTION.md`. **If a sound has no verifiable CC0 equivalent, synthesise that one** rather than using an ambiguous source.

Create `public/assets/audio/ATTRIBUTION.md` with one row per file:

```markdown
# Audio attribution

| File | Source | Licence |
|---|---|---|
| music.mp3 | Minimal Electronica — Kulakovka, https://pixabay.com/music/upbeat-minimal-electronica-274978/ | Pixabay Content License — commercial use permitted, attribution not required |
| key.wav | <url> | CC0 |
| click.wav | <url> | CC0 |
| whoosh.wav | <url> | CC0 |
| cart.wav | <url> | CC0 |
```

- [ ] **Step 7: Verify every asset the data references exists**

Create `src/data/assets.test.ts`:

```ts
import { existsSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { CARDS } from "./catalogue";

describe("assets", () => {
  it("has a real file behind every card image", () => {
    for (const c of CARDS) {
      expect(c.image).toBeTruthy();
      expect(existsSync(join("public/assets", c.image!))).toBe(true);
    }
  });

  it("has the music and every sound effect", () => {
    for (const f of ["music.mp3", "key.wav", "click.wav", "whoosh.wav", "cart.wav"]) {
      expect(existsSync(join("public/assets/audio", f))).toBe(true);
    }
  });

  it("has an attribution entry for every audio file", () => {
    const md = require("node:fs").readFileSync("public/assets/audio/ATTRIBUTION.md", "utf8");
    for (const f of ["music.mp3", "key.wav", "click.wav", "whoosh.wav", "cart.wav"]) {
      expect(md).toContain(f);
    }
  });
});
```

- [ ] **Step 8: Run it**

Run: `npm test -- src/data/assets.test.ts`
Expected: PASS, 3 tests.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat(assets): fonts, brand marks, product photography, licence-clean audio"
```

---

## Task 6: Chrome — backdrop, browser frame, cursor, caption

**Files:**
- Create: `src/ui/chrome/Backdrop.tsx`, `BrowserFrame.tsx`, `Cursor.tsx`, `Caption.tsx`
- Test: `src/ui/chrome/BrowserFrame.test.tsx`

**Interfaces:**
- Consumes: `tokens`, `sidepath`, `fontFamilies`, `cursorAt`, `RIPPLE_FRAMES`
- Produces:
  - `<Backdrop>{children}</Backdrop>`
  - `<BrowserFrame url={string}>{children}</BrowserFrame>` — inner viewport is exactly 1600×900 at origin `(160, 120)` in composition space. Export those as `VIEWPORT = { x: 160, y: 120, width: 1600, height: 900 }` so cursor keyframes can be written in viewport coordinates.
  - `<Cursor keys={CursorKey[]} />`
  - `<Caption text={string} from={number} durationInFrames={number} />`

- [ ] **Step 1: Write the failing viewport test**

Cursor keyframes across every scene are written against `VIEWPORT`, so its numbers must be fixed and asserted once.

Create `src/ui/chrome/BrowserFrame.test.tsx`:

```tsx
import { describe, expect, it } from "vitest";
import { VIEWPORT } from "./BrowserFrame";

describe("VIEWPORT", () => {
  it("is a 16:9 viewport centred horizontally in a 1920x1080 frame", () => {
    expect(VIEWPORT).toEqual({ x: 160, y: 120, width: 1600, height: 900 });
    expect(VIEWPORT.x * 2 + VIEWPORT.width).toBe(1920);
    expect(VIEWPORT.width / VIEWPORT.height).toBeCloseTo(16 / 9, 5);
  });

  it("leaves room below the frame for the Shopware lockup", () => {
    expect(VIEWPORT.y + VIEWPORT.height).toBeLessThan(1080);
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npm test -- src/ui/chrome/BrowserFrame.test.tsx`
Expected: FAIL — `Cannot find module './BrowserFrame'`.

- [ ] **Step 3: Implement the backdrop**

Create `src/ui/chrome/Backdrop.tsx`:

```tsx
import { AbsoluteFill, Img, staticFile } from "remotion";
import { tokens } from "../../theme/tokens";

export const Backdrop: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <AbsoluteFill style={{ backgroundColor: tokens.tint }}>
    {children}
    <Img
      src={staticFile("assets/brand/shopware-hor.svg")}
      style={{ position: "absolute", left: 64, bottom: 44, height: 34, opacity: 0.9 }}
    />
  </AbsoluteFill>
);
```

- [ ] **Step 4: Implement the browser frame**

Create `src/ui/chrome/BrowserFrame.tsx`:

```tsx
import { tokens } from "../../theme/tokens";
import { fontFamilies } from "../../fonts";

export const VIEWPORT = { x: 160, y: 120, width: 1600, height: 900 } as const;

const CHROME_HEIGHT = 44;

export const BrowserFrame: React.FC<{ url: string; children: React.ReactNode }> = ({
  url,
  children,
}) => (
  <div
    style={{
      position: "absolute",
      left: VIEWPORT.x,
      top: VIEWPORT.y - CHROME_HEIGHT,
      width: VIEWPORT.width,
      borderRadius: 14,
      overflow: "hidden",
      backgroundColor: "#ffffff",
      boxShadow: tokens.lift[3],
    }}
  >
    <div
      style={{
        height: CHROME_HEIGHT,
        display: "flex",
        alignItems: "center",
        gap: 8,
        padding: "0 14px",
        backgroundColor: "#f4f5f7",
        borderBottom: `1px solid ${tokens.hairline}`,
      }}
    >
      {["#ff5f57", "#febc2e", "#28c840"].map((c) => (
        <span key={c} style={{ width: 11, height: 11, borderRadius: 999, backgroundColor: c }} />
      ))}
      <div
        style={{
          marginLeft: 12,
          flex: 1,
          height: 26,
          borderRadius: tokens.radius.pill,
          backgroundColor: "#ffffff",
          border: `1px solid ${tokens.hairline}`,
          display: "flex",
          alignItems: "center",
          padding: "0 12px",
          fontFamily: fontFamilies.inter,
          fontSize: tokens.font.meta,
          color: tokens.meta,
        }}
      >
        {url}
      </div>
    </div>
    <div style={{ width: VIEWPORT.width, height: VIEWPORT.height, position: "relative", overflow: "hidden" }}>
      {children}
    </div>
  </div>
);
```

- [ ] **Step 5: Implement the cursor**

Create `src/ui/chrome/Cursor.tsx`:

```tsx
import { interpolate, useCurrentFrame } from "remotion";
import { RIPPLE_FRAMES, cursorAt, type CursorKey } from "../../lib/cursor";
import { VIEWPORT } from "./BrowserFrame";

export const Cursor: React.FC<{ keys: CursorKey[] }> = ({ keys }) => {
  const frame = useCurrentFrame();
  const { x, y, clickAge } = cursorAt(keys, frame);
  const left = VIEWPORT.x + x;
  const top = VIEWPORT.y + y;

  return (
    <>
      {clickAge !== null && (
        <span
          style={{
            position: "absolute",
            left,
            top,
            width: interpolate(clickAge, [0, RIPPLE_FRAMES], [8, 46]),
            height: interpolate(clickAge, [0, RIPPLE_FRAMES], [8, 46]),
            marginLeft: -interpolate(clickAge, [0, RIPPLE_FRAMES], [4, 23]),
            marginTop: -interpolate(clickAge, [0, RIPPLE_FRAMES], [4, 23]),
            borderRadius: 999,
            border: "2px solid rgba(0,21,62,0.30)",
            opacity: interpolate(clickAge, [0, RIPPLE_FRAMES], [0.9, 0]),
          }}
        />
      )}
      <svg
        width="26"
        height="26"
        viewBox="0 0 26 26"
        style={{ position: "absolute", left, top, filter: "drop-shadow(0 2px 3px rgba(0,21,62,0.35))" }}
      >
        <path d="M4 2 L4 19 L9 14.5 L12.5 22 L15.5 20.5 L12 13.5 L18.5 13 Z" fill="#ffffff" stroke="#00153e" strokeWidth="1.4" />
      </svg>
    </>
  );
};
```

- [ ] **Step 6: Implement the caption**

Create `src/ui/chrome/Caption.tsx`:

```tsx
import { Sequence, interpolate, useCurrentFrame } from "remotion";
import { tokens } from "../../theme/tokens";
import { fontFamilies } from "../../fonts";

const Body: React.FC<{ text: string; durationInFrames: number }> = ({ text, durationInFrames }) => {
  const frame = useCurrentFrame();
  const opacity = interpolate(
    frame,
    [0, 8, durationInFrames - 8, durationInFrames],
    [0, 1, 1, 0],
    { extrapolateLeft: "clamp", extrapolateRight: "clamp" },
  );
  const lift = interpolate(frame, [0, 12], [10, 0], { extrapolateRight: "clamp" });

  return (
    <div
      style={{
        position: "absolute",
        left: 0,
        right: 0,
        bottom: 46,
        display: "flex",
        justifyContent: "center",
        opacity,
        transform: `translateY(${lift}px)`,
      }}
    >
      <span
        style={{
          fontFamily: fontFamilies.inter,
          fontSize: 26,
          fontWeight: 600,
          color: tokens.text,
          backgroundColor: "rgba(255,255,255,0.92)",
          padding: "10px 20px",
          borderRadius: tokens.radius.control,
          boxShadow: tokens.lift[1],
        }}
      >
        {text}
      </span>
    </div>
  );
};

export const Caption: React.FC<{ text: string; from: number; durationInFrames: number }> = ({
  text,
  from,
  durationInFrames,
}) => (
  <Sequence from={from} durationInFrames={durationInFrames} premountFor={15} layout="none">
    <Body text={text} durationInFrames={durationInFrames} />
  </Sequence>
);
```

- [ ] **Step 7: Run the test to confirm it passes**

Run: `npm test -- src/ui/chrome/BrowserFrame.test.tsx`
Expected: PASS, 2 tests.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(chrome): backdrop, browser frame with a fixed viewport, cursor, captions"
```

---

## Task 7: The Sidepath storefront shell

**Files:**
- Create: `src/ui/shop/SidepathHeader.tsx`, `Hero.tsx`, `StatBand.tsx`, `AisleChips.tsx`, `Storefront.tsx`
- Test: `src/ui/shop/Storefront.test.tsx`

**Interfaces:**
- Consumes: `sidepath`, `sidepathType`, `fontFamilies`, `CART_TOTAL_BEFORE`
- Produces: `<Storefront cartTotal={string} />` rendering the full page at `VIEWPORT` size.

- [ ] **Step 1: Write the failing storefront test**

Create `src/ui/shop/Storefront.test.tsx`:

```tsx
import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Storefront } from "./Storefront";

describe("Storefront", () => {
  it("renders the ten nav items from the live capture, in order", () => {
    render(<Storefront cartTotal="€0.00" />);
    const nav = screen.getByRole("navigation");
    expect(nav.textContent).toBe(
      "HomeBrakesTyresMaintenanceHelmetsMerchAccessoriesApparelRestrictedComponents",
    );
  });

  it("renders the hero headline as two lines", () => {
    render(<Storefront cartTotal="€0.00" />);
    expect(screen.getByText("PARTS FOR THE ROADS")).toBeTruthy();
    expect(screen.getByText("NOT ON THE MAP")).toBeTruthy();
  });

  it("shows the cart total it is given", () => {
    render(<Storefront cartTotal="€89.00" />);
    expect(screen.getByText("€89.00")).toBeTruthy();
  });

  it("renders the three stat-band claims", () => {
    render(<Storefront cartTotal="€0.00" />);
    expect(screen.getByText(/REAL STOCK COUNTS/)).toBeTruthy();
    expect(screen.getByText(/DISPATCH 1–3 DAYS/)).toBeTruthy();
    expect(screen.getByText(/28 PRODUCTS/)).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npm test -- src/ui/shop/Storefront.test.tsx`
Expected: FAIL — `Cannot find module './Storefront'`.

- [ ] **Step 3: Implement the storefront**

Create `src/ui/shop/Storefront.tsx`. Values are from the 2026-08-31 live capture; compare against `reference/storefront-home.png` while building.

```tsx
import { Img, staticFile } from "remotion";
import { sidepath, sidepathType } from "../../theme/sidepath";
import { fontFamilies } from "../../fonts";

const NAV = ["Home", "Brakes", "Tyres", "Maintenance", "Helmets", "Merch", "Accessories", "Apparel", "Restricted", "Components"];

const STATS: [string, string][] = [
  ["REAL STOCK COUNTS", "NO BACK-ORDER THEATRE"],
  ["DISPATCH 1–3 DAYS", "FROM ONE WAREHOUSE"],
  ["28 PRODUCTS", "AND NOT ONE FILLER"],
];

const AISLES = ["APPAREL", "ACCESSORIES", "COMPONENTS", "HELMETS", "TYRES", "BRAKES", "MAINTENANCE", "MERCH", "LIGHTS"];

export const Storefront: React.FC<{ cartTotal: string }> = ({ cartTotal }) => (
  <div style={{ width: "100%", height: "100%", backgroundColor: sidepath.paper, fontFamily: fontFamilies.inter, color: sidepath.ink, overflow: "hidden" }}>
    {/* header */}
    <div style={{ display: "flex", alignItems: "center", padding: "26px 56px 0" }}>
      <Img src={staticFile("assets/brand/sidepath-logo.svg")} style={{ height: 36 }} />
      <div style={{ flex: 1, display: "flex", justifyContent: "center" }}>
        <div style={{ width: 340, height: 38, border: `1px solid ${sidepath.hairline}`, backgroundColor: "#fff", display: "flex", alignItems: "center", padding: "0 14px", fontSize: 14, color: "#8a8a8a" }}>
          Enter search term...
        </div>
      </div>
      <span style={{ fontFamily: sidepathType.display, fontWeight: 700, fontSize: 22, color: sidepath.teal }}>{cartTotal}</span>
    </div>

    <nav role="navigation" style={{ display: "flex", gap: 26, padding: "22px 56px 18px", fontSize: 15 }}>
      {NAV.map((n, i) => (
        <span key={n} style={{ borderBottom: i === 0 ? `2px solid ${sidepath.teal}` : "none", paddingBottom: 3 }}>{n}</span>
      ))}
    </nav>

    {/* hero */}
    <div style={{ position: "relative", height: 470, backgroundColor: "#1b1f22", overflow: "hidden" }}>
      <div style={{ position: "absolute", inset: 0, background: "linear-gradient(100deg,#14181b 0%,#2b3237 55%,#4a5560 100%)" }} />
      <div style={{ position: "absolute", left: 56, top: 78 }}>
        <div style={{ fontFamily: sidepathType.meta, fontSize: 12, letterSpacing: 3, color: sidepath.teal, marginBottom: 18 }}>
          GRAVEL · COMMUTE · ALL-ROAD
        </div>
        <div style={{ fontFamily: sidepathType.display, fontWeight: 800, fontSize: 68, lineHeight: 0.94, letterSpacing: -1, color: "#f2f0ed" }}>
          PARTS FOR THE ROADS
        </div>
        <div style={{ fontFamily: sidepathType.display, fontWeight: 800, fontSize: 68, lineHeight: 0.94, letterSpacing: -1, color: "transparent", WebkitTextStroke: `2px ${sidepath.teal}` }}>
          NOT ON THE MAP
        </div>
      </div>
      <div style={{ position: "absolute", left: 56, right: 56, bottom: 0, display: "flex", borderTop: "1px solid rgba(242,240,237,0.22)" }}>
        {STATS.map(([a, b]) => (
          <div key={a} style={{ flex: 1, padding: "20px 0", fontFamily: sidepathType.meta, fontSize: 11, letterSpacing: 1.5, color: "#f2f0ed" }}>
            <strong>{a}</strong> <span style={{ opacity: 0.7 }}>— {b}</span>
          </div>
        ))}
      </div>
    </div>

    {/* aisles */}
    <div style={{ padding: "34px 56px" }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", marginBottom: 22 }}>
        <span style={{ fontFamily: sidepathType.display, fontWeight: 700, fontSize: 30 }}>THE AISLES</span>
        <span style={{ fontFamily: sidepathType.meta, fontSize: 11, letterSpacing: 1.5, opacity: 0.6 }}>09 DEPARTMENTS</span>
      </div>
      <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
        {AISLES.map((a, i) => (
          <span key={a} style={{ border: `1px solid ${sidepath.hairline}`, padding: "10px 16px", fontFamily: sidepathType.meta, fontSize: 12, letterSpacing: 1.2 }}>
            <span style={{ color: sidepath.teal }}>{String(i + 1).padStart(2, "0")}</span> {a}
          </span>
        ))}
      </div>
    </div>
  </div>
);
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `npm test -- src/ui/shop/Storefront.test.tsx`
Expected: PASS, 4 tests.

- [ ] **Step 5: Compare against the reference**

Render a still and put it beside the capture:

```bash
npx remotion still ShoppingAssistant out/check-storefront.png --frame=60
open out/check-storefront.png reference/storefront-home.png
```

Adjust spacing, weights and the hero gradient until they read as the same page. The hero photo is replaced by a gradient deliberately — the real photo is not in the repo, and a gradient is honest rather than a broken image.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(shop): Sidepath storefront shell from the live capture"
```

---

## Task 8: The widget — disc, nudge, panel, composer, thinking

**Files:**
- Create: `src/ui/widget/Disc.tsx`, `Nudge.tsx`, `Panel.tsx`, `PanelHeader.tsx`, `Composer.tsx`, `Thinking.tsx`
- Test: `src/ui/widget/Panel.test.tsx`

**Interfaces:**
- Consumes: `tokens`, `sidepath`, `fontFamilies`, conversation copy constants
- Produces:
  - `<Disc thinking?: boolean />` — 60px teal disc, masked chat glyph, three dots when thinking
  - `<Nudge visible: boolean />`
  - `<Panel progress: number>{children}</Panel>` — `progress` 0→1 drives the open animation
  - `<PanelHeader />`, `<Composer value: string focused: boolean />`, `<Thinking />`
  - Exports `PANEL_BOX = { right: 24, top: 24, width: 480, height: 760 }` in viewport coordinates.

- [ ] **Step 1: Write the failing panel test**

Create `src/ui/widget/Panel.test.tsx`:

```tsx
import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { PanelHeader } from "./PanelHeader";
import { Composer } from "./Composer";
import { ASSISTANT_NAME, INPUT_PLACEHOLDER } from "../../data/conversation";

describe("PanelHeader", () => {
  it("says Shopping Assistant, not the live shop's Gustav", () => {
    render(<PanelHeader />);
    expect(screen.getByText(ASSISTANT_NAME)).toBeTruthy();
    expect(screen.queryByText("Gustav")).toBeNull();
  });
});

describe("Composer", () => {
  it("shows the snippet placeholder when empty", () => {
    render(<Composer value="" focused={false} />);
    expect(screen.getByText(INPUT_PLACEHOLDER)).toBeTruthy();
  });

  it("shows typed text instead of the placeholder", () => {
    render(<Composer value="I only ride trails." focused />);
    expect(screen.getByText("I only ride trails.")).toBeTruthy();
    expect(screen.queryByText(INPUT_PLACEHOLDER)).toBeNull();
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npm test -- src/ui/widget/Panel.test.tsx`
Expected: FAIL — `Cannot find module './PanelHeader'`.

- [ ] **Step 3: Implement the disc and nudge**

The disc is `swag-assistant-disc`: one flat fill, one elevation step, **no border**. The glyph is a masked chat icon at 42% of the disc, in the computed foreground for the merchant colour — white on Sidepath Teal.

Create `src/ui/widget/Disc.tsx`:

```tsx
import { interpolate, useCurrentFrame } from "remotion";
import { tokens } from "../../theme/tokens";
import { sidepath } from "../../theme/sidepath";

const CHAT_GLYPH =
  "M2 3a1 1 0 0 1 1-1h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H8l-5 4V16H3a1 1 0 0 1-1-1V3z";

export const Disc: React.FC<{ thinking?: boolean; size?: number }> = ({
  thinking = false,
  size = tokens.orbSize,
}) => {
  const frame = useCurrentFrame();
  return (
    <div
      style={{
        width: size,
        height: size,
        borderRadius: "50%",
        backgroundColor: sidepath.teal,
        boxShadow: tokens.lift[2],
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
      }}
    >
      {thinking ? (
        <div style={{ display: "flex", gap: size * 0.07 }}>
          {[0, 1, 2].map((i) => (
            <span
              key={i}
              style={{
                width: size * 0.09,
                height: size * 0.09,
                borderRadius: 999,
                backgroundColor: "#fff",
                opacity: interpolate(
                  (frame + i * 6) % 30,
                  [0, 10, 20, 30],
                  [0.35, 1, 0.35, 0.35],
                ),
              }}
            />
          ))}
        </div>
      ) : (
        <svg width={size * 0.42} height={size * 0.42} viewBox="0 0 24 24">
          <path d={CHAT_GLYPH} fill="#ffffff" />
        </svg>
      )}
    </div>
  );
};
```

Create `src/ui/widget/Nudge.tsx`:

```tsx
import { tokens } from "../../theme/tokens";
import { fontFamilies } from "../../fonts";
import { NUDGE } from "../../data/conversation";

export const Nudge: React.FC<{ opacity: number }> = ({ opacity }) => (
  <div
    style={{
      position: "absolute",
      right: tokens.orbInset + tokens.orbSize + 12,
      bottom: tokens.orbInset + 6,
      maxWidth: 150,
      backgroundColor: tokens.surface,
      color: tokens.text,
      fontFamily: fontFamilies.inter,
      fontSize: tokens.font.meta,
      fontWeight: 600,
      lineHeight: 1.35,
      padding: "10px 12px",
      borderRadius: tokens.radius.control,
      boxShadow: tokens.lift[2],
      opacity,
    }}
  >
    {NUDGE}
  </div>
);
```

- [ ] **Step 4: Implement the panel, header, composer, thinking**

The panel takes elevation and no border. The message log is **bottom-anchored** — the live capture shows content stacking from the bottom.

Create `src/ui/widget/PanelHeader.tsx`:

```tsx
import { tokens } from "../../theme/tokens";
import { sidepath } from "../../theme/sidepath";
import { fontFamilies } from "../../fonts";
import { ASSISTANT_NAME } from "../../data/conversation";

export const PanelHeader: React.FC = () => (
  <div
    style={{
      display: "flex",
      alignItems: "center",
      gap: 12,
      padding: "18px 20px",
      borderBottom: `1px solid ${tokens.hairline}`,
    }}
  >
    <span
      style={{
        width: 34,
        height: 34,
        borderRadius: 9,
        backgroundColor: sidepath.teal,
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
      }}
    >
      <svg width="15" height="15" viewBox="0 0 24 24">
        <path d="M2 3a1 1 0 0 1 1-1h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H8l-5 4V16H3a1 1 0 0 1-1-1V3z" fill="#fff" />
      </svg>
    </span>
    <span style={{ flex: 1, fontFamily: fontFamilies.inter, fontSize: tokens.font.heading, fontWeight: 700, color: tokens.text }}>
      {ASSISTANT_NAME}
    </span>
    <span style={{ fontSize: 22, color: tokens.meta, lineHeight: 1 }}>+</span>
    <span style={{ fontSize: 22, color: tokens.meta, lineHeight: 1, marginLeft: 6 }}>×</span>
  </div>
);
```

Create `src/ui/widget/Composer.tsx`:

```tsx
import { tokens } from "../../theme/tokens";
import { sidepath } from "../../theme/sidepath";
import { fontFamilies } from "../../fonts";
import { INPUT_PLACEHOLDER } from "../../data/conversation";

export const Composer: React.FC<{ value: string; focused: boolean; sent?: boolean }> = ({
  value,
  focused,
  sent = false,
}) => (
  <div style={{ display: "flex", alignItems: "center", gap: 10, padding: "14px 16px 18px" }}>
    <div
      style={{
        flex: 1,
        height: 46,
        display: "flex",
        alignItems: "center",
        padding: "0 16px",
        borderRadius: tokens.radius.pill,
        backgroundColor: tokens.surface,
        border: `2px solid ${focused ? sidepath.teal : tokens.hairline}`,
        fontFamily: fontFamilies.inter,
        fontSize: tokens.font.body,
        color: value ? tokens.text : tokens.meta,
      }}
    >
      {value || INPUT_PLACEHOLDER}
      {focused && !sent && (
        <span style={{ width: 2, height: 20, backgroundColor: sidepath.teal, marginLeft: 2 }} />
      )}
    </div>
    <span
      style={{
        width: 40,
        height: 40,
        borderRadius: "50%",
        backgroundColor: value ? sidepath.teal : tokens.hairline,
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
      }}
    >
      <svg width="17" height="17" viewBox="0 0 24 24">
        <path d="M3 20.5 21 12 3 3.5 3 10l12 2-12 2z" fill={value ? "#fff" : tokens.meta} />
      </svg>
    </span>
  </div>
);
```

Create `src/ui/widget/Thinking.tsx`:

```tsx
import { tokens } from "../../theme/tokens";
import { fontFamilies } from "../../fonts";
import { THINKING } from "../../data/conversation";
import { Disc } from "./Disc";

export const Thinking: React.FC = () => (
  <div style={{ display: "flex", alignItems: "center", gap: 10, padding: "8px 20px 4px" }}>
    <Disc thinking size={26} />
    <span style={{ fontFamily: fontFamilies.inter, fontSize: tokens.font.meta, color: tokens.meta }}>
      {THINKING}
    </span>
  </div>
);
```

Create `src/ui/widget/Panel.tsx`:

```tsx
import { interpolate } from "remotion";
import { tokens } from "../../theme/tokens";
import { PanelHeader } from "./PanelHeader";

export const PANEL_BOX = { right: 24, top: 24, width: 480, height: 760 } as const;

export const Panel: React.FC<{ progress: number; children: React.ReactNode }> = ({
  progress,
  children,
}) => (
  <div
    style={{
      position: "absolute",
      right: PANEL_BOX.right,
      top: PANEL_BOX.top,
      width: PANEL_BOX.width,
      height: PANEL_BOX.height,
      backgroundColor: tokens.surface,
      borderRadius: tokens.radius.panel,
      boxShadow: tokens.lift[3],
      display: "flex",
      flexDirection: "column",
      overflow: "hidden",
      opacity: interpolate(progress, [0, 0.35], [0, 1], { extrapolateRight: "clamp" }),
      transform: `translateY(${interpolate(progress, [0, 1], [26, 0])}px) scale(${interpolate(progress, [0, 1], [0.97, 1])})`,
      transformOrigin: "bottom right",
    }}
  >
    <PanelHeader />
    {/* Bottom-anchored log, as the live widget stacks it. */}
    <div style={{ flex: 1, display: "flex", flexDirection: "column", justifyContent: "flex-end", overflow: "hidden", backgroundColor: tokens.tint }}>
      {children}
    </div>
  </div>
);
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `npm test -- src/ui/widget/Panel.test.tsx`
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(widget): disc, nudge, panel, composer and thinking indicator"
```

---

## Task 9: The widget — messages and the product card carousel

**Files:**
- Create: `src/ui/widget/Message.tsx`, `src/ui/widget/ProductCard.tsx`, `src/ui/widget/CardCarousel.tsx`
- Test: `src/ui/widget/ProductCard.test.tsx`

**Interfaces:**
- Consumes: `tokens`, `sidepath`, `CARDS`, `STOCK_LABEL`, `CARD_VIEW`, `CARD_ADD`, `CARD_ADDED`
- Produces:
  - `<Message role="user" | "assistant" text={string} bullets?={readonly string[]} time?={string} />`
  - `<ProductCard card={Card} added?: boolean />`
  - `<CardCarousel cards={readonly Card[]} progress={number} addedId?: string />`

- [ ] **Step 1: Write the failing card test**

Create `src/ui/widget/ProductCard.test.tsx`:

```tsx
import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { ProductCard } from "./ProductCard";
import { CARDS } from "../../data/catalogue";

const trail = CARDS[0];
const gravel = CARDS[1];

describe("ProductCard", () => {
  it("renders the real price and stock label", () => {
    render(<ProductCard card={trail} />);
    expect(screen.getByText("€89.00")).toBeTruthy();
    expect(screen.getByText("In stock")).toBeTruthy();
  });

  it("shows the delivery line for an in-stock card", () => {
    render(<ProductCard card={trail} />);
    expect(screen.getByText("Delivery: 1-3 days")).toBeTruthy();
  });

  it("omits the delivery line for a low-stock card, as the live widget does", () => {
    render(<ProductCard card={gravel} />);
    expect(screen.queryByText(/Delivery:/)).toBeNull();
    expect(screen.getByText("Low stock")).toBeTruthy();
  });

  it("offers both actions by default", () => {
    render(<ProductCard card={trail} />);
    expect(screen.getByText("View product")).toBeTruthy();
    expect(screen.getByText("Add to cart")).toBeTruthy();
  });

  it("swaps the action label once added", () => {
    render(<ProductCard card={trail} added />);
    expect(screen.getByText("Added")).toBeTruthy();
    expect(screen.queryByText("Add to cart")).toBeNull();
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npm test -- src/ui/widget/ProductCard.test.tsx`
Expected: FAIL — `Cannot find module './ProductCard'`.

- [ ] **Step 3: Implement the card**

A card sits on the log's own ground, so it takes a border and **no** elevation.

Create `src/ui/widget/ProductCard.tsx`:

```tsx
import { Img, staticFile } from "remotion";
import { tokens } from "../../theme/tokens";
import { sidepath } from "../../theme/sidepath";
import { fontFamilies } from "../../fonts";
import type { Card } from "../../data/catalogue";
import { CARD_ADD, CARD_ADDED, CARD_VIEW, STOCK_LABEL } from "../../data/conversation";

const DOT: Record<Card["stock"], string> = {
  in: tokens.inStock,
  low: tokens.lowStock,
  out: tokens.outOfStock,
};

export const CARD_WIDTH = 178;

export const ProductCard: React.FC<{ card: Card; added?: boolean }> = ({ card, added = false }) => (
  <div
    style={{
      width: CARD_WIDTH,
      flex: `0 0 ${CARD_WIDTH}px`,
      backgroundColor: tokens.surface,
      border: `1px solid ${tokens.hairline}`,
      borderRadius: tokens.radius.card,
      overflow: "hidden",
      fontFamily: fontFamilies.inter,
    }}
  >
    <div style={{ height: CARD_WIDTH, backgroundColor: sidepath.paper }}>
      <Img src={staticFile(`assets/${card.image}`)} style={{ width: "100%", height: "100%", objectFit: "cover" }} />
    </div>
    <div style={{ padding: "10px 12px 12px" }}>
      <div style={{ fontSize: tokens.font.body, fontWeight: 700, color: tokens.text, lineHeight: 1.2 }}>{card.name}</div>
      {card.variant && (
        <div style={{ fontSize: tokens.font.meta, color: tokens.meta, marginTop: 3 }}>{card.variant}</div>
      )}
      <div style={{ fontSize: tokens.font.meta, color: tokens.meta, marginTop: 2 }}>{card.spec}</div>
      {card.delivery && (
        <div style={{ fontSize: tokens.font.meta, color: tokens.meta, marginTop: 2 }}>{card.delivery}</div>
      )}
      <div style={{ fontSize: tokens.font.heading, fontWeight: 700, color: tokens.text, marginTop: 9 }}>{card.price}</div>
      <div style={{ display: "flex", alignItems: "center", gap: 6, marginTop: 5 }}>
        <span style={{ width: 7, height: 7, borderRadius: 999, backgroundColor: DOT[card.stock] }} />
        <span style={{ fontSize: tokens.font.meta, color: tokens.meta }}>{STOCK_LABEL[card.stock]}</span>
      </div>
      <div
        style={{
          marginTop: 11,
          height: 32,
          border: `1px solid ${tokens.hairline}`,
          borderRadius: tokens.radius.control,
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          fontSize: tokens.font.meta,
          fontWeight: 600,
          color: sidepath.teal,
        }}
      >
        {CARD_VIEW}
      </div>
      <div
        style={{
          marginTop: 7,
          height: 32,
          backgroundColor: sidepath.teal,
          borderRadius: tokens.radius.control,
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          fontSize: tokens.font.meta,
          fontWeight: 600,
          color: "#fff",
        }}
      >
        {added ? CARD_ADDED : CARD_ADD}
      </div>
    </div>
  </div>
);
```

- [ ] **Step 4: Implement the carousel and the message**

Create `src/ui/widget/CardCarousel.tsx`:

```tsx
import { interpolate } from "remotion";
import { tokens } from "../../theme/tokens";
import type { Card } from "../../data/catalogue";
import { CARD_WIDTH, ProductCard } from "./ProductCard";

export const CardCarousel: React.FC<{
  cards: readonly Card[];
  progress: number;
  addedId?: string;
}> = ({ cards, progress, addedId }) => (
  <div style={{ padding: "10px 20px 0" }}>
    <div style={{ display: "flex", gap: 10, overflow: "hidden" }}>
      {cards.map((c, i) => {
        // Staggered entrance: one card every 0.12 of the beat's progress.
        const local = interpolate(progress, [i * 0.12, i * 0.12 + 0.3], [0, 1], {
          extrapolateLeft: "clamp",
          extrapolateRight: "clamp",
        });
        return (
          <div
            key={c.id}
            style={{
              opacity: local,
              transform: `translateY(${interpolate(local, [0, 1], [14, 0])}px)`,
              flex: `0 0 ${CARD_WIDTH}px`,
            }}
          >
            <ProductCard card={c} added={addedId === c.id} />
          </div>
        );
      })}
    </div>
    <div style={{ height: 4, borderRadius: 999, backgroundColor: tokens.hairline, margin: "10px 0 0", width: "62%" }} />
  </div>
);
```

Create `src/ui/widget/Message.tsx`:

```tsx
import { tokens } from "../../theme/tokens";
import { fontFamilies } from "../../fonts";

export const Message: React.FC<{
  role: "user" | "assistant";
  text: string;
  bullets?: readonly string[];
  time?: string;
}> = ({ role, text, bullets, time }) => {
  const user = role === "user";
  return (
    <div style={{ padding: "8px 20px", display: "flex", flexDirection: "column", alignItems: user ? "flex-end" : "stretch" }}>
      <div
        style={{
          maxWidth: user ? "82%" : "100%",
          backgroundColor: user ? tokens.bubble : "transparent",
          borderRadius: user ? tokens.radius.card : 0,
          padding: user ? "10px 14px" : 0,
          fontFamily: fontFamilies.inter,
          fontSize: tokens.font.body,
          lineHeight: 1.45,
          color: tokens.text,
        }}
      >
        {text}
        {bullets && bullets.length > 0 && (
          <ul style={{ margin: "8px 0 0", paddingLeft: 20 }}>
            {bullets.map((b) => (
              <li key={b} style={{ marginBottom: 4 }}>{b}</li>
            ))}
          </ul>
        )}
      </div>
      {time && (
        <span style={{ fontFamily: fontFamilies.inter, fontSize: tokens.font.micro, color: tokens.meta, marginTop: 5 }}>
          {time}
        </span>
      )}
    </div>
  );
};
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `npm test -- src/ui/widget/ProductCard.test.tsx`
Expected: PASS, 5 tests.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(widget): messages and the horizontal product card carousel"
```

---

## Task 10: Storefront scenes 1–7

**Files:**
- Create: `src/scenes/Scene01ColdOpen.tsx` … `src/scenes/Scene07Cart.tsx`
- Create: `src/scenes/storefrontCursor.ts`
- Modify: `src/Video.tsx`
- Test: `src/scenes/storefrontCursor.test.ts`

**Interfaces:**
- Consumes: everything from Tasks 1–9
- Produces: `STOREFRONT_CURSOR: CursorKey[]` in viewport coordinates, and seven scene components each taking no props.

- [ ] **Step 1: Write the failing cursor-path test**

The cursor must be at the disc when the panel opens, at the composer when typing starts, and at the Trail Helmet's Add to cart when it is clicked. Assert those, so a later layout change cannot silently desynchronise them.

Create `src/scenes/storefrontCursor.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { cursorAt } from "../lib/cursor";
import { FPS, beat } from "../timeline";
import { DISC_POINT, COMPOSER_POINT, ADD_TO_CART_POINT, STOREFRONT_CURSOR } from "./storefrontCursor";

const near = (a: number, b: number, tol = 24) => Math.abs(a - b) <= tol;

describe("STOREFRONT_CURSOR", () => {
  it("clicks exactly three times: disc, send, add to cart", () => {
    expect(STOREFRONT_CURSOR.filter((k) => k.click)).toHaveLength(3);
  });

  it("is on the disc when the panel-open beat begins", () => {
    const at = cursorAt(STOREFRONT_CURSOR, beat(2).from);
    expect(near(at.x, DISC_POINT.x)).toBe(true);
    expect(near(at.y, DISC_POINT.y)).toBe(true);
  });

  it("is on the composer while the question is being typed", () => {
    const mid = beat(3).from + Math.floor(beat(3).durationInFrames / 2);
    const at = cursorAt(STOREFRONT_CURSOR, mid);
    expect(near(at.x, COMPOSER_POINT.x, 60)).toBe(true);
  });

  it("is on Add to cart when the cart beat clicks", () => {
    const click = STOREFRONT_CURSOR.filter((k) => k.click).at(-1)!;
    expect(click.frame).toBeGreaterThanOrEqual(beat(7).from);
    expect(click.frame).toBeLessThan(beat(7).from + beat(7).durationInFrames);
    expect(near(click.x, ADD_TO_CART_POINT.x)).toBe(true);
    expect(near(click.y, ADD_TO_CART_POINT.y)).toBe(true);
  });

  it("never leaves the viewport", () => {
    for (let f = 0; f <= beat(7).from + beat(7).durationInFrames; f += 5) {
      const at = cursorAt(STOREFRONT_CURSOR, f);
      expect(at.x).toBeGreaterThanOrEqual(0);
      expect(at.x).toBeLessThanOrEqual(1600);
      expect(at.y).toBeGreaterThanOrEqual(0);
      expect(at.y).toBeLessThanOrEqual(900);
    }
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npm test -- src/scenes/storefrontCursor.test.ts`
Expected: FAIL — `Cannot find module './storefrontCursor'`.

- [ ] **Step 3: Define the cursor path**

Points are in viewport coordinates (1600×900). Derive them from `PANEL_BOX` and `tokens` rather than hardcoding twice.

Create `src/scenes/storefrontCursor.ts`:

```ts
import type { CursorKey } from "../lib/cursor";
import { FPS, beat } from "../timeline";
import { tokens } from "../theme/tokens";
import { PANEL_BOX } from "../ui/widget/Panel";
import { CARD_WIDTH } from "../ui/widget/ProductCard";

const VW = 1600;
const VH = 900;

export const DISC_POINT = {
  x: VW - tokens.orbInset - tokens.orbSize / 2,
  y: VH - tokens.orbInset - tokens.orbSize / 2,
};

const PANEL_LEFT = VW - PANEL_BOX.right - PANEL_BOX.width;

export const COMPOSER_POINT = { x: PANEL_LEFT + 140, y: PANEL_BOX.top + PANEL_BOX.height - 42 };
export const SEND_POINT = { x: PANEL_LEFT + PANEL_BOX.width - 42, y: PANEL_BOX.top + PANEL_BOX.height - 42 };
// First card in the carousel; Add to cart is the lower of its two actions.
export const ADD_TO_CART_POINT = { x: PANEL_LEFT + 20 + CARD_WIDTH / 2, y: PANEL_BOX.top + 604 };

const s = (seconds: number) => Math.round(seconds * FPS);

export const STOREFRONT_CURSOR: CursorKey[] = [
  { frame: 0, x: VW * 0.42, y: VH * 0.5 },
  { frame: beat(2).from - s(0.6), x: DISC_POINT.x, y: DISC_POINT.y },
  { frame: beat(2).from, x: DISC_POINT.x, y: DISC_POINT.y, click: true },
  { frame: beat(3).from - s(0.4), x: COMPOSER_POINT.x, y: COMPOSER_POINT.y },
  { frame: beat(3).from + beat(3).durationInFrames - s(0.9), x: COMPOSER_POINT.x, y: COMPOSER_POINT.y },
  { frame: beat(3).from + beat(3).durationInFrames - s(0.2), x: SEND_POINT.x, y: SEND_POINT.y, click: true },
  { frame: beat(7).from + s(1.2), x: ADD_TO_CART_POINT.x, y: ADD_TO_CART_POINT.y },
  { frame: beat(7).from + s(2.0), x: ADD_TO_CART_POINT.x, y: ADD_TO_CART_POINT.y, click: true },
  { frame: beat(8).from, x: ADD_TO_CART_POINT.x, y: ADD_TO_CART_POINT.y },
];
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `npm test -- src/scenes/storefrontCursor.test.ts`
Expected: PASS, 5 tests.

If "is on the composer" fails, the composer point is derived from `PANEL_BOX.height`; check that `PANEL_BOX.top + PANEL_BOX.height` is inside the viewport (24 + 760 = 784 < 900 ✓).

- [ ] **Step 5: Build a single storefront stage component**

All seven storefront beats share one stage; only the widget's state changes. Building it once avoids seven near-copies drifting apart.

Create `src/scenes/StorefrontStage.tsx`:

```tsx
import { AbsoluteFill } from "remotion";
import { BrowserFrame } from "../ui/chrome/BrowserFrame";
import { Storefront } from "../ui/shop/Storefront";
import { Disc } from "../ui/widget/Disc";
import { Nudge } from "../ui/widget/Nudge";
import { Panel } from "../ui/widget/Panel";
import { Composer } from "../ui/widget/Composer";
import { tokens } from "../theme/tokens";

export type StageState = {
  cartTotal: string;
  nudgeOpacity: number;
  panelProgress: number;
  composerValue: string;
  composerFocused: boolean;
  composerSent: boolean;
  log: React.ReactNode;
};

export const StorefrontStage: React.FC<StageState> = (s) => (
  <AbsoluteFill>
    <BrowserFrame url="sidepath.shop">
      <Storefront cartTotal={s.cartTotal} />
      {s.nudgeOpacity > 0 && <Nudge opacity={s.nudgeOpacity} />}
      <div style={{ position: "absolute", right: tokens.orbInset, bottom: tokens.orbInset }}>
        <Disc />
      </div>
      {s.panelProgress > 0 && (
        <Panel progress={s.panelProgress}>
          {s.log}
          <Composer value={s.composerValue} focused={s.composerFocused} sent={s.composerSent} />
        </Panel>
      )}
    </BrowserFrame>
  </AbsoluteFill>
);
```

- [ ] **Step 6: Write scenes 1 through 7**

Each scene computes its own `StageState` from its local frame. Create `src/scenes/Scene01ColdOpen.tsx`:

```tsx
import { interpolate, useCurrentFrame } from "remotion";
import { StorefrontStage } from "./StorefrontStage";
import { CART_TOTAL_BEFORE } from "../data/catalogue";

export const Scene01ColdOpen: React.FC = () => {
  const f = useCurrentFrame();
  return (
    <StorefrontStage
      cartTotal={CART_TOTAL_BEFORE}
      nudgeOpacity={interpolate(f, [18, 30, 96, 108], [0, 1, 1, 0], {
        extrapolateLeft: "clamp",
        extrapolateRight: "clamp",
      })}
      panelProgress={0}
      composerValue=""
      composerFocused={false}
      composerSent={false}
      log={null}
    />
  );
};
```

Create `src/scenes/Scene02OpenPanel.tsx`:

```tsx
import { spring, useCurrentFrame, useVideoConfig } from "remotion";
import { StorefrontStage } from "./StorefrontStage";
import { CART_TOTAL_BEFORE } from "../data/catalogue";
import { Message } from "../ui/widget/Message";
import { GREETING, SUGGESTIONS } from "../data/conversation";
import { Suggestions } from "../ui/widget/Suggestions";

export const Scene02OpenPanel: React.FC = () => {
  const f = useCurrentFrame();
  const { fps } = useVideoConfig();
  const progress = spring({ frame: f, fps, config: { damping: 200 }, durationInFrames: 18 });
  return (
    <StorefrontStage
      cartTotal={CART_TOTAL_BEFORE}
      nudgeOpacity={0}
      panelProgress={progress}
      composerValue=""
      composerFocused={false}
      composerSent={false}
      log={
        <>
          <Message role="assistant" text={GREETING} />
          <Suggestions items={SUGGESTIONS} />
        </>
      }
    />
  );
};
```

Create `src/ui/widget/Suggestions.tsx`:

```tsx
import { tokens } from "../../theme/tokens";
import { sidepath } from "../../theme/sidepath";
import { fontFamilies } from "../../fonts";

export const Suggestions: React.FC<{ items: readonly string[] }> = ({ items }) => (
  <div style={{ display: "flex", gap: 8, padding: "4px 20px 0", flexWrap: "wrap" }}>
    {items.map((i) => (
      <span
        key={i}
        style={{
          border: `1px solid ${sidepath.teal}`,
          color: sidepath.teal,
          borderRadius: tokens.radius.pill,
          padding: "6px 12px",
          fontFamily: fontFamilies.inter,
          fontSize: tokens.font.meta,
          fontWeight: 600,
        }}
      >
        {i}
      </span>
    ))}
  </div>
);
```

Create `src/scenes/Scene03Question.tsx`:

```tsx
import { useCurrentFrame } from "remotion";
import { StorefrontStage } from "./StorefrontStage";
import { CART_TOTAL_BEFORE } from "../data/catalogue";
import { Message } from "../ui/widget/Message";
import { GREETING, QUESTION, SUGGESTIONS, TYPING_CPS } from "../data/conversation";
import { Suggestions } from "../ui/widget/Suggestions";
import { typedLength } from "../lib/typewriter";

export const Scene03Question: React.FC = () => {
  const f = useCurrentFrame();
  const typed = QUESTION.slice(0, typedLength(QUESTION, f, 6, TYPING_CPS));
  return (
    <StorefrontStage
      cartTotal={CART_TOTAL_BEFORE}
      nudgeOpacity={0}
      panelProgress={1}
      composerValue={typed}
      composerFocused
      composerSent={false}
      log={
        <>
          <Message role="assistant" text={GREETING} />
          <Suggestions items={SUGGESTIONS} />
        </>
      }
    />
  );
};
```

Create `src/scenes/Scene04Thinking.tsx`:

```tsx
import { StorefrontStage } from "./StorefrontStage";
import { CART_TOTAL_BEFORE } from "../data/catalogue";
import { Message } from "../ui/widget/Message";
import { QUESTION } from "../data/conversation";
import { Thinking } from "../ui/widget/Thinking";

export const Scene04Thinking: React.FC = () => (
  <StorefrontStage
    cartTotal={CART_TOTAL_BEFORE}
    nudgeOpacity={0}
    panelProgress={1}
    composerValue=""
    composerFocused={false}
    composerSent
    log={
      <>
        <Message role="user" text={QUESTION} time="16:03" />
        <Thinking />
      </>
    }
  />
);
```

Create `src/scenes/Scene05Answer.tsx`:

```tsx
import { interpolate, useCurrentFrame } from "remotion";
import { StorefrontStage } from "./StorefrontStage";
import { CART_TOTAL_BEFORE, CARDS } from "../data/catalogue";
import { Message } from "../ui/widget/Message";
import { QUESTION, REPLY_BULLETS, REPLY_CPS, REPLY_PARAGRAPH } from "../data/conversation";
import { CardCarousel } from "../ui/widget/CardCarousel";
import { typedLength } from "../lib/typewriter";
import { beat } from "../timeline";

const FULL = REPLY_PARAGRAPH + "\n" + REPLY_BULLETS.join("\n");

export const Scene05Answer: React.FC = () => {
  const f = useCurrentFrame();
  const n = typedLength(FULL, f, 0, REPLY_CPS);
  const paragraph = REPLY_PARAGRAPH.slice(0, n);
  const remaining = Math.max(0, n - REPLY_PARAGRAPH.length);
  const bullets = REPLY_BULLETS.filter((_, i) => remaining > i * 110);
  const cardsProgress = interpolate(f, [beat(5).durationInFrames * 0.42, beat(5).durationInFrames], [0, 1], {
    extrapolateLeft: "clamp",
    extrapolateRight: "clamp",
  });

  return (
    <StorefrontStage
      cartTotal={CART_TOTAL_BEFORE}
      nudgeOpacity={0}
      panelProgress={1}
      composerValue=""
      composerFocused={false}
      composerSent
      log={
        <>
          <Message role="user" text={QUESTION} time="16:03" />
          <Message role="assistant" text={paragraph} bullets={bullets} />
          {cardsProgress > 0 && <CardCarousel cards={CARDS} progress={cardsProgress} />}
        </>
      }
    />
  );
};
```

Create `src/scenes/Scene06Claim.tsx` — the full answer plus the annotation overlay:

```tsx
import { interpolate, useCurrentFrame } from "remotion";
import { StorefrontStage } from "./StorefrontStage";
import { CART_TOTAL_BEFORE, CARDS } from "../data/catalogue";
import { Message } from "../ui/widget/Message";
import { QUESTION, REPLY_BULLETS, REPLY_PARAGRAPH } from "../data/conversation";
import { CardCarousel } from "../ui/widget/CardCarousel";
import { Annotation } from "../ui/chrome/Annotation";

export const Scene06Claim: React.FC = () => {
  const f = useCurrentFrame();
  const reveal = interpolate(f, [4, 20], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  return (
    <>
      <StorefrontStage
        cartTotal={CART_TOTAL_BEFORE}
        nudgeOpacity={0}
        panelProgress={1}
        composerValue=""
        composerFocused={false}
        composerSent
        log={
          <>
            <Message role="user" text={QUESTION} time="16:03" />
            <Message role="assistant" text={REPLY_PARAGRAPH} bullets={REPLY_BULLETS} />
            <CardCarousel cards={CARDS} progress={1} />
          </>
        }
      />
      <Annotation
        reveal={reveal}
        text="Rendered from the catalogue. The model only returns a product ID."
      />
    </>
  );
};
```

Create `src/ui/chrome/Annotation.tsx`:

```tsx
import { interpolate } from "remotion";
import { tokens } from "../../theme/tokens";
import { fontFamilies } from "../../fonts";
import { VIEWPORT } from "./BrowserFrame";
import { PANEL_BOX } from "../widget/Panel";

// Targets in composition space: the Trail Helmet's price/stock and the Gravel
// Helmet's Low stock pill. Both derive from PANEL_BOX so they follow the panel.
const PANEL_LEFT = VIEWPORT.x + VIEWPORT.width - PANEL_BOX.right - PANEL_BOX.width;
const TARGETS = [
  { x: PANEL_LEFT + 60, y: VIEWPORT.y + PANEL_BOX.top + 520 },
  { x: PANEL_LEFT + 250, y: VIEWPORT.y + PANEL_BOX.top + 548 },
];

export const Annotation: React.FC<{ reveal: number; text: string }> = ({ reveal, text }) => {
  const labelX = PANEL_LEFT - 330;
  const labelY = VIEWPORT.y + PANEL_BOX.top + 500;

  return (
    <>
      <svg style={{ position: "absolute", inset: 0 }} width="1920" height="1080">
        {TARGETS.map((t, i) => (
          <line
            key={i}
            x1={labelX + 300}
            y1={labelY + 30}
            x2={interpolate(reveal, [0, 1], [labelX + 300, t.x])}
            y2={interpolate(reveal, [0, 1], [labelY + 30, t.y])}
            stroke={tokens.text}
            strokeWidth={1.5}
            opacity={0.55 * reveal}
          />
        ))}
        {TARGETS.map((t, i) => (
          <circle key={`d${i}`} cx={t.x} cy={t.y} r={4 * reveal} fill={tokens.text} opacity={0.7 * reveal} />
        ))}
      </svg>
      <div
        style={{
          position: "absolute",
          left: labelX,
          top: labelY,
          width: 300,
          fontFamily: fontFamilies.inter,
          fontSize: 22,
          fontWeight: 600,
          lineHeight: 1.35,
          color: tokens.text,
          opacity: reveal,
          textAlign: "right",
        }}
      >
        {text}
      </div>
    </>
  );
};
```

Create `src/scenes/Scene07Cart.tsx`:

```tsx
import { interpolate, useCurrentFrame } from "remotion";
import { StorefrontStage } from "./StorefrontStage";
import { CARDS, CART_TOTAL_AFTER, CART_TOTAL_BEFORE } from "../data/catalogue";
import { Message } from "../ui/widget/Message";
import { QUESTION, REPLY_BULLETS, REPLY_PARAGRAPH } from "../data/conversation";
import { CardCarousel } from "../ui/widget/CardCarousel";
import { FPS } from "../timeline";

const CLICK_FRAME = Math.round(2.0 * FPS); // matches STOREFRONT_CURSOR's last click

export const Scene07Cart: React.FC = () => {
  const f = useCurrentFrame();
  const added = f >= CLICK_FRAME;
  return (
    <StorefrontStage
      cartTotal={added ? CART_TOTAL_AFTER : CART_TOTAL_BEFORE}
      nudgeOpacity={0}
      panelProgress={1}
      composerValue=""
      composerFocused={false}
      composerSent
      log={
        <>
          <Message role="user" text={QUESTION} time="16:03" />
          <Message role="assistant" text={REPLY_PARAGRAPH} bullets={REPLY_BULLETS} />
          <CardCarousel cards={CARDS} progress={1} addedId={added ? CARDS[0].id : undefined} />
        </>
      }
    />
  );
};
```

- [ ] **Step 7: Wire scenes 1–7 into the video**

Replace `src/Video.tsx`:

```tsx
import { AbsoluteFill, Sequence } from "remotion";
import { BEATS, beat } from "./timeline";
import { Backdrop } from "./ui/chrome/Backdrop";
import { Cursor } from "./ui/chrome/Cursor";
import { Caption } from "./ui/chrome/Caption";
import { STOREFRONT_CURSOR } from "./scenes/storefrontCursor";
import { Scene01ColdOpen } from "./scenes/Scene01ColdOpen";
import { Scene02OpenPanel } from "./scenes/Scene02OpenPanel";
import { Scene03Question } from "./scenes/Scene03Question";
import { Scene04Thinking } from "./scenes/Scene04Thinking";
import { Scene05Answer } from "./scenes/Scene05Answer";
import { Scene06Claim } from "./scenes/Scene06Claim";
import { Scene07Cart } from "./scenes/Scene07Cart";

const SCENES = [
  Scene01ColdOpen,
  Scene02OpenPanel,
  Scene03Question,
  Scene04Thinking,
  Scene05Answer,
  Scene06Claim,
  Scene07Cart,
];

export const Video: React.FC = () => (
  <AbsoluteFill>
    <Backdrop>
      {SCENES.map((Scene, i) => {
        const b = beat(i + 1);
        return (
          <Sequence key={b.id} from={b.from} durationInFrames={b.durationInFrames} premountFor={30}>
            <Scene />
          </Sequence>
        );
      })}
      <Caption text="Their real cart. Your normal checkout." from={beat(7).from + 70} durationInFrames={100} />
      <Cursor keys={STOREFRONT_CURSOR} />
    </Backdrop>
  </AbsoluteFill>
);
```

- [ ] **Step 8: Review beats 1–7 in the studio**

Run: `npx remotion studio`
Check: the nudge appears and retracts; the panel springs open on the click; typing lands character by character and Send fires at the end of beat 3; the reply streams and the four cards stagger in; the annotation lines reach the price and the Low stock pill; the cart ticks to €89.00 exactly when the cursor clicks.

- [ ] **Step 9: Run the whole suite and commit**

```bash
npm test
git add -A
git commit -m "feat(scenes): storefront beats 1-7 with a verified cursor path"
```

---

## Task 11: The Administration replica and scenes 8–10

**Files:**
- Create: `src/ui/admin/AdminShell.tsx`, `Sidebar.tsx`, `SmartBar.tsx`, `OutcomeFilter.tsx`, `DataGrid.tsx`, `TurnCard.tsx`, `PhaseTimeline.tsx`
- Create: `src/theme/admin.ts`
- Create: `src/scenes/Scene08Turn.tsx`, `Scene09TraceList.tsx`, `Scene10TraceDetail.tsx`
- Create: `src/scenes/adminCursor.ts`
- Modify: `src/Video.tsx`
- Test: `src/ui/admin/DataGrid.test.tsx`, `src/ui/admin/PhaseTimeline.test.tsx`

**Interfaces:**
- Consumes: `LIST_COLUMNS`, `LIST_ROWS`, `TURN`, `CONVERSATION_COUNT`, `adminTokens`
- Produces: `<AdminShell title children>`, `<DataGrid highlightRow?: number>`, `<TurnCard rowsRevealed: number>`, `ADMIN_CURSOR: CursorKey[]`

- [ ] **Step 1: Write the failing admin tests**

Create `src/ui/admin/DataGrid.test.tsx`:

```tsx
import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { DataGrid } from "./DataGrid";
import { LIST_COLUMNS, LIST_ROWS } from "../../data/trace";

describe("DataGrid", () => {
  it("renders the Administration's eight column headers in order", () => {
    render(<DataGrid />);
    const headers = screen.getAllByRole("columnheader").map((h) => h.textContent);
    expect(headers).toEqual([...LIST_COLUMNS]);
  });

  it("renders every row it is given", () => {
    render(<DataGrid />);
    expect(screen.getAllByRole("row")).toHaveLength(LIST_ROWS.length + 1);
  });

  it("shows the real conversation count", () => {
    render(<DataGrid />);
    expect(screen.getByText("All 107 conversations")).toBeTruthy();
  });
});
```

Create `src/ui/admin/PhaseTimeline.test.tsx`:

```tsx
import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { PhaseTimeline } from "./PhaseTimeline";
import { TURN } from "../../data/trace";

describe("PhaseTimeline", () => {
  it("reveals only the rows it is told to", () => {
    render(<PhaseTimeline rowsRevealed={3} />);
    expect(screen.getAllByTestId("trace-row")).toHaveLength(3);
  });

  it("renders every row when fully revealed", () => {
    render(<PhaseTimeline rowsRevealed={TURN.rows.length} />);
    expect(screen.getAllByTestId("trace-row")).toHaveLength(TURN.rows.length);
  });

  it("marks the waiting rows so they can be emphasised", () => {
    render(<PhaseTimeline rowsRevealed={TURN.rows.length} />);
    const waits = screen.getAllByTestId("trace-row").filter((r) =>
      r.getAttribute("data-kind") === "wait",
    );
    expect(waits.length).toBeGreaterThanOrEqual(1);
    expect(waits[0].textContent).toContain("waiting on the model");
  });
});
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `npm test -- src/ui/admin`
Expected: FAIL — modules not found.

- [ ] **Step 3: Write the admin tokens**

Create `src/theme/admin.ts`, from `reference/admin-trace-list-1920.png`:

```ts
export const adminTokens = {
  sidebarBg: "#22375a",
  sidebarWidth: 80,
  canvas: "#f9fafb",
  surface: "#ffffff",
  border: "#d1d9e0",
  text: "#202124",
  meta: "#758ca3",
  primary: "#189eff",
  rowHeight: 42,
  headerHeight: 56,
  smartBarHeight: 60,
  topBarHeight: 80,
} as const;
```

- [ ] **Step 4: Implement the shell, grid and timeline**

Create `src/ui/admin/DataGrid.tsx`:

```tsx
import { adminTokens } from "../../theme/admin";
import { fontFamilies } from "../../fonts";
import { CONVERSATION_COUNT, LIST_COLUMNS, LIST_ROWS } from "../../data/trace";

const WIDTHS = [240, 210, 100, 70, 130, 380, 240, 90];

export const DataGrid: React.FC<{ highlightRow?: number }> = ({ highlightRow }) => (
  <div style={{ fontFamily: fontFamilies.inter, fontSize: 13, color: adminTokens.text }}>
    <div
      style={{
        backgroundColor: adminTokens.surface,
        border: `1px solid ${adminTokens.border}`,
        borderRadius: 4,
        padding: "18px 22px",
        margin: "0 0 22px",
        display: "flex",
        alignItems: "center",
      }}
    >
      <span style={{ fontSize: 16, fontWeight: 600 }}>Filter by outcome</span>
      <div style={{ flex: 1 }} />
      <span style={{ color: adminTokens.meta, marginRight: 14 }}>{CONVERSATION_COUNT}</span>
      <span style={{ border: `1px solid ${adminTokens.border}`, borderRadius: 4, padding: "7px 14px", fontWeight: 600 }}>
        Export traces
      </span>
    </div>

    <table role="table" style={{ width: "100%", borderCollapse: "collapse", backgroundColor: adminTokens.surface }}>
      <thead>
        <tr role="row">
          {LIST_COLUMNS.map((c, i) => (
            <th
              key={c}
              role="columnheader"
              style={{
                width: WIDTHS[i],
                height: adminTokens.headerHeight,
                textAlign: "left",
                padding: "0 14px",
                fontWeight: 600,
                borderBottom: `1px solid ${adminTokens.border}`,
                borderTop: `1px solid ${adminTokens.border}`,
                whiteSpace: "nowrap",
              }}
            >
              {c}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {LIST_ROWS.map((r, i) => (
          <tr
            role="row"
            key={r.started}
            style={{
              backgroundColor: highlightRow === i ? "#eef4ff" : i % 2 ? "#fbfcfd" : adminTokens.surface,
            }}
          >
            {[r.started, r.salesChannel, r.user, String(r.turns), r.outcome, r.question, r.reply, r.duration].map(
              (cell, j) => (
                <td
                  key={j}
                  style={{
                    height: adminTokens.rowHeight,
                    padding: "0 14px",
                    borderBottom: `1px solid ${adminTokens.border}`,
                    whiteSpace: "nowrap",
                    overflow: "hidden",
                    textOverflow: "ellipsis",
                    maxWidth: WIDTHS[j],
                  }}
                >
                  {cell}
                </td>
              ),
            )}
          </tr>
        ))}
      </tbody>
    </table>
  </div>
);
```

Create `src/ui/admin/PhaseTimeline.tsx`:

```tsx
import { adminTokens } from "../../theme/admin";
import { fontFamilies } from "../../fonts";
import { TURN } from "../../data/trace";

export const PhaseTimeline: React.FC<{ rowsRevealed: number }> = ({ rowsRevealed }) => (
  <ol style={{ listStyle: "none", margin: 0, padding: 0, fontFamily: fontFamilies.inter, fontSize: 14 }}>
    {TURN.rows.slice(0, rowsRevealed).map((r, i) => (
      <li
        key={i}
        data-testid="trace-row"
        data-kind={r.kind}
        style={{
          display: "flex",
          alignItems: "baseline",
          gap: 18,
          padding: "9px 0",
          color: adminTokens.text,
        }}
      >
        <span style={{ width: 78, fontFamily: fontFamilies.plex, fontSize: 12, color: adminTokens.meta }}>
          {r.at}
        </span>
        <span style={{ flex: 1 }}>
          {r.kind === "wait" ? (
            <em style={{ color: adminTokens.meta }}>{r.label}</em>
          ) : (
            <>
              <strong>{r.label}</strong>
              {r.fact && (
                <span style={{ color: adminTokens.meta, marginLeft: 12 }}>
                  {r.fact} <strong style={{ color: adminTokens.text }}>{r.factValue}</strong>
                </span>
              )}
            </>
          )}
        </span>
        <span
          style={{
            fontFamily: fontFamilies.plex,
            fontSize: 12,
            color: r.kind === "wait" ? adminTokens.text : adminTokens.meta,
            fontWeight: r.kind === "wait" ? 700 : 400,
          }}
        >
          {r.took}
        </span>
      </li>
    ))}
  </ol>
);
```

Create `src/ui/admin/AdminShell.tsx` (sidebar, top search bar, smart bar) and `src/ui/admin/TurnCard.tsx` (the `Turn 1` card with the `14.8 s / shop 263 ms · model 14.5 s` header, SHOPPER and ASSISTANT prose, the `Cards shown:` monospace line, then `<PhaseTimeline>`), matching `reference/admin-trace-detail-1920.png`.

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `npm test -- src/ui/admin`
Expected: PASS, 6 tests.

- [ ] **Step 6: Write the admin cursor and scenes 8–10**

`Scene08Turn` cross-dissolves the storefront to the admin using `TransitionSeries` with `fade()` and morphs the URL pill from `sidepath.shop` to `sidepath.shop/admin`. `Scene09TraceList` renders `AdminShell` + `DataGrid`, with `ADMIN_CURSOR` travelling to and clicking the first row. `Scene10TraceDetail` renders `AdminShell` + `TurnCard`, revealing rows with:

```tsx
const rowsRevealed = Math.floor(
  interpolate(f, [10, beat(10).durationInFrames - 40], [0, TURN.rows.length], {
    extrapolateLeft: "clamp",
    extrapolateRight: "clamp",
  }),
);
```

Add the caption *"Including the time you don't control."* over the last third of beat 10.

- [ ] **Step 7: Review beats 8–10 against the reference**

```bash
npx remotion still ShoppingAssistant out/check-list.png --frame=1500
npx remotion still ShoppingAssistant out/check-detail.png --frame=1800
open out/check-list.png reference/admin-trace-list-1920.png
open out/check-detail.png reference/admin-trace-detail-1920.png
```

Fix row heights, header weight and border colour until they match. This is the beat most likely to be subtly wrong.

- [ ] **Step 8: Commit**

```bash
npm test
git add -A
git commit -m "feat(admin): trace list and trace detail replica, beats 8-10"
```

---

## Task 12: Code beat, end card, audio, and the render

**Files:**
- Create: `src/ui/chrome/CodePane.tsx`, `src/ui/chrome/EndCard.tsx`
- Create: `src/scenes/Scene11CodeBeat.tsx`, `src/scenes/Scene12EndCard.tsx`
- Create: `src/Audio.tsx`, `scripts/stills.mjs`
- Modify: `src/Video.tsx`
- Test: `src/Audio.test.ts`

**Interfaces:**
- Consumes: everything above
- Produces: `<Soundtrack />`, `ALL_CUES: SfxCue[]`, and the final MP4.

- [ ] **Step 1: Write the failing audio-schedule test**

Create `src/Audio.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { ALL_CUES, MUSIC_TRIM_BEFORE_FRAMES } from "./Audio";
import { TOTAL_FRAMES, FPS } from "./timeline";

describe("soundtrack", () => {
  it("trims the music to the 9.0s window start", () => {
    expect(MUSIC_TRIM_BEFORE_FRAMES).toBe(Math.round(9.0 * FPS));
  });

  it("keeps every cue inside the composition", () => {
    for (const c of ALL_CUES) {
      expect(c.frame).toBeGreaterThanOrEqual(0);
      expect(c.frame).toBeLessThan(TOTAL_FRAMES);
    }
  });

  it("has exactly three click cues — disc, send, add to cart", () => {
    expect(ALL_CUES.filter((c) => c.sound === "click")).toHaveLength(3);
  });

  it("has one whoosh, on the beat 8 transition", () => {
    expect(ALL_CUES.filter((c) => c.sound === "whoosh")).toHaveLength(1);
  });

  it("has one cart confirmation", () => {
    expect(ALL_CUES.filter((c) => c.sound === "cart")).toHaveLength(1);
  });

  it("has keystroke cues from the typed question, thinned", () => {
    const keys = ALL_CUES.filter((c) => c.sound === "key");
    expect(keys.length).toBeGreaterThan(5);
    expect(keys.length).toBeLessThan(50);
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npm test -- src/Audio.test.ts`
Expected: FAIL — `Cannot find module './Audio'`.

- [ ] **Step 3: Implement the soundtrack**

Create `src/Audio.tsx`:

```tsx
import { Audio } from "@remotion/media";
import { Sequence, interpolate, staticFile } from "remotion";
import { FPS, TOTAL_FRAMES, beat } from "./timeline";
import { QUESTION, TYPING_CPS } from "./data/conversation";
import { sfxFromCursor, sfxFromTyping, type SfxCue } from "./lib/sfx";
import { STOREFRONT_CURSOR } from "./scenes/storefrontCursor";
import { ADMIN_CURSOR } from "./scenes/adminCursor";

export const MUSIC_TRIM_BEFORE_FRAMES = Math.round(9.0 * FPS);

export const ALL_CUES: SfxCue[] = [
  ...sfxFromCursor(STOREFRONT_CURSOR),
  ...sfxFromCursor(ADMIN_CURSOR),
  ...sfxFromTyping(QUESTION, beat(3).from + 6, TYPING_CPS, 3),
  { frame: beat(8).from, sound: "whoosh" },
  { frame: beat(7).from + Math.round(2.0 * FPS), sound: "cart" },
].filter((c) => c.frame >= 0 && c.frame < TOTAL_FRAMES);

export const Soundtrack: React.FC = () => (
  <>
    <Audio
      src={staticFile("assets/audio/music.mp3")}
      trimBefore={MUSIC_TRIM_BEFORE_FRAMES}
      trimAfter={MUSIC_TRIM_BEFORE_FRAMES + TOTAL_FRAMES}
      volume={(f) =>
        interpolate(
          f,
          [0, 1.5 * FPS, TOTAL_FRAMES - 3 * FPS, TOTAL_FRAMES],
          [0, 0.72, 0.72, 0],
          { extrapolateLeft: "clamp", extrapolateRight: "clamp" },
        )
      }
    />
    {ALL_CUES.map((c, i) => (
      <Sequence key={i} from={c.frame} durationInFrames={2 * FPS} premountFor={FPS} layout="none">
        <Audio src={staticFile(`assets/audio/${c.sound}.wav`)} volume={c.sound === "key" ? 0.34 : 0.5} />
      </Sequence>
    ))}
  </>
);
```

Note: `ADMIN_CURSOR` must exist from Task 11. If it does not, that task is incomplete — do not stub it here.

- [ ] **Step 4: Run the test to confirm it passes**

Run: `npm test -- src/Audio.test.ts`
Expected: PASS, 6 tests.

- [ ] **Step 5: Implement the code beat and end card**

`CodePane` is a dark editor surface with per-line reveal (never per character — 10 lines of PHP in 5 seconds is not typeable at a believable speed). The snippet:

```php
final class FindCompatiblePartsToolFactory implements GroundedToolFactoryInterface
{
    public function create(CatalogueScope $scope): Tool
    {
        return new Tool(
            name: 'find_compatible_parts',
            description: 'Parts that fit a given bike model and year.',
            handler: fn (string $model, int $year) => $this->fitment->for($model, $year),
        );
    }
}
```

Below it, the DI tag, revealed last:

```yaml
SwagAssistantStarterKit\Tool\FindCompatiblePartsToolFactory:
    tags: [ 'swag_assistant.grounded_tool_factory' ]
```

Then hard-cut back to the panel with the question *"will these pads fit my 2019 frame?"* answered. Caption: *"Your own tools. Your own prompt. Your own model."*

`EndCard` renders on `tokens.text` (Night Blue): the white Shopware lockup, "Shopping Assistant" at 64px, then three lines — *Grounded in your catalogue* · *Observable in your admin* · *Extensible in your code* — staggered 6 frames apart, and a footer *Research preview · Agentic Commerce Lab* at `tokens.font.meta`.

- [ ] **Step 6: Wire all twelve scenes plus the soundtrack**

In `src/Video.tsx`, extend the `SCENES` array to all twelve components and add `<Soundtrack />` inside the outermost `AbsoluteFill`, outside `<Backdrop>`. Switch the cursor so `STOREFRONT_CURSOR` renders during beats 1–7 and `ADMIN_CURSOR` during beats 9–10, each inside its own premounted `<Sequence>`.

- [ ] **Step 7: Add the stills script**

Create `scripts/stills.mjs`:

```js
import { execSync } from "node:child_process";
import { mkdirSync } from "node:fs";

const BOUNDARIES = [0, 4, 7, 13, 18, 28, 33, 41, 45, 55, 65, 74, 80];
mkdirSync("out/stills", { recursive: true });

BOUNDARIES.slice(0, -1).forEach((from, i) => {
  const centre = Math.round(((from + BOUNDARIES[i + 1]) / 2) * 30);
  const name = `out/stills/beat-${String(i + 1).padStart(2, "0")}-f${centre}.png`;
  console.log(`beat ${i + 1} -> frame ${centre}`);
  execSync(`npx remotion still ShoppingAssistant ${name} --frame=${centre}`, { stdio: "inherit" });
});
```

- [ ] **Step 8: Render the stills and review every beat**

```bash
node scripts/stills.mjs
open out/stills/
```

Compare each still against its reference capture. **Do not proceed to the full render until every beat has been reviewed.** Beats 9 and 10 get the closest look.

- [ ] **Step 9: Verify the audio lands on frame**

Run: `npx remotion studio`
Scrub beat 3 (keystrokes match characters appearing), beat 7 (the cart sound is on the click), beat 8 (the whoosh is on the cut, and the music's arrangement change is audible under it).

- [ ] **Step 10: Full render**

```bash
mkdir -p out
npx remotion render ShoppingAssistant out/shopping-assistant-1080p30.mp4 \
  --codec=h264 --crf=18
ffprobe -v error -show_entries format=duration:stream=width,height,r_frame_rate -of default=nw=1 out/shopping-assistant-1080p30.mp4
```

Expected: duration ≈ 80.0s, 1920×1080, 30/1.

- [ ] **Step 11: Final suite and commit**

```bash
npm test
git add -A
git commit -m "feat: code beat, end card, soundtrack, and the 80s render"
```

---

## Self-Review

**Spec coverage.** Every spec section maps to a task: purpose and constraints → Task 2's timeline; Sidepath identity → Tasks 1 and 7; the widget-wears-teal decision → Tasks 1, 8, 9; the "Shopping Assistant" rename → Task 4 data plus its assertion in Task 8; the verbatim conversation and trimming rationale → Task 4; the four real cards including the delivery-line rule → Tasks 4 and 9; the carousel correction → Task 9; all twelve shot-list beats → Tasks 10, 11, 12; the fidelity rule → Task 1's SCSS-parsing test plus the reference-comparison steps in Tasks 7, 11, 12; timing-is-data → Task 2; cursor and typing as data → Task 3; the audio plan and the chosen track with its window → Tasks 5 and 12; product photography with the style brief and measured costs → Task 5; verification → Task 12's stills script; the omissions list needs no task by construction.

**Placeholders.** Task 11 Step 4 and Task 12 Step 5 describe `AdminShell`, `TurnCard`, `CodePane` and `EndCard` in prose with their exact content, targets and reference images rather than full code. This is deliberate for the two largest layout components, whose correctness is judged against a screenshot rather than a signature — but it is the weakest part of this plan, and the executor should expect to iterate against `reference/` there rather than getting it right first time.

**Type consistency.** `Card`/`Stock` (Task 4) are consumed unchanged by `ProductCard` and `CardCarousel` (Task 9). `CursorKey` (Task 3) is produced by `storefrontCursor.ts` and `adminCursor.ts` (Tasks 10, 11) and consumed by `Cursor` (Task 6) and `sfxFromCursor` (Task 3). `typedLength` (Task 3) is used by both `Scene03Question` and `Scene05Answer` and by `sfxFromTyping`, which is what keeps keystroke audio aligned. `TraceRow`/`TURN` (Task 4) feed `PhaseTimeline` (Task 11). `VIEWPORT` (Task 6) and `PANEL_BOX` (Task 8) are the shared coordinate anchors for `storefrontCursor.ts` and `Annotation`. `beat(id)` (Task 2) is the only source of frame numbers anywhere.

**Resolved during review.** `tokens.meta` is a computed SCSS `mix()`. Rather than leave it as a gap it was resolved exactly — `#596782` — and cross-checked: it measures 5.70:1 on white, which is the precise figure `_tokens.scss` claims in its own comment. Task 1 asserts both the hex and the contrast ratio.
