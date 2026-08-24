# Brandable Widget Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A merchant can put their own two colours on the assistant, gets a neutral chat-bubble entry point instead of our creature by default, and a shopper can resize the panel.

**Direction (declared before any CSS, per the anti-slop gate):** *a neutral, merchant-brandable entry point — one solid accent surface, one authored icon, no gradient and no character — so a pilot shop puts its own colour on it in two config fields; the creature stays available for anyone who wants personality.*

**Architecture:** Colours cannot be SCSS variables — SCSS compiles at `theme:compile`, and these are per-sales-channel runtime config. They travel as **CSS custom properties** set on the widget root from Twig, with the existing `$swag-assistant-*` tokens as the `var()` fallbacks. The entry point becomes a style variant, not a replacement. Resize is the only JavaScript change, so exactly one `dist` rebuild.

**Tech Stack:** PHP 8.2, Shopware 6.7 (Twig + PHP SCSS pipeline), vanilla-JS storefront plugin, PHPUnit, node:test.

**Spec:** No separate design document. This implements the 2026-08-24 PM meeting outcome — *"Provide a neutral, configurable chat icon (colors, style) as a starting point, with the option for full overrides"* — plus a shopper-facing resize. Design decisions are recorded below.

## Design of record

### 1. Why CSS custom properties, not SCSS variables

The widget's colours live in `_tokens.scss` as SCSS variables, resolved when Shopware compiles the
theme. Merchant config is read per request, per sales channel. Those cannot meet: one shop, two
storefronts, two brand colours is a legitimate configuration and a compiled stylesheet cannot serve
it.

So the two configurable colours are emitted as custom properties on `.swag-assistant`, and every rule
that used the accent reads `var(--swag-assistant-primary, #{$swag-assistant-accent})`. The token stays
the single source of the *default*; the property is the per-channel override. No hardcoded colour is
introduced anywhere — which the anti-slop note bans outright next to an existing token system.

### 2. Two colours, two jobs — never one treatment with two meanings

- **Primary** — the accent: the entry point's surface, the send button, focus rings, links. This is
  the *action* colour.
- **Secondary** — the quiet surface: the assistant's message bubbles and the log's ground tint.

They are deliberately not "accent and second accent". The note's *"one treatment, two meanings"* rule
is the reason: if both were action colours, a shopper could not tell which thing on screen is the
button.

### 3. Foreground is computed, never configured

A merchant who picks a pale primary would otherwise get white-on-pale and an unreadable icon. There is
no third config field for it: the server computes relative luminance (WCAG) and emits
`--swag-assistant-on-primary` as either white or the existing dark text token. Contrast is a
correctness property, not a preference — asking the merchant to get it right is how it ends up wrong.

### 4. Colours are validated server-side, like `escalationUrl`

A colour string lands in a `style` attribute served to every shopper. Anything other than a strict
`#rrggbb` is dropped to empty, so the `var()` fallback wins. Config access is not permission to inject
CSS — the same argument, and the same shape, as `SystemConfigAssistantConfig::safeUrl()`.

### 5. The creature becomes a choice, not a casualty

`entryPointStyle` defaults to `icon` (the neutral bubble) with `creature` available. Deleting the
creature would throw away a considered piece of work — seven moods, a face shared by orb and header,
personality that survives `prefers-reduced-motion` — for no gain: the default is what a pilot shop
sees, and an option costs one config field.

The icon variant deliberately has **no** gradient, no glint, no `::before` highlight. Today's orb is a
four-stop radial gradient with a diffuse specular highlight, which is right for a creature and wrong
for a neutral brandable surface. One solid fill, one icon, one elevation step.

### 6. Resize: width and height, desktop only, persisted

The panel is a floating card on desktop (`width: 420px; max-height: min(640px, 100vh - 7rem)`) and a
full-screen sheet on phones (`inset: 0`). Resizing a full-screen sheet is meaningless, so the handle
exists only where the panel is a card.

A drag handle on the **top-left corner** — the two edges that grow away from the orb. Clamped to
360–720px wide and 320px–(100vh − 7rem) tall. Persisted in `localStorage`, not `sessionStorage`: the
conversation token is per-tab, but "I like it bigger" is a preference that should survive.

Written to `width`/`height` on the element. The note bans *animating* layout properties; a drag is not
an animation, and there is no transition on these two properties so a drag cannot trigger one.

### 7. What this plan does not do

No new gradient anywhere. No second accent. No hover-only affordance — the resize handle has a
resting state. No pulsing anything. No eyebrow labels in the new config card's help text.

## Global Constraints

- PHP **8.2+**, `declare(strict_types=1)`, non-disableable.
- `composer run quality` must exit **0**. `excessive-parameter-list` threshold 5; `too-many-methods` past ~15 — split rather than suppress, as `SystemConfigEscalationTest` did.
- `vendor/bin/mago fmt` before each commit, and `vendor/bin/mago fmt <file>` for files outside `src`/`tests` (the bare command skips them; the pre-commit hook does not).
- **Never** run `vendor/bin/phpunit tests/Eval` without `--exclude-group eval` — the bootstrap loads `.env` and it fires paid live turns.
- **SCSS needs no `dist` rebuild.** Shopware compiles the widget's styles with its own PHP SCSS pipeline at `theme:compile`; only `src/Resources/app/storefront/src/**/*.js` requires `composer run build:storefront`. That command rebuilds the administration bundle too — commit only the storefront paths, and revert admin churn with `git checkout -- src/Resources/public/administration/`.
- Widget JS emits **DOM nodes, never `innerHTML`**.
- No unit tests for rendering functions by design (`tests/e2e/README.md`); the browser check is the test. Pure functions (clamping, colour maths) do get unit tests.
- **The anti-slop note is the gate**, not a suggestion: `~/Obsidian/SecondBrain/1 Work/3 Resources/Design/How to kill AI slop.md`. Read its ban lists before writing CSS. Before calling Task 4 done, run `node ~/.claude/skills/impeccable/scripts/detect.mjs --json <changed scss/js>` and triage every finding out loud — deliberate, false positive, or real.
- Two of its rules to hold consciously: **a surface takes elevation or a border, never both**, and **overshoot easing only where the brief asked for it** — the creature earned `$swag-assistant-ease-spring`; the neutral icon and the resize do not.

---

### Task 1: Two configurable colours, reaching the widget as custom properties

**Files:**
- Create: `src/Core/Config/WidgetTheme.php`
- Modify: `src/Core/Config/SystemConfigWidgetSettings.php`
- Modify: `src/Storefront/AssistantWidgetExtension.php`
- Modify: `src/Resources/config/config.xml`
- Modify: `src/Resources/views/storefront/component/assistant/orb.html.twig`
- Modify: `src/Resources/app/storefront/src/scss/components/_tokens.scss` and the rules that consume the accent
- Test: `tests/Core/Config/WidgetThemeTest.php`, `tests/PluginManifestTest.php`

**Interfaces:**
- Produces: `WidgetTheme` (`final readonly`) with `string $primary`, `string $secondary`, `string $onPrimary`, `string $entryPointStyle`; `WidgetTheme::of(string $primary, string $secondary, string $style): self` doing validation and luminance; `SystemConfigWidgetSettings::theme(string $salesChannelId): WidgetTheme`; Twig function `swag_assistant_theme(salesChannelId)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Config/WidgetThemeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\WidgetTheme;

/**
 * A merchant's colour lands in a `style` attribute served to every shopper, so it is validated where
 * it is read — the same argument as `SystemConfigAssistantConfig::safeUrl()`. Config access is not
 * permission to inject CSS.
 */
final class WidgetThemeTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'css injection' => ['#fff; background: url(//evil.example/x)'];
        yield 'a css function' => ['var(--x)'];
        yield 'a named colour' => ['rebeccapurple'];
        yield 'three-digit hex' => ['#fff'];
        yield 'no hash' => ['0870ff'];
        yield 'too long' => ['#0870ff00'];
        yield 'not hex at all' => ['#zzzzzz'];
        yield 'empty' => [''];
    }

    #[DataProvider('rejected')]
    public function testAnythingButASixDigitHexIsDropped(string $stored): void
    {
        // Dropped to empty rather than corrected, so the `var()` fallback in the stylesheet wins and
        // the widget keeps the shipped token.
        self::assertSame('', WidgetTheme::of($stored, '', 'icon')->primary);
    }

    /** @return iterable<string, array{string, string}> */
    public static function accepted(): iterable
    {
        yield 'lowercase' => ['#0870ff', '#0870ff'];
        yield 'uppercase is normalised' => ['#0870FF', '#0870ff'];
        yield 'surrounding whitespace' => ['  #0870ff ', '#0870ff'];
    }

    #[DataProvider('accepted')]
    public function testAValidHexSurvivesNormalised(string $stored, string $expected): void
    {
        self::assertSame($expected, WidgetTheme::of($stored, '', 'icon')->primary);
    }

    public function testAPalePrimaryGetsDarkForeground(): void
    {
        // The reason there is no third config field: a merchant picking a pale brand colour would
        // otherwise ship white-on-pale. Contrast is correctness, not preference.
        self::assertSame('#00153e', WidgetTheme::of('#ffe066', '', 'icon')->onPrimary);
    }

    public function testADarkPrimaryGetsWhiteForeground(): void
    {
        self::assertSame('#ffffff', WidgetTheme::of('#0870ff', '', 'icon')->onPrimary);
    }

    public function testAnUnsetPrimaryLeavesTheForegroundToTheStylesheet(): void
    {
        // Nothing to compute against, so nothing is claimed: both properties stay empty and the
        // stylesheet's own pairing applies.
        $theme = WidgetTheme::of('', '', 'icon');

        self::assertSame('', $theme->primary);
        self::assertSame('', $theme->onPrimary);
    }

    public function testAnUnknownEntryPointStyleFallsBackToTheNeutralIcon(): void
    {
        self::assertSame('icon', WidgetTheme::of('', '', 'sparkles')->entryPointStyle);
        self::assertSame('icon', WidgetTheme::of('', '', '')->entryPointStyle);
        self::assertSame('creature', WidgetTheme::of('', '', 'creature')->entryPointStyle);
    }
}
```

Add to `tests/PluginManifestTest.php`'s `$required` array: `'primaryColor'`, `'secondaryColor'`,
`'entryPointStyle'`.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Core/Config/WidgetThemeTest.php tests/PluginManifestTest.php`
Expected: FAIL — `Class "…WidgetTheme" not found`, and `config.xml is missing "primaryColor"`.

- [ ] **Step 3: Implement `WidgetTheme`**

Create `src/Core/Config/WidgetTheme.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

/**
 * The merchant's two brand colours, validated, plus the foreground the primary needs.
 *
 * **These are CSS custom properties, not SCSS variables, and that is forced rather than chosen.** The
 * widget's tokens live in `_tokens.scss` and are resolved when Shopware compiles the theme; this
 * config is read per request, per sales channel. One shop with two storefronts and two brand colours
 * is a legitimate setup that a compiled stylesheet cannot serve. So these travel to the browser as
 * properties on `.swag-assistant` and every rule reads
 * `var(--swag-assistant-primary, #{$swag-assistant-accent})` — the token stays the default, the
 * property is the override, and no hardcoded colour is introduced anywhere.
 *
 * **Empty is a real value.** A colour that fails validation, or was never set, yields `''`, the
 * property is not emitted at all, and the `var()` fallback keeps the shipped token. Correcting a bad
 * value would be guessing at what the merchant meant.
 */
final readonly class WidgetTheme
{
    /**
     * Shopware's Night Blue, matching `$swag-assistant-text`. Kept as a literal here because this is
     * the one place a colour has to exist in PHP — the stylesheet cannot compute contrast.
     */
    private const DARK_FOREGROUND = '#00153e';

    private const LIGHT_FOREGROUND = '#ffffff';

    /**
     * WCAG's threshold for preferring dark text over white. 0.179 is the relative luminance at which
     * the two contrast ratios cross; above it, dark wins.
     */
    private const LUMINANCE_PIVOT = 0.179;

    public const STYLE_ICON = 'icon';

    public const STYLE_CREATURE = 'creature';

    private function __construct(
        public string $primary,
        public string $secondary,
        public string $onPrimary,
        public string $entryPointStyle,
    ) {}

    public static function of(string $primary, string $secondary, string $entryPointStyle): self
    {
        $safePrimary = self::hexOrEmpty($primary);

        return new self(
            $safePrimary,
            self::hexOrEmpty($secondary),
            // Nothing to compute against means nothing claimed: the stylesheet's own pairing applies.
            $safePrimary === '' ? '' : self::readableOn($safePrimary),
            $entryPointStyle === self::STYLE_CREATURE ? self::STYLE_CREATURE : self::STYLE_ICON,
        );
    }

    /**
     * A strict `#rrggbb`, or nothing.
     *
     * This value is interpolated into a `style` attribute served to every shopper, so the allowlist
     * is deliberately narrower than CSS accepts: no `var()`, no `rgb()`, no named colours, no
     * three-digit shorthand. Everything CSS would happily parse is also a way to smuggle a second
     * declaration in, and config access is not permission to do that — the same reasoning as
     * {@see SystemConfigAssistantConfig::safeUrl()}.
     */
    private static function hexOrEmpty(string $value): string
    {
        $trimmed = strtolower(trim($value));

        return preg_match('/^#[0-9a-f]{6}$/', $trimmed) === 1 ? $trimmed : '';
    }

    /**
     * White or Night Blue, whichever is readable on this background.
     *
     * There is no config field for this on purpose. A merchant picking a pale brand colour would
     * otherwise ship a white icon on a pale disc and an unreadable entry point; asking them to also
     * choose the foreground is how that ends up wrong. Contrast is a correctness property.
     */
    private static function readableOn(string $hex): string
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $srgb = (int) hexdec(substr($hex, $offset, 2)) / 255;
            // WCAG's sRGB linearisation, not a naive average: perceived lightness is not the mean of
            // the channels, and a naive version picks white on mid-yellow.
            $channels[] = $srgb <= 0.03928 ? $srgb / 12.92 : (($srgb + 0.055) / 1.055) ** 2.4;
        }

        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return $luminance > self::LUMINANCE_PIVOT ? self::DARK_FOREGROUND : self::LIGHT_FOREGROUND;
    }
}
```

- [ ] **Step 4: Read it from config and expose it to Twig**

In `SystemConfigWidgetSettings`, add:

```php
    public function theme(string $salesChannelId): WidgetTheme
    {
        $prefix = SystemConfigAssistantConfig::PREFIX;

        return WidgetTheme::of(
            $this->systemConfig->getString($prefix . 'primaryColor', $salesChannelId),
            $this->systemConfig->getString($prefix . 'secondaryColor', $salesChannelId),
            $this->systemConfig->getString($prefix . 'entryPointStyle', $salesChannelId),
        );
    }
```

In `AssistantWidgetExtension`, register `new TwigFunction('swag_assistant_theme', $this->theme(...))`
returning `$this->widgetSettings->theme($salesChannelId)`.

- [ ] **Step 5: Declare the three fields**

Add a `config.xml` card titled **Appearance**, placed immediately after the `Storefront widget` card:

```xml
    <card>
        <title>Appearance</title>

        <input-field type="single-select">
            <name>entryPointStyle</name>
            <label>Entry point</label>
            <defaultValue>icon</defaultValue>
            <options>
                <option>
                    <id>icon</id>
                    <name>Chat icon — neutral, takes your colours</name>
                </option>
                <option>
                    <id>creature</id>
                    <name>Character — the animated assistant face</name>
                </option>
            </options>
            <helpText>The chat icon is the neutral default and the one to brand. The character has
                expressions and reacts to the conversation; it carries more personality than a
                merchant storefront usually wants.</helpText>
        </input-field>

        <input-field type="colorpicker">
            <name>primaryColor</name>
            <label>Primary colour</label>
            <helpText>The action colour: the entry point, the send button, focus rings. Left empty,
                the shipped blue is used. Text on top of it is chosen automatically so it stays
                readable — a pale colour gets dark text, not white.</helpText>
        </input-field>

        <input-field type="colorpicker">
            <name>secondaryColor</name>
            <label>Secondary colour</label>
            <helpText>The quiet surface behind the assistant's replies. Deliberately not a second
                action colour: if two things on screen shout equally, a shopper cannot tell which one
                is the button.</helpText>
        </input-field>
    </card>
```

Verify `colorpicker` is a valid `config.xml` field type against the schema the file already references
(`https://raw.githubusercontent.com/shopware/shopware/trunk/src/Core/System/SystemConfig/Schema/config.xsd`);
if it is not, use `type="text"` — the server-side validation is what matters and it is already strict.

- [ ] **Step 6: Emit the properties, and consume them**

In `orb.html.twig`, on the `.swag-assistant` root element, add a `style` attribute built only from
non-empty values, and a data attribute for the style variant:

```twig
{% set theme = swag_assistant_theme(context.salesChannelId) %}
```

then on the root div:

```twig
         data-entry-point="{{ theme.entryPointStyle }}"
         style="
            {%- if theme.primary %}--swag-assistant-primary: {{ theme.primary }};{% endif %}
            {%- if theme.onPrimary %}--swag-assistant-on-primary: {{ theme.onPrimary }};{% endif %}
            {%- if theme.secondary %}--swag-assistant-secondary: {{ theme.secondary }};{% endif -%}
         "
```

Then in the SCSS, replace accent usages with `var(--swag-assistant-primary, #{$swag-assistant-accent})`
and bubble/tint usages with `var(--swag-assistant-secondary, #{$swag-assistant-bubble})`. Find them
with:

```bash
grep -rn 'swag-assistant-accent\b\|swag-assistant-bubble\b' src/Resources/app/storefront/src/scss
```

Two rules to hold while doing this: leave `$swag-assistant-accent-light` / `-dark` / `-abyss` alone —
they are the creature's gradient stops and the creature is not being rebranded — and do **not**
introduce a `--swag-assistant-primary-dark` computed in CSS. `color-mix()` support is not universal
enough to be the only path to a hover state; use `filter: brightness(0.92)` on the existing rule or
leave the hover as it is.

- [ ] **Step 7: Run the tests, then the gate**

Run: `vendor/bin/phpunit --no-coverage --exclude-group eval && vendor/bin/mago fmt && composer run quality`
Expected: pass, `quality` exits 0.

- [ ] **Step 8: Look at it in a browser**

SCSS needs no `dist` rebuild — Shopware compiles it:

```bash
docker exec shopping-assistant-test-web-1 bash -lc 'cd /var/www/html && \
  php bin/console system:config:set SwagAssistantStarterKit.config.primaryColor "#7a3cff" && \
  php bin/console theme:compile -q && php bin/console cache:clear -q'
```

Open the storefront. The orb and the send button must both be the new colour, and the icon on the orb
must still be readable. Then try a pale one (`#ffe066`) and confirm the foreground flips to dark.
Finally unset it and confirm the shipped blue returns.

- [ ] **Step 9: Commit**

```bash
git add src/Core/Config src/Storefront src/Resources/config/config.xml \
        src/Resources/views src/Resources/app/storefront/src/scss tests/Core/Config tests/PluginManifestTest.php
git commit -m "feat: let a merchant put their own two colours on the widget"
```

---

### Task 2: A neutral chat-bubble entry point

**Files:**
- Modify: `src/Resources/app/storefront/src/scss/components/_icons.scss` (add the mask)
- Modify: `src/Resources/app/storefront/src/scss/components/_orb.scss`, `_bubble.scss`, `_panel.scss`
- Modify: `src/Resources/views/storefront/component/assistant/orb.html.twig`, `panel.html.twig`
- Test: `tests/e2e/widget.spec.js`

**Interfaces:**
- Consumes: `data-entry-point` and the custom properties from Task 1.
- Produces: `$swag-assistant-icon-chat`; `.swag-assistant-orb--icon` / `--creature` variants.

- [ ] **Step 1: Add the icon mask**

`_icons.scss` holds masks as data-URI SVGs. Add `$swag-assistant-icon-chat` in the same shape as the
existing eight, matching their stroke weight — read two of them first and match, rather than pasting an
icon from elsewhere at a different weight. The note's *"real icon library or authored SVG, one stroke
weight"* rule is the reason: one heavier glyph among eight is more visible than a missing one.

A speech bubble: rounded rectangle with a tail at the bottom-left. No dots inside it — three dots in a
bubble reads as "typing", which is a state, and this element is not showing one.

- [ ] **Step 2: Split the orb surface into two variants**

`_bubble.scss`'s `swag-assistant-bubble` mixin paints the four-stop radial gradient and the glint.
Leave the mixin as it is — it is the creature's surface — and apply it only under
`[data-entry-point="creature"]`.

For the icon variant write a sibling mixin, deliberately plain:

```scss
// The neutral entry point: one fill, one icon, one elevation step.
//
// No gradient, no glint, no ::before highlight. The creature's bubble has all three and earns them —
// it is a character with a wet plastic surface. A neutral mark a merchant recolours is a different
// object, and a specular highlight on an arbitrary brand colour reads as a mistake rather than a
// finish. Elevation only, and no border with it.
@mixin swag-assistant-disc {
    position: relative;
    border-radius: 50%;
    background: var(--swag-assistant-primary, #{$swag-assistant-accent});
    box-shadow: $swag-assistant-lift-2;
}
```

Then in `_orb.scss`, scope today's rules to the creature variant and add the icon variant: the disc
plus a centred mask-image glyph at ~42% of `--swag-assistant-unit`, coloured
`var(--swag-assistant-on-primary, #{$swag-assistant-surface})`.

Keep the existing hover/active/focus-visible treatment for both variants — the note requires real
states, and the focus ring must be visible against an arbitrary brand colour, so keep it offset rather
than tinted.

- [ ] **Step 3: The header avatar follows the same choice**

`panel.html.twig` includes `face.html.twig` for the docked avatar at 34px. Under
`data-entry-point="icon"`, render the same disc-and-glyph instead. The two must not disagree: an
icon in the corner and a face in the header would read as two different products.

`face.html.twig` and `creature.js` stay untouched — under `creature` everything behaves exactly as it
does today, including the moods and the `prefers-reduced-motion` handling.

- [ ] **Step 4: Add the browser checks**

In the stubbed describe block of `tests/e2e/widget.spec.js`, following its existing helpers:

```js
    test('the neutral entry point shows an icon, not a face', async ({ page }) => {
        await page.goto(SHOP);

        const orb = page.locator('[data-swag-assistant-orb]');
        await expect(orb).toBeVisible();
        // The creature's face is what the icon variant replaces; both existing at once would mean the
        // variant is additive rather than exclusive.
        await expect(orb.locator('.swag-assistant-face')).toHaveCount(0);
        await expect(orb.locator('.swag-assistant-orb__glyph')).toHaveCount(1);
    });
```

Match the class name to whatever Step 2 actually used, and check the shop's `entryPointStyle` is at
its default before asserting the icon variant.

- [ ] **Step 5: Compile, look at it, and run the checks**

```bash
docker exec shopping-assistant-test-web-1 bash -lc 'cd /var/www/html && php bin/console theme:compile -q && php bin/console cache:clear -q'
npx playwright test tests/e2e/widget.spec.js -g "entry point|reading what the server" --reporter=line
```

Look at both variants at both sizes. The three things a screenshot will not tell you: the icon is
optically centred in the disc (a speech bubble with a tail is not centred on its bounding box), the
focus ring is visible on a saturated brand colour, and the header avatar and the orb read as the same
object.

- [ ] **Step 6: Commit**

```bash
git add src/Resources/app/storefront/src/scss src/Resources/views tests/e2e/widget.spec.js
git commit -m "feat: a neutral chat icon as the default entry point"
```

---

### Task 3: A resizable panel

**Files:**
- Create: `src/Resources/app/storefront/src/assistant/resize.js`
- Modify: `src/Resources/app/storefront/src/assistant/panel.plugin.js`
- Modify: `src/Resources/views/storefront/component/assistant/panel.html.twig`
- Modify: `src/Resources/app/storefront/src/scss/components/_panel.scss`
- Modify: `src/Resources/snippet/swag-assistant.en.json`, `swag-assistant.de.json`
- Test: `tests/js/resize.test.js`
- Rebuild: `src/Resources/app/storefront/dist/**`

**Interfaces:**
- Produces: `clampSize({width, height}, viewport)` → `{width, height}`, and `attachResize(panel, handle, {onCommit})` from `resize.js`. `clampSize` is pure and unit-tested; the drag wiring is covered by the browser check.

- [ ] **Step 1: Write the failing test**

Create `tests/js/resize.test.js`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { MAX_WIDTH, MIN_HEIGHT, MIN_WIDTH, clampSize } from '../../src/Resources/app/storefront/src/assistant/resize.js';

/*
 * The clamp is the part worth testing: a drag can ask for any number, and a panel wider than the
 * viewport or shorter than its own header is not a preference, it is a broken layout.
 */
test('a size within bounds is returned unchanged', () => {
    assert.deepEqual(clampSize({ width: 500, height: 500 }, { width: 1440, height: 900 }), { width: 500, height: 500 });
});

test('below the floor is raised to it', () => {
    const size = clampSize({ width: 10, height: 10 }, { width: 1440, height: 900 });

    assert.equal(size.width, MIN_WIDTH);
    assert.equal(size.height, MIN_HEIGHT);
});

test('above the ceiling is capped', () => {
    assert.equal(clampSize({ width: 99999, height: 500 }, { width: 1440, height: 900 }).width, MAX_WIDTH);
});

test('a narrow viewport wins over the configured ceiling', () => {
    // On a 500px-wide window the 720px ceiling is not reachable, and a panel wider than the window is
    // a horizontal scrollbar on the whole page.
    const size = clampSize({ width: 720, height: 400 }, { width: 500, height: 900 });

    assert.ok(size.width < 500, `expected under the viewport width, got ${size.width}`);
});

test('a short viewport wins over the height floor', () => {
    // The floor cannot be honoured on a 200px-tall window; the viewport is the harder constraint, and
    // a panel taller than the window cannot be closed.
    const size = clampSize({ width: 420, height: 640 }, { width: 1440, height: 200 });

    assert.ok(size.height <= 200, `expected within the viewport, got ${size.height}`);
});

test('a non-numeric size yields the defaults rather than NaN', () => {
    // localStorage is a string store and anyone can edit it. NaN written to a width is a panel with
    // no size at all.
    const size = clampSize({ width: 'wide', height: null }, { width: 1440, height: 900 });

    assert.equal(Number.isFinite(size.width), true);
    assert.equal(Number.isFinite(size.height), true);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `node --test tests/js/resize.test.js`
Expected: FAIL — the module does not exist.

- [ ] **Step 3: Implement `resize.js`**

`clampSize` first, pure. Then `attachResize(panel, handle, {onCommit})`:

- `pointerdown` on the handle captures the start point and the panel's current box, then
  `setPointerCapture` so a fast drag that leaves the handle keeps working.
- `pointermove` writes clamped `width`/`height` **in px** to `panel.style`. Growth is up and to the
  left, so width increases as `startX - event.clientX` and height as `startY - event.clientY`: the
  panel is anchored bottom-right by the orb, and a handle that grew it downward would push it off
  screen.
- `pointerup` releases capture and calls `onCommit(size)` once — persistence belongs to the caller,
  and one write per drag rather than one per frame.
- No `requestAnimationFrame` throttle: two style writes per pointer event on one element is not the
  bottleneck, and a rAF queue makes the panel lag the cursor, which is the one thing a drag must not do.
- Keyboard: the handle is focusable and arrow keys resize in 24px steps. Not decoration — a
  pointer-only affordance is one a keyboard user cannot reach, and the widget's other controls are all
  operable.

- [ ] **Step 4: Run the test to verify it passes**

Run: `node --test tests/js/resize.test.js`
Expected: PASS.

- [ ] **Step 5: Wire it into the panel**

Add the handle to `panel.html.twig` inside a new `swag_assistant_panel_resize` block — a real
`<button>` with `aria-label` from a new snippet, so it is reachable and named. Put it at the panel's
**top-left** corner.

In `panel.plugin.js`, call `attachResize` when the panel opens, with `onCommit` writing to
`localStorage` under `swagAssistantPanelSize`, and read that key back on open (clamped again, because
the viewport may have changed since). `localStorage`, not `sessionStorage`: the conversation token is
per-tab, a size preference is not.

SCSS for the handle: 16px hit area at the corner, `cursor: nwse-resize`, a resting state that is
visible without hover (a short 1px diagonal rule in `$swag-assistant-hairline`), and
`:focus-visible` matching the widget's existing ring. **Desktop only** — inside the existing media
query where the panel is a floating card, not the phone sheet. Add `resize: none` guards nowhere; this
is not a textarea.

Two things to get right: no `transition` on `width`/`height` (a transition plus a drag is a panel that
lags the cursor), and the handle must not appear in the phone sheet at all.

- [ ] **Step 6: Rebuild `dist` — the one JS rebuild in this plan**

```bash
composer run build:storefront
git status --short src/Resources/app/storefront/dist src/Resources/public/administration
```

Commit only the storefront paths; revert the administration churn with
`git checkout -- src/Resources/public/administration/` and delete the superseded
`*.panel.plugin.<hash>.js`.

- [ ] **Step 7: Drive it in a browser**

```bash
docker exec shopping-assistant-test-web-1 bash -lc 'cd /var/www/html && php bin/console assets:install -q && php bin/console theme:compile -q && php bin/console cache:clear -q'
```

Then by hand, because a Playwright drag will not tell you how it feels: drag it bigger, reload and
confirm it persisted, drag it past both limits, resize the window smaller than the stored size and
confirm it re-clamps rather than overflowing, and tab to the handle and use the arrows. Then narrow to
a phone viewport and confirm the handle is gone.

- [ ] **Step 8: Gate and commit**

```bash
node --test "tests/js/"*.test.js
vendor/bin/phpunit --no-coverage --exclude-group eval
composer run quality
git add src/Resources/app/storefront src/Resources/views src/Resources/snippet tests/js/resize.test.js
git commit -m "feat: let a shopper resize the chat panel"
```

---

### Task 4: The design gate, then documentation

Not a formality. On the last widget change the reviewer's verdict was "looks too AI sloppy", and the
findings were things already shipped and defended in comments.

**Files:**
- Modify: `README.md`
- Modify: `ARCHITECTURE.md` (only if the extension-points table needs the new block name)

- [ ] **Step 1: Run the detector and triage every finding out loud**

```bash
node ~/.claude/skills/impeccable/scripts/detect.mjs --json \
  src/Resources/app/storefront/src/scss/components/_orb.scss \
  src/Resources/app/storefront/src/scss/components/_bubble.scss \
  src/Resources/app/storefront/src/scss/components/_panel.scss \
  src/Resources/app/storefront/src/scss/components/_icons.scss \
  src/Resources/app/storefront/src/assistant/resize.js
```

Classify each finding as deliberate, false positive, or real — in writing. Its `bounce-easing` rule
matches loosely across multi-line `animation:` declarations, so check the flagged line actually
contains a spring curve before dismissing or fixing it. A clean scan is not proof of quality.

- [ ] **Step 2: Walk the note's pre-flight list against this diff**

Specifically, and each one either passes or gets fixed before commit:

- **Direction declared and followed** — is the icon variant actually plain, or did a gradient creep back in?
- **One accent** — `grep -c` the new custom properties; two colours, two jobs, no third.
- **Elevation or border, never both** — the new disc and the resize handle each pick one.
- **Overshoot easing only where earned** — `grep -n 'ease-spring' src/Resources/app/storefront/src/scss` and confirm every hit is creature motion, not the neutral variant or the resize.
- **Contrast ≥ 4.5:1 body / ≥ 3:1 large, in every state** — check the computed foreground against three brand colours, including a mid-tone that sits near the pivot.
- **Real states** — hover, focus-visible, active, and the resize handle's resting state.
- **Countable claims counted** — the radius scale has four tokens; confirm the new CSS adds no fifth value.
- **Touch floor × row pitch** — the resize handle is desktop-only, so the 44px floor does not apply; say so rather than silently skipping it.
- **Diff clean** — no dead code, no leftover debug, no admin-bundle churn.

- [ ] **Step 3: Document it**

In the README's widget section: the three new settings, that the icon is the default and the creature
is opt-in, that colours are validated and the foreground is computed, and that the panel is
resizable and remembers its size. Mention the new Twig block beside the existing five.

- [ ] **Step 4: Final gate and commit**

```bash
composer run test && composer run quality && node --test "tests/js/"*.test.js
git add README.md ARCHITECTURE.md
git commit -m "docs: the widget's new appearance settings"
```

---

## Self-review notes

**Scope check.** Four tasks, each independently reviewable: colours reaching the widget, the entry
point's appearance, the resize, the design gate. Tasks 1 and 2 touch only PHP/Twig/SCSS and need no
`dist` rebuild — Shopware compiles the styles. Task 3 is the only JavaScript, so there is exactly one
rebuild in the plan.

**Type consistency.** `WidgetTheme::of(string, string, string): self` with `$primary`, `$secondary`,
`$onPrimary`, `$entryPointStyle` is used with those names in Tasks 1 and 2 and in the Twig template.
`clampSize({width, height}, viewport)` returns the same shape in the test and in `panel.plugin.js`.

**Four things an executor must verify rather than trust**, because this plan did not run them:
whether `colorpicker` is a valid `config.xml` field type on 6.7 (Task 1 Step 5, fall back to `text`);
the exact set of SCSS rules using `$swag-assistant-accent` and `$swag-assistant-bubble` (Task 1 Step 6
gives the grep); the existing icon masks' stroke weight before authoring a ninth (Task 2 Step 1); and
the media query inside `_panel.scss` that separates the desktop card from the phone sheet (Task 3
Step 5).

**Risk: low, and contained to the storefront.** Nothing here touches the grounding pipeline, the
gateway, the tool loop, or R32. The worst failure is an ugly or unreadable widget, which Task 4's gate
exists to catch. The one thing that would reach a shopper badly is an unvalidated colour landing in a
`style` attribute, which is why validation is server-side, allowlisted, and tested against eight
hostile inputs before anything renders.
