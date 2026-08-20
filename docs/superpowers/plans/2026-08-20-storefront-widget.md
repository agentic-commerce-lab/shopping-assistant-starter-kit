# Storefront Assistant Widget Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the shopper-facing chat widget into a real Shopware 6.7 storefront, closing Must-have 1.

**Architecture:** A Twig template appended to `base.html.twig` renders an entry orb (CSS-animated, zero JS) and a panel shell. Storefront JS follows the standard Shopware plugin shape — authored in `Resources/app/storefront/src`, registered lazily through `window.PluginManager`, built with `shopware-cli` into a committed `dist`. The panel talks to the existing `POST /assistant/chat` and `GET /assistant/history`, renders server-rendered product cards, and never derives a figure from the model's prose.

**Tech Stack:** Twig (`sw_extends`), SCSS via scssphp (`theme:compile`, no Node in the shop), vanilla ES modules against `window.PluginBaseClass`, `shopware-cli project storefront-build`, PHPUnit, Playwright.

**Spec:** `docs/superpowers/specs/2026-08-20-storefront-widget-design.md` (W1–W24)

## Global Constraints

- **PHP 8.2**, `declare(strict_types=1)` in every file, Mago at full strictness. No `mixed`, no unsafe casts.
- **Gate thresholds:** cyclomatic complexity 10, nesting depth 4, parameters 5, **~400 lines/file**. `composer run quality` must exit 0. `scripts/check_file_length.php` scans **PHP only** — JS files are not length-gated, but keep them under 400 lines anyway (AGENTS.md structure rules).
- **Existing tests must stay green.** Updating an assertion because a contract deliberately changed is legitimate; loosening one to get green is not.
- **No figure in the UI may originate from `prose`.** Every price, stock number and delivery time comes from a `cards[]` entry (D3, ruling R47).
- **Colours — brand palette only, no invented values.** Surface `#ffffff`; text `#00153e`; meta `#a3a6b5`; accent `#0870ff`; brand `#189eff`; user bubble `#f0f6ff`; hairline `#e6e9f0`; in stock `#57d998`; low stock `#f88138`; out of stock `#838489`. **There is no red in the palette** — errors and warnings use `#f88138`.
- **Type — three steps only:** meta 12/500, body 16/400 (600 name, 650 price), heading 20/600 at −0.01em. Prices use `font-variant-numeric: tabular-nums`.
- **Radii:** panel 12, card 8, button/input 6, user bubble 12 with a 4px bottom-right corner, orb circle.
- **Exactly one gradient and one coloured shadow in the whole widget, both on the orb.** Everywhere else: flat fills, neutral shadows.
- **Surfaces:** panel = elevation only, no border. Cards = 1px border only, no shadow.
- **Motion:** `transform`/`opacity`/`border-radius` only — never width/height/padding/margin. Exponential ease-out. Nothing sits at `opacity: 0` at rest. No decorative pulse.
- **`prefers-reduced-motion`** disables motion but **keeps the copy changes**.
- **Status is never colour alone** — always dot plus text label.
- **Contrast** ≥ 4.5:1 body, ≥ 3:1 large, in every state including placeholders, disabled controls and focus rings.
- **No new npm dependency** unless a task says so. `motion` is opt-in only if CSS/WAAPI proves insufficient (W4). React/shadcn/Tailwind/GSAP are rejected.
- **English + German snippets** for every user-visible string. No hardcoded copy in Twig or JS.
- **Verified API facts** (do not re-derive): plugin JS is collected from `Resources/app/storefront/dist/storefront/js/<asset-name>/<asset-name>.js`; `window.PluginBaseClass` and `window.PluginManager` are globals; `context` is a Twig global; template inheritance uses `sw_extends`; snippets auto-load from `Resources/snippet`; there is no CSRF layer in 6.7.

> **⚠ Before starting: re-read `HEAD`.** This plan was written while another session was committing to `feat/grounded-core`. `warnings` landed in `42ca719`; `AssistantRunner`, `FactRenderer` and `ProseAudit` had uncommitted edits; `DalConversationStore` changed in `36ada74`. Confirm the `warnings` payload shape and the `ConversationTurn` signature before Task 9. **Task 9 is blocked until the tree is clean.**

---

### Task 1: The orb renders, and only on a shop that can answer

**Files:**
- Create: `src/Core/Twig/AssistantWidgetExtension.php`
- Create: `src/Resources/views/storefront/base.html.twig`
- Create: `src/Resources/views/storefront/component/assistant/orb.html.twig`
- Create: `src/Resources/app/storefront/src/scss/base.scss`
- Create: `src/Resources/app/storefront/src/scss/components/_tokens.scss`
- Create: `src/Resources/app/storefront/src/scss/components/_orb.scss`
- Create: `src/Resources/snippet/en_GB/storefront.en-GB.json`
- Create: `src/Resources/snippet/de_DE/storefront.de-DE.json`
- Modify: `src/Resources/config/services.xml`
- Modify: `src/Resources/config/config.xml`
- Test: `tests/Core/Twig/AssistantWidgetExtensionTest.php`

**Interfaces:**
- Consumes: `SystemConfigLlmSettings::isConfigured(string $salesChannelId): bool`; `SystemConfigAssistantConfig::forSalesChannel(string $salesChannelId): AssistantConfig` where `AssistantConfig` exposes `bool $killSwitch` and `bool $enableAddToCart`.
- Produces: Twig function `swag_assistant_widget_enabled(string $salesChannelId): bool`; the DOM contract every later task depends on — `[data-swag-assistant-orb]` and `[data-swag-assistant-root]` with data attributes `data-chat-url`, `data-history-url`, `data-cart-url`, `data-locale`, `data-assistant-name`, `data-add-to-cart-enabled`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Twig\AssistantWidgetExtension;

#[CoversClass(AssistantWidgetExtension::class)]
final class AssistantWidgetExtensionTest extends TestCase
{
    private const CHANNEL = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';

    public function testEnabledWhenConfiguredAndNotKilled(): void
    {
        $extension = $this->extension(configured: true, killSwitch: false, widgetEnabled: true);

        self::assertTrue($extension->isEnabled(self::CHANNEL));
    }

    public function testDisabledWhenTheShopHasNoModel(): void
    {
        // The endpoint would answer 503. An orb that opens a panel which cannot answer is worse
        // than no orb, so the widget must not render at all.
        $extension = $this->extension(configured: false, killSwitch: false, widgetEnabled: true);

        self::assertFalse($extension->isEnabled(self::CHANNEL));
    }

    public function testDisabledWhenTheKillSwitchIsOn(): void
    {
        $extension = $this->extension(configured: true, killSwitch: true, widgetEnabled: true);

        self::assertFalse($extension->isEnabled(self::CHANNEL));
    }

    public function testDisabledWhenTheMerchantTurnedTheWidgetOff(): void
    {
        $extension = $this->extension(configured: true, killSwitch: false, widgetEnabled: false);

        self::assertFalse($extension->isEnabled(self::CHANNEL));
    }

    public function testExposesTheTwigFunction(): void
    {
        $names = array_map(
            static fn($function): string => $function->getName(),
            $this->extension(configured: true, killSwitch: false, widgetEnabled: true)->getFunctions(),
        );

        self::assertContains('swag_assistant_widget_enabled', $names);
    }

    private function extension(bool $configured, bool $killSwitch, bool $widgetEnabled): AssistantWidgetExtension
    {
        // Replace these fakes with the project's existing test doubles for the two config services
        // if they already exist; do not introduce a mocking framework.
        return new AssistantWidgetExtension(
            new FakeLlmSettings($configured),
            new FakeAssistantConfig($killSwitch),
            new FakeWidgetSettings($widgetEnabled),
        );
    }
}
```

Note: the three `Fake*` classes are written in Step 3 alongside the implementation, because their shape depends on the interfaces the extension consumes. Put them in `tests/Core/Twig/`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Twig/AssistantWidgetExtensionTest.php`
Expected: FAIL — `Class "Swag\AssistantStarterKit\Core\Twig\AssistantWidgetExtension" not found`

- [ ] **Step 3: Write the extension**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Twig;

use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Decides whether the storefront widget renders at all.
 *
 * The rule lives in PHP because it already does: `isConfigured()` encodes what "this shop can answer"
 * means, and `killSwitch` encodes what "stop everything" means. Re-deriving either in Twig would
 * duplicate a contract that has one owner, which is the failure AGENTS.md's shared-contracts rule
 * exists to prevent.
 *
 * **An orb that opens a panel which answers 503 is worse than no orb**, so an unconfigured shop
 * renders nothing rather than rendering a broken affordance.
 */
final class AssistantWidgetExtension extends AbstractExtension
{
    public function __construct(
        private readonly SystemConfigLlmSettings $llmSettings,
        private readonly SystemConfigAssistantConfig $assistantConfig,
        private readonly SystemConfigWidgetSettings $widgetSettings,
    ) {}

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('swag_assistant_widget_enabled', $this->isEnabled(...)),
            new TwigFunction('swag_assistant_widget_name', $this->assistantName(...)),
            new TwigFunction('swag_assistant_add_to_cart_enabled', $this->addToCartEnabled(...)),
            new TwigFunction('swag_assistant_greeting', $this->greeting(...)),
        ];
    }

    public function isEnabled(string $salesChannelId): bool
    {
        if (!$this->widgetSettings->isWidgetEnabled($salesChannelId)) {
            return false;
        }

        if (!$this->llmSettings->isConfigured($salesChannelId)) {
            return false;
        }

        return !$this->assistantConfig->forSalesChannel($salesChannelId)->killSwitch;
    }

    public function assistantName(string $salesChannelId): string
    {
        return $this->widgetSettings->assistantName($salesChannelId);
    }

    public function greeting(string $salesChannelId): string
    {
        return $this->widgetSettings->greeting($salesChannelId);
    }

    public function addToCartEnabled(string $salesChannelId): bool
    {
        return $this->assistantConfig->forSalesChannel($salesChannelId)->enableAddToCart;
    }
}
```

Also create `src/Core/Config/SystemConfigWidgetSettings.php`, following the exact shape of the existing `SystemConfigAssistantConfig` (read it first — it already has the `boolOr` / string-reading helpers this needs):

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

/**
 * The three widget fields from `config.xml`.
 *
 * Separate from {@see SystemConfigAssistantConfig} because those fields are policy the model is
 * bound by, and these are presentation. A merchant switching off the widget has not changed what the
 * assistant may do.
 */
final readonly class SystemConfigWidgetSettings
{
    private const DEFAULT_NAME = 'Shopping Assistant';

    public function __construct(private ConfigReader $reader) {}

    public function isWidgetEnabled(string $salesChannelId): bool
    {
        return $this->reader->boolOr('widgetEnabled', true, $salesChannelId);
    }

    public function assistantName(string $salesChannelId): string
    {
        $name = trim($this->reader->stringOr('assistantName', '', $salesChannelId));

        return $name === '' ? self::DEFAULT_NAME : $name;
    }

    public function greeting(string $salesChannelId): string
    {
        return trim($this->reader->stringOr('greeting', '', $salesChannelId));
    }
}
```

**Read `SystemConfigAssistantConfig` before writing this** and reuse its actual config-reading collaborator and method names rather than the `ConfigReader` placeholder above — the point is to reuse the existing reader, not to invent a second one.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Twig/AssistantWidgetExtensionTest.php`
Expected: PASS, 5 tests

- [ ] **Step 5: Register the services**

In `src/Resources/config/services.xml`, inside `<services>` (autowire and autoconfigure are already on by default, so the Twig extension is tagged automatically):

```xml
        <!-- Storefront widget. The Twig extension is the single gate deciding whether the orb
             renders; it reuses the config services rather than re-deriving their rules. -->
        <service id="Swag\AssistantStarterKit\Core\Config\SystemConfigWidgetSettings"/>
        <service id="Swag\AssistantStarterKit\Core\Twig\AssistantWidgetExtension"/>
```

- [ ] **Step 6: Add the config card**

Append to `src/Resources/config/config.xml`, before `</config>`:

```xml
    <card>
        <title>Storefront widget</title>

        <input-field type="bool">
            <name>widgetEnabled</name>
            <label>Show the assistant in the storefront</label>
            <defaultValue>true</defaultValue>
            <helpText>When off, no entry point renders. The chat endpoint stays reachable, so a
                custom interface built against it keeps working.</helpText>
        </input-field>

        <input-field type="text">
            <name>assistantName</name>
            <label>Assistant name</label>
            <helpText>Shown in the panel heading. Defaults to "Shopping Assistant".</helpText>
        </input-field>

        <input-field type="textarea">
            <name>greeting</name>
            <label>Greeting</label>
            <helpText>The first message a shopper sees. Say what the assistant can look up, not how
                advanced it is.</helpText>
        </input-field>
    </card>
```

- [ ] **Step 7: Write the snippets**

`src/Resources/snippet/en_GB/storefront.en-GB.json`:

```json
{
  "swagAssistant": {
    "orb": {
      "open": "Open the shopping assistant",
      "nudge": "Ask me anything about this shop"
    },
    "panel": {
      "close": "Close the assistant",
      "inputLabel": "Your message",
      "inputPlaceholder": "Ask about this shop…",
      "send": "Send",
      "defaultGreeting": "Hi. I can look things up in this shop's catalogue."
    },
    "card": {
      "view": "View product",
      "add": "Add to cart",
      "noImage": "No image available",
      "inStock": "In stock",
      "lowStock": "Low stock",
      "outOfStock": "Out of stock",
      "outOfStockReason": "Out of stock, so it cannot be added to the cart",
      "parentStock": "Stock shown for the product, not this variant.",
      "delivery": "Delivery: %time%"
    },
    "thinking": {
      "start": "Thinking…",
      "waiting": "Still looking. Give me a sec.",
      "patient": "This one's taking a moment."
    },
    "warning": {
      "availability": "This is currently out of stock. The card below is correct.",
      "price": "The prices on the cards are the ones that apply."
    },
    "error": {
      "network": "That didn't get through. Try again?",
      "retry": "Try again",
      "unavailable": "The assistant is unavailable right now.",
      "tooLong": "That message is too long. Please shorten it.",
      "cartFailed": "Could not add this to the cart."
    }
  }
}
```

`src/Resources/snippet/de_DE/storefront.de-DE.json` — same keys, German values:

```json
{
  "swagAssistant": {
    "orb": {
      "open": "Einkaufsassistenten öffnen",
      "nudge": "Frag mich alles über diesen Shop"
    },
    "panel": {
      "close": "Assistenten schließen",
      "inputLabel": "Ihre Nachricht",
      "inputPlaceholder": "Frage zum Shop stellen…",
      "send": "Senden",
      "defaultGreeting": "Hallo. Ich kann im Katalog dieses Shops nachsehen."
    },
    "card": {
      "view": "Produkt ansehen",
      "add": "In den Warenkorb",
      "noImage": "Kein Bild verfügbar",
      "inStock": "Auf Lager",
      "lowStock": "Wenig auf Lager",
      "outOfStock": "Nicht auf Lager",
      "outOfStockReason": "Nicht auf Lager und kann daher nicht hinzugefügt werden",
      "parentStock": "Lagerbestand gilt für das Produkt, nicht für diese Variante.",
      "delivery": "Lieferung: %time%"
    },
    "thinking": {
      "start": "Ich denke nach…",
      "waiting": "Ich suche noch. Einen Moment.",
      "patient": "Das dauert diesmal etwas länger."
    },
    "warning": {
      "availability": "Dieser Artikel ist nicht auf Lager. Die Karte unten ist korrekt.",
      "price": "Es gelten die Preise auf den Karten."
    },
    "error": {
      "network": "Das kam nicht durch. Nochmal versuchen?",
      "retry": "Nochmal versuchen",
      "unavailable": "Der Assistent ist gerade nicht verfügbar.",
      "tooLong": "Diese Nachricht ist zu lang. Bitte kürzen.",
      "cartFailed": "Konnte nicht in den Warenkorb gelegt werden."
    }
  }
}
```

- [ ] **Step 8: Write the templates**

`src/Resources/views/storefront/base.html.twig`:

```twig
{% sw_extends '@Storefront/storefront/base.html.twig' %}

{% block base_body_inner %}
    {{ parent() }}

    {# Gated in PHP: an unconfigured shop, a killed assistant, or a merchant who switched the widget
       off renders nothing at all. Excluding specific pages (checkout, for instance) is a matter of
       wrapping this include in your own condition — that is the documented extension point. #}
    {% if swag_assistant_widget_enabled(context.salesChannelId) %}
        {% sw_include '@Storefront/storefront/component/assistant/orb.html.twig' %}
    {% endif %}
{% endblock %}
```

`src/Resources/views/storefront/component/assistant/orb.html.twig`:

```twig
{% block swag_assistant_root %}
    <div class="swag-assistant"
         data-swag-assistant-root
         data-chat-url="{{ path('frontend.assistant.chat') }}"
         data-history-url="{{ path('frontend.assistant.history') }}"
         data-cart-url="{{ path('frontend.checkout.line-item.add') }}"
         data-locale="{{ app.request.locale|replace({'_': '-'}) }}"
         data-assistant-name="{{ swag_assistant_widget_name(context.salesChannelId) }}"
         data-greeting="{{ swag_assistant_greeting(context.salesChannelId) }}"
         data-add-to-cart-enabled="{{ swag_assistant_add_to_cart_enabled(context.salesChannelId) ? 'true' : 'false' }}">

        {% block swag_assistant_orb %}
            <button class="swag-assistant-orb"
                    type="button"
                    data-swag-assistant-orb
                    aria-label="{{ 'swagAssistant.orb.open'|trans|sw_sanitize }}"
                    aria-expanded="false">
                <span class="swag-assistant-orb__face" aria-hidden="true">
                    <span class="swag-assistant-orb__eye"></span>
                    <span class="swag-assistant-orb__eye"></span>
                    {% block swag_assistant_orb_signet %}
                        <span class="swag-assistant-orb__signet"></span>
                    {% endblock %}
                </span>
            </button>
        {% endblock %}

        {% block swag_assistant_nudge %}
            <span class="swag-assistant-nudge" data-swag-assistant-nudge hidden>
                {{ 'swagAssistant.orb.nudge'|trans|sw_sanitize }}
            </span>
        {% endblock %}
    </div>
{% endblock %}
```

- [ ] **Step 9: Write the tokens and orb styles**

`src/Resources/app/storefront/src/scss/base.scss`:

```scss
@import 'components/tokens';
@import 'components/orb';
```

`src/Resources/app/storefront/src/scss/components/_tokens.scss`:

```scss
// Brand palette only — every value traces to the Shopware brand spec. A new value here is a
// deliberate system addition, never a one-off.
$swag-assistant-surface: #ffffff;
$swag-assistant-text: #00153e;          // Night Blue
$swag-assistant-meta: #a3a6b5;          // Light Gray
$swag-assistant-accent: #0870ff;        // SW 500 — the one accent colour
$swag-assistant-brand: #189eff;         // Bright Blue — orb only
$swag-assistant-accent-dark: #005cd7;   // SW 600
$swag-assistant-bubble: #f0f6ff;        // SW 50
$swag-assistant-hairline: #e6e9f0;
$swag-assistant-in-stock: #57d998;      // Teal
$swag-assistant-low-stock: #f88138;     // Orange — also the warning colour; the palette has no red
$swag-assistant-out-of-stock: #838489;  // Dark Gray

// Three type steps, ratios 1.33 and 1.25. Do not add a fourth.
$swag-assistant-font-meta: 12px;
$swag-assistant-font-body: 16px;
$swag-assistant-font-heading: 20px;

$swag-assistant-radius-panel: 12px;
$swag-assistant-radius-card: 8px;
$swag-assistant-radius-control: 6px;

$swag-assistant-orb-size: 60px;
$swag-assistant-orb-inset: 20px;
$swag-assistant-panel-width: 420px;
$swag-assistant-z: 1035; // above $scroll-up-zindex
```

`src/Resources/app/storefront/src/scss/components/_orb.scss`:

```scss
.swag-assistant {
    position: fixed;
    right: $swag-assistant-orb-inset;
    bottom: $swag-assistant-orb-inset;
    z-index: $swag-assistant-z;
}

// The theme's scroll-up button is fixed to this exact corner. Lift it above the orb, scoped to
// pages where the widget actually renders so a shop with the widget off is untouched.
body:has(.swag-assistant) .scroll-up-button {
    bottom: $swag-assistant-orb-inset + $swag-assistant-orb-size + 12px;
}

.swag-assistant-orb {
    position: relative;
    width: $swag-assistant-orb-size;
    height: $swag-assistant-orb-size;
    padding: 0;
    border: 0;
    border-radius: 50%;
    cursor: pointer;

    // The one gradient and the one coloured shadow in the entire widget. Both are deliberate:
    // the orb is a brand mark, and a flat fill throws away what makes it read as a character.
    background: radial-gradient(circle at 32% 28%, #4a8fff 0%, $swag-assistant-accent 45%, $swag-assistant-accent-dark 100%);
    box-shadow: 0 0 0 6px rgba(24, 158, 255, 0.10), 0 8px 24px rgba(0, 21, 62, 0.18);

    animation: swag-assistant-breathe 4s ease-in-out infinite;
    transition: transform 150ms cubic-bezier(0.2, 0.9, 0.25, 1),
                box-shadow 150ms cubic-bezier(0.2, 0.9, 0.25, 1);

    &::after { // the luminous rim
        content: '';
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 1.5px solid rgba(24, 158, 255, 0.5);
    }

    &:hover {
        transform: scale(1.06);
        box-shadow: 0 0 0 9px rgba(24, 158, 255, 0.14), 0 10px 28px rgba(0, 21, 62, 0.22);
    }

    &:focus-visible {
        outline: 2px solid $swag-assistant-accent;
        outline-offset: 2px;
    }
}

.swag-assistant-orb__face {
    position: absolute;
    inset: 0;
}

.swag-assistant-orb__eye {
    position: absolute;
    top: 38%;
    width: 7px;
    height: 14px;
    border-radius: 3.5px;
    background: #ffffff;
    // Randomised per page load by orb.plugin.js so two shoppers never blink in lockstep.
    animation: swag-assistant-blink 7s var(--swag-assistant-blink-delay, 0ms) infinite;

    &:first-of-type { left: 30%; }
    &:last-of-type { right: 30%; }

    .swag-assistant-orb:hover & { transform: translateY(-1.5px); }
}

.swag-assistant-orb__signet {
    position: absolute;
    right: 24%;
    bottom: 22%;
    width: 16px;
    height: 16px;
    opacity: 0.4;
    // Replace with the Shopware signet asset. Override the swag_assistant_orb_signet Twig block
    // to remove or replace it in a merchant's own build.
    background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg"/>') no-repeat center / contain;
}

.swag-assistant-nudge {
    position: absolute;
    right: $swag-assistant-orb-size + 12px;
    bottom: 18px;
    padding: 8px 12px;
    border-radius: $swag-assistant-radius-control;
    background: $swag-assistant-surface;
    box-shadow: 0 4px 16px rgba(0, 21, 62, 0.14);
    color: $swag-assistant-text;
    font-size: $swag-assistant-font-meta;
    white-space: nowrap;
}

@keyframes swag-assistant-breathe {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.025); }
}

@keyframes swag-assistant-blink {
    0%, 95%, 100% { transform: scaleY(1); }
    97% { transform: scaleY(0.1); }
}

@media (prefers-reduced-motion: reduce) {
    .swag-assistant-orb { animation: none; transition: none; }
    .swag-assistant-orb__eye { animation: none; }
    .swag-assistant-orb:hover { transform: none; }
}
```

The signet is a placeholder `data:` URI. Replace it with the real asset from
`~/.agents/skills/shopware-brand-spec/assets/logos` (an approved standalone signet), inlined or
referenced from `Resources/public`.

- [ ] **Step 10: Verify in the real storefront**

```bash
cd ~/Workspace/shopping-assistant-test
bin/console cache:clear && bin/console theme:compile
curl -s http://127.0.0.1:8000/ | grep -c 'data-swag-assistant-root'
```

Expected: `1`. Then prove the gate works in both directions:

```bash
bin/console system:config:set SwagAssistantStarterKit.config.killSwitch true
curl -s http://127.0.0.1:8000/ | grep -c 'data-swag-assistant-root'   # expect 0
bin/console system:config:set SwagAssistantStarterKit.config.killSwitch false
curl -s http://127.0.0.1:8000/ | grep -c 'data-swag-assistant-root'   # expect 1
```

**A grep count of 0 in the first case is the point of this task.** If the orb renders with the kill
switch on, stop and fix it before moving on.

- [ ] **Step 11: Run the gate and commit**

```bash
composer run test && composer run quality
git add src/Core/Twig src/Core/Config/SystemConfigWidgetSettings.php src/Resources tests/Core/Twig
git commit -m "feat: render the assistant orb, gated on a shop that can answer"
```

---

### Task 2: The panel opens, closes, and is reachable by keyboard

**Files:**
- Create: `src/Resources/app/storefront/src/main.js`
- Create: `src/Resources/app/storefront/src/assistant/orb.plugin.js`
- Create: `src/Resources/app/storefront/src/assistant/panel.plugin.js`
- Create: `src/Resources/views/storefront/component/assistant/panel.html.twig`
- Create: `src/Resources/app/storefront/src/scss/components/_panel.scss`
- Modify: `src/Resources/views/storefront/component/assistant/orb.html.twig` (include the panel)
- Modify: `src/Resources/app/storefront/src/scss/base.scss` (import `_panel`)
- Modify: `composer.json` (add `build:storefront`)
- Modify: `.github/workflows/quality-gate-strict.yml` (dist drift check)

**Interfaces:**
- Consumes: the DOM contract from Task 1.
- Produces: `SwagAssistantPanel` plugin instance exposing `open()`, `close()`, and the DOM hooks later tasks render into — `[data-swag-assistant-log]` (the message list), `[data-swag-assistant-form]`, `[data-swag-assistant-input]`, `[data-swag-assistant-send]`. Custom events `swag-assistant:open` and `swag-assistant:close` on the root element.

- [ ] **Step 1: Write `main.js` — registrations only, all lazy**

```javascript
// Lazy registration, the shape SwagPayPal uses. The orb chunk is tiny and loads on every page; the
// panel chunk — rendering, transport, the thinking choreography — downloads only when a shopper
// first opens the assistant. That split is why page-load cost is effectively zero.
const PluginManager = window.PluginManager;

PluginManager.register(
    'SwagAssistantOrb',
    () => import('./assistant/orb.plugin'),
    '[data-swag-assistant-orb]',
);

PluginManager.register(
    'SwagAssistantPanel',
    () => import('./assistant/panel.plugin'),
    '[data-swag-assistant-root]',
);
```

- [ ] **Step 2: Write `orb.plugin.js`**

```javascript
const { PluginBaseClass } = window;

const NUDGE_DELAY_MS = 4000;
const NUDGE_VISIBLE_MS = 6000;
const NUDGE_SEEN_KEY = 'swagAssistantNudgeSeen';

export default class SwagAssistantOrb extends PluginBaseClass {
    init() {
        this.root = this.el.closest('[data-swag-assistant-root]');
        this.nudge = this.root?.querySelector('[data-swag-assistant-nudge]');

        this._varyBlink();
        this._scheduleNudge();

        this.el.addEventListener('click', () => {
            this._hideNudge();
            this.root?.dispatchEvent(new CustomEvent('swag-assistant:toggle'));
        });
    }

    /**
     * Blink phase, not blink existence. Without this every orb on every open tab blinks in lockstep,
     * which reads as a synchronised animation rather than a creature.
     */
    _varyBlink() {
        this.el.style.setProperty('--swag-assistant-blink-delay', `${Math.floor(Math.random() * 5000)}ms`);
    }

    _scheduleNudge() {
        if (!this.nudge || window.sessionStorage.getItem(NUDGE_SEEN_KEY) === '1') {
            return;
        }

        window.setTimeout(() => {
            if (this.root?.classList.contains('is-open')) {
                return;
            }

            this.nudge.hidden = false;
            window.sessionStorage.setItem(NUDGE_SEEN_KEY, '1');
            window.setTimeout(() => this._hideNudge(), NUDGE_VISIBLE_MS);
        }, NUDGE_DELAY_MS);
    }

    _hideNudge() {
        if (this.nudge) {
            this.nudge.hidden = true;
        }
    }
}
```

- [ ] **Step 3: Write the panel template**

Add to `orb.html.twig`, inside `swag_assistant_root` after the nudge block:

```twig
        {% block swag_assistant_panel %}
            {% sw_include '@Storefront/storefront/component/assistant/panel.html.twig' %}
        {% endblock %}
```

`src/Resources/views/storefront/component/assistant/panel.html.twig`:

```twig
{% block swag_assistant_panel_inner %}
    {# Not aria-modal on desktop: the panel is genuinely non-modal, the storefront behind it stays
       usable, and claiming modality to a screen reader when the page is still reachable is a lie. #}
    <section class="swag-assistant-panel"
             data-swag-assistant-panel
             role="dialog"
             aria-modal="false"
             aria-label="{{ swag_assistant_widget_name(context.salesChannelId) }}"
             hidden>

        {% block swag_assistant_panel_header %}
            <header class="swag-assistant-panel__header">
                <span class="swag-assistant-panel__avatar" aria-hidden="true"></span>
                <h2 class="swag-assistant-panel__title">
                    {{ swag_assistant_widget_name(context.salesChannelId) }}
                </h2>
                <button class="swag-assistant-panel__close"
                        type="button"
                        data-swag-assistant-close
                        aria-label="{{ 'swagAssistant.panel.close'|trans|sw_sanitize }}">
                    &times;
                </button>
            </header>
        {% endblock %}

        {% block swag_assistant_panel_log %}
            <div class="swag-assistant-log"
                 data-swag-assistant-log
                 role="log"
                 aria-live="polite"
                 aria-relevant="additions"></div>
        {% endblock %}

        {% block swag_assistant_panel_composer %}
            <form class="swag-assistant-composer" data-swag-assistant-form>
                <label class="swag-assistant-composer__label"
                       for="swag-assistant-input">{{ 'swagAssistant.panel.inputLabel'|trans|sw_sanitize }}</label>
                <textarea class="swag-assistant-composer__input"
                          id="swag-assistant-input"
                          data-swag-assistant-input
                          rows="1"
                          maxlength="2000"
                          placeholder="{{ 'swagAssistant.panel.inputPlaceholder'|trans|sw_sanitize }}"></textarea>
                <button class="swag-assistant-composer__send"
                        type="submit"
                        data-swag-assistant-send>{{ 'swagAssistant.panel.send'|trans|sw_sanitize }}</button>
            </form>
        {% endblock %}
    </section>
{% endblock %}
```

The `<label>` is visually hidden in CSS, not omitted — a placeholder is not an accessible name.

- [ ] **Step 4: Write `panel.plugin.js`**

```javascript
const { PluginBaseClass } = window;

const FOCUSABLE = 'button:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

export default class SwagAssistantPanel extends PluginBaseClass {
    init() {
        this.panel = this.el.querySelector('[data-swag-assistant-panel]');
        this.orb = this.el.querySelector('[data-swag-assistant-orb]');
        this.input = this.el.querySelector('[data-swag-assistant-input]');

        this.el.addEventListener('swag-assistant:toggle', () => this.toggle());
        this.el.querySelector('[data-swag-assistant-close]')
            ?.addEventListener('click', () => this.close());

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.isOpen()) {
                this.close();
            }
        });

        this.panel?.addEventListener('keydown', (event) => this._trapTab(event));
    }

    isOpen() {
        return this.el.classList.contains('is-open');
    }

    toggle() {
        this.isOpen() ? this.close() : this.open();
    }

    open() {
        this.panel.hidden = false;
        // Two frames: one to let `hidden` removal take effect, one so the transition has a start
        // state to animate from. Without this the panel appears fully open with no motion.
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
            this.el.classList.add('is-open');
        }));
        this.orb?.setAttribute('aria-expanded', 'true');
        this.input?.focus();
        this.el.dispatchEvent(new CustomEvent('swag-assistant:open'));
    }

    close() {
        this.el.classList.remove('is-open');
        this.orb?.setAttribute('aria-expanded', 'false');
        // Focus goes back where it came from. A shopper who closes with Escape must not be dropped
        // at the top of the document.
        this.orb?.focus();
        this.el.dispatchEvent(new CustomEvent('swag-assistant:close'));

        const done = () => { this.panel.hidden = true; };
        this.panel.addEventListener('transitionend', done, { once: true });
        // Fallback for reduced-motion, where no transition fires at all.
        window.setTimeout(done, 400);
    }

    _trapTab(event) {
        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [...this.panel.querySelectorAll(FOCUSABLE)];
        if (focusable.length === 0) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}
```

- [ ] **Step 5: Write `_panel.scss`**

```scss
.swag-assistant-panel {
    position: absolute;
    right: 0;
    bottom: $swag-assistant-orb-size + 16px;
    display: flex;
    flex-direction: column;
    width: $swag-assistant-panel-width;
    max-width: calc(100vw - #{$swag-assistant-orb-inset * 2});
    height: min(640px, calc(100vh - 7rem));
    border-radius: $swag-assistant-radius-panel;
    background: $swag-assistant-surface;
    // Elevation only — no border. One decision per surface.
    box-shadow: 0 12px 40px rgba(0, 21, 62, 0.16);
    color: $swag-assistant-text;
    font-size: $swag-assistant-font-body;
    overflow: hidden;

    // Morphs from the orb: radius and scale, never width or height.
    transform: scale(0.92) translateY(8px);
    opacity: 0;
    border-radius: 30px;
    transition: transform 320ms cubic-bezier(0.2, 0.9, 0.25, 1),
                opacity 200ms cubic-bezier(0.2, 0.9, 0.25, 1),
                border-radius 320ms cubic-bezier(0.2, 0.9, 0.25, 1);

    .swag-assistant.is-open & {
        transform: scale(1) translateY(0);
        opacity: 1;
        border-radius: $swag-assistant-radius-panel;
    }
}

.swag-assistant-panel__header {
    display: flex;
    gap: 10px;
    align-items: center;
    height: 56px;
    padding: 0 12px 0 16px;
    border-bottom: 1px solid $swag-assistant-hairline;
}

.swag-assistant-panel__avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: radial-gradient(circle at 32% 28%, #4a8fff 0%, $swag-assistant-accent 45%, $swag-assistant-accent-dark 100%);
    flex: 0 0 auto;
}

.swag-assistant-panel__title {
    margin: 0;
    font-size: $swag-assistant-font-heading;
    font-weight: 600;
    letter-spacing: -0.01em;
    flex: 1 1 auto;
}

.swag-assistant-panel__close {
    border: 0;
    background: none;
    color: $swag-assistant-meta;
    font-size: $swag-assistant-font-heading;
    line-height: 1;
    cursor: pointer;

    &:focus-visible { outline: 2px solid $swag-assistant-accent; outline-offset: 2px; }
}

.swag-assistant-log {
    flex: 1 1 auto;
    padding: 16px;
    overflow-y: auto;
    overflow-x: hidden;
}

.swag-assistant-composer {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    padding: 12px 16px 16px;
    border-top: 1px solid $swag-assistant-hairline;
}

.swag-assistant-composer__label {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}

.swag-assistant-composer__input {
    flex: 1 1 auto;
    max-height: 120px;
    padding: 10px 12px;
    border: 1px solid $swag-assistant-hairline;
    border-radius: $swag-assistant-radius-control;
    font: inherit;
    font-size: $swag-assistant-font-body;
    resize: none;

    &:focus-visible { outline: 2px solid $swag-assistant-accent; outline-offset: 1px; }
}

.swag-assistant-composer__send {
    padding: 10px 14px;
    border: 0;
    border-radius: $swag-assistant-radius-control;
    background: $swag-assistant-accent;
    color: #ffffff;
    font: inherit;
    font-weight: 600;
    cursor: pointer;

    &:disabled { background: $swag-assistant-out-of-stock; cursor: not-allowed; }
    &:focus-visible { outline: 2px solid $swag-assistant-accent; outline-offset: 2px; }
}

// Full-screen sheet on mobile.
@media (max-width: 575px) {
    .swag-assistant-panel {
        position: fixed;
        inset: 0;
        width: 100vw;
        max-width: none;
        height: 100vh;
        border-radius: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .swag-assistant-panel { transition: none; }
}
```

- [ ] **Step 6: Add the build script and CI drift check**

In `composer.json` `scripts`:

```json
    "build:storefront": "shopware-cli project storefront-build"
```

Note: `shopware-cli project storefront-build` must run against the **shop**, not this repo. Document
in the README that the shop bind-mounts this plugin at `plugin-src`, so building in
`~/Workspace/shopping-assistant-test` writes `dist/` straight back here.

In `.github/workflows/quality-gate-strict.yml`, add a job after `Quality`:

```yaml
  storefront-dist:
    name: Storefront dist matches src
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '24'

      - name: Setup shopware-cli
        uses: shopware/shopware-cli-action@v1

      # A committed artefact that can silently disagree with its source is the same failure shape as
      # the lessons in docs/HANDOFF.md. Rebuild and require the diff to be empty.
      - name: Rebuild storefront assets
        run: shopware-cli extension build .

      - name: Fail on drift
        run: git diff --exit-code -- src/Resources/app/storefront/dist
```

Verify `shopware/shopware-cli-action@v1` resolves; if it does not, install the binary with a `curl`
step instead. Do not leave the job referencing an action that does not exist.

- [ ] **Step 7: Build and verify by hand**

```bash
cd ~/Workspace/shopping-assistant-test
shopware-cli project storefront-build
bin/console theme:compile
ls -la plugin-src/src/Resources/app/storefront/dist/storefront/js/
```

Expected: a directory containing at least the entry file. Then open
`http://127.0.0.1:8000/` in a browser: clicking the orb opens the panel, `Escape` closes it, focus
returns to the orb, and `Tab` stays inside the panel while it is open.

- [ ] **Step 8: Commit**

```bash
git add src/Resources composer.json .github/workflows/quality-gate-strict.yml
git commit -m "feat: open and close the assistant panel"
```

---

### Task 3: A real conversation renders

**Files:**
- Create: `src/Resources/app/storefront/src/assistant/transport.js`
- Create: `src/Resources/app/storefront/src/assistant/render.js`
- Create: `src/Resources/app/storefront/src/scss/components/_message.scss`
- Modify: `src/Resources/app/storefront/src/assistant/panel.plugin.js`
- Modify: `src/Resources/app/storefront/src/scss/base.scss`

**Interfaces:**
- Consumes: `SwagAssistantPanel` DOM hooks from Task 2.
- Produces:
  - `transport.js`: `createTransport({ chatUrl, historyUrl, cartUrl })` → `{ send(message, token), history(token), addToCart(productId, quantity) }`. `send` resolves `{ token, prose, cards, outcome, warnings }` and rejects with an `Error` carrying a numeric `status` property. `history` resolves `{ messages: [...] }`.
  - `render.js`: `renderMessage(log, { role, prose, cards, warnings, createdAt, animate })` → the appended element; `formatTime(value, locale)` → string; `formatPrice(amount, currency, locale)` → string.

- [ ] **Step 1: Write `transport.js`**

```javascript
/**
 * The only place that talks to the server.
 *
 * Errors carry the HTTP status so the caller can distinguish "the shop has no model" (503) from
 * "your message is too long" (400) from a dropped connection — three states a shopper must be told
 * apart, per the spec's state table.
 */
export function createTransport({ chatUrl, historyUrl, cartUrl }) {
    async function send(message, token) {
        const response = await fetch(chatUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(token ? { message, token } : { message }),
        });

        if (!response.ok) {
            const error = new Error(`Assistant responded ${response.status}`);
            error.status = response.status;
            throw error;
        }

        return response.json();
    }

    async function history(token) {
        if (!token) {
            return { messages: [] };
        }

        const response = await fetch(`${historyUrl}?token=${encodeURIComponent(token)}`);

        if (!response.ok) {
            return { messages: [] };
        }

        return response.json();
    }

    async function addToCart(productId, quantity = 1) {
        // Shopware's own cart route. The shop keeps ownership of cart rules; we add no cart logic.
        // 6.7 has no CSRF layer, so no token is needed.
        const body = new FormData();
        body.append(`lineItems[${productId}][id]`, productId);
        body.append(`lineItems[${productId}][referencedId]`, productId);
        body.append(`lineItems[${productId}][type]`, 'product');
        body.append(`lineItems[${productId}][quantity]`, String(quantity));

        const response = await fetch(cartUrl, {
            method: 'POST',
            body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            const error = new Error(`Cart responded ${response.status}`);
            error.status = response.status;
            throw error;
        }

        return response;
    }

    return { send, history, addToCart };
}
```

- [ ] **Step 2: Write `render.js` (messages and timestamps; cards come in Task 4)**

```javascript
const ROLE_USER = 'user';

export function formatTime(value, locale) {
    const date = value ? new Date(value) : new Date();

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(date);
}

export function formatPrice(amount, currency, locale) {
    // The server sends 74.9 and "EUR", never a formatted string. Formatting is the client's job and
    // must follow the storefront's locale, not the browser's default.
    return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(amount);
}

/**
 * User messages are contained; assistant messages are unbounded. That contrast is the whole
 * hierarchy — it reads as the shop speaking rather than a peer in a group chat, so no avatar or
 * accent rule is added on top of it.
 *
 * `animate` is false for rehydrated history. Nothing sits at opacity 0 at rest: JS enhances an
 * entrance, it never gates existence.
 */
export function renderMessage(log, { role, prose, createdAt, locale, animate = true }) {
    const wrapper = document.createElement('div');
    wrapper.className = `swag-assistant-message swag-assistant-message--${role === ROLE_USER ? 'user' : 'assistant'}`;

    if (animate) {
        wrapper.classList.add('is-entering');
    }

    const body = document.createElement('div');
    body.className = 'swag-assistant-message__body';
    // textContent, not innerHTML. The prose is model output rendered as plain text with paragraph
    // breaks; a markdown parser would be an XSS surface for no established benefit.
    prose.split(/\n{2,}/).forEach((paragraph) => {
        const p = document.createElement('p');
        p.textContent = paragraph.trim();
        body.appendChild(p);
    });
    wrapper.appendChild(body);

    const time = document.createElement('time');
    time.className = 'swag-assistant-message__time';
    const stamp = createdAt ? new Date(createdAt) : new Date();
    if (!Number.isNaN(stamp.getTime())) {
        time.dateTime = stamp.toISOString();
    }
    time.textContent = formatTime(createdAt, locale);
    wrapper.appendChild(time);

    log.appendChild(wrapper);
    log.scrollTop = log.scrollHeight;

    if (animate) {
        window.requestAnimationFrame(() => wrapper.classList.remove('is-entering'));
    }

    return wrapper;
}
```

- [ ] **Step 3: Wire the panel to the transport**

Add to `panel.plugin.js` — extend `init()` and add the methods:

```javascript
import { createTransport } from './transport';
import { renderMessage } from './render';

const TOKEN_KEY = 'swagAssistantToken';

// ... inside init(), after the existing wiring:
        this.log = this.el.querySelector('[data-swag-assistant-log]');
        this.form = this.el.querySelector('[data-swag-assistant-form]');
        this.send = this.el.querySelector('[data-swag-assistant-send]');
        this.locale = this.el.dataset.locale || 'en-GB';
        this.transport = createTransport({
            chatUrl: this.el.dataset.chatUrl,
            historyUrl: this.el.dataset.historyUrl,
            cartUrl: this.el.dataset.cartUrl,
        });

        this.form.addEventListener('submit', (event) => {
            event.preventDefault();
            this._submit();
        });

        // Enter sends, Shift+Enter makes a newline — the convention every chat interface uses.
        this.input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                this._submit();
            }
        });

        this.el.addEventListener('swag-assistant:open', () => this._hydrateOnce(), { once: true });
```

```javascript
    async _hydrateOnce() {
        const token = window.sessionStorage.getItem(TOKEN_KEY);

        if (!token) {
            this._renderGreeting();
            return;
        }

        const { messages } = await this.transport.history(token);

        if (messages.length === 0) {
            this._renderGreeting();
            return;
        }

        // animate: false — rehydrated history is content, not an event.
        messages.forEach((message) => renderMessage(this.log, {
            role: message.role,
            prose: message.prose,
            createdAt: message.createdAt,
            locale: this.locale,
            animate: false,
        }));
    }

    _renderGreeting() {
        const greeting = this.el.dataset.greeting?.trim();

        if (!greeting) {
            return;
        }

        renderMessage(this.log, { role: 'assistant', prose: greeting, locale: this.locale, animate: false });
    }

    async _submit() {
        const message = this.input.value.trim();

        if (message === '' || this.isBusy) {
            return;
        }

        this.isBusy = true;
        this.send.disabled = true;
        this.input.value = '';

        renderMessage(this.log, { role: 'user', prose: message, locale: this.locale });

        try {
            const reply = await this.transport.send(message, window.sessionStorage.getItem(TOKEN_KEY));
            window.sessionStorage.setItem(TOKEN_KEY, reply.token);
            renderMessage(this.log, {
                role: 'assistant',
                prose: reply.prose,
                locale: this.locale,
            });
        } finally {
            this.isBusy = false;
            this.send.disabled = false;
        }
    }
```

Error handling is deliberately absent here — Task 8 adds it as its own deliverable with its own
verification. Leaving a bare `finally` for one task is honest; shipping it is not.

- [ ] **Step 4: Write `_message.scss`**

```scss
.swag-assistant-message {
    margin-bottom: 20px;

    &.is-entering {
        transform: translateY(4px);
        opacity: 0;
    }

    transform: translateY(0);
    opacity: 1;
    transition: transform 240ms cubic-bezier(0.2, 0.9, 0.25, 1),
                opacity 240ms cubic-bezier(0.2, 0.9, 0.25, 1);
}

// Assistant: unbounded, full width, no container.
.swag-assistant-message--assistant {
    .swag-assistant-message__body p {
        margin: 0 0 8px;
        font-size: $swag-assistant-font-body;
        line-height: 1.55;

        &:last-child { margin-bottom: 0; }
    }
}

// User: contained, right-aligned.
.swag-assistant-message--user {
    display: flex;
    flex-direction: column;
    align-items: flex-end;

    .swag-assistant-message__body {
        max-width: 85%;
        padding: 10px 12px;
        border-radius: $swag-assistant-radius-panel $swag-assistant-radius-panel 4px $swag-assistant-radius-panel;
        background: $swag-assistant-bubble;
    }

    .swag-assistant-message__body p {
        margin: 0;
        font-size: $swag-assistant-font-body;
        line-height: 1.45;
    }
}

.swag-assistant-message__time {
    display: block;
    margin-top: 6px;
    color: $swag-assistant-meta;
    font-size: $swag-assistant-font-meta;
    font-weight: 500;
}

@media (prefers-reduced-motion: reduce) {
    .swag-assistant-message { transition: none; }
}
```

- [ ] **Step 5: Verify against the real shop**

Rebuild, then in the browser: open the panel, send *"show me the trail jersey in blue, size M"*, and
confirm after ~19 s that the reply renders with a timestamp. Then **reload the page, reopen the
panel** and confirm the conversation is still there.

Rehydrated messages will have **no timestamp** until Task 9 — that is expected at this point, not a
bug to chase.

- [ ] **Step 6: Commit**

```bash
cd ~/Workspace/shopping-assistant-starter-kit
git add src/Resources
git commit -m "feat: send a message and render the conversation"
```

---

### Task 4: Product cards

**Files:**
- Create: `src/Resources/app/storefront/src/assistant/card.js`
- Create: `src/Resources/app/storefront/src/scss/components/_card.scss`
- Modify: `src/Resources/app/storefront/src/assistant/render.js`
- Modify: `src/Resources/app/storefront/src/scss/base.scss`

**Interfaces:**
- Consumes: `formatPrice(amount, currency, locale)` from Task 3.
- Produces: `renderCards(container, cards, { locale, addToCartEnabled, translations })` → the appended element. `renderMessage` gains a `cards` option that delegates to it.

- [ ] **Step 1: Write `card.js`**

```javascript
import { formatPrice } from './render';

const STOCK_LOW_THRESHOLD = 5;

/**
 * One card, built entirely from server-rendered fields.
 *
 * Every figure here comes from the `cards[]` payload. Nothing is read out of `prose` — that rule is
 * the product's central claim (D3), and this function is where a client would most easily break it.
 */
function buildCard(card, { locale, addToCartEnabled, translations }) {
    const el = document.createElement('article');
    el.className = 'swag-assistant-card';
    el.dataset.productId = card.id;

    el.appendChild(buildMedia(card, translations));

    const info = document.createElement('div');
    info.className = 'swag-assistant-card__info';

    const name = document.createElement('h3');
    name.className = 'swag-assistant-card__name';
    name.textContent = card.name;
    info.appendChild(name);

    const options = Object.values(card.options ?? {});
    if (options.length > 0) {
        const variant = document.createElement('p');
        variant.className = 'swag-assistant-card__options';
        variant.textContent = options.join(' · ');
        info.appendChild(variant);
    }

    const price = document.createElement('p');
    price.className = 'swag-assistant-card__price';
    price.textContent = formatPrice(card.price, card.currency, locale);
    info.appendChild(price);

    info.appendChild(buildStock(card, translations));

    // `stockSource` says whether a stock figure belongs to the variant asked about or to its parent.
    // A client cannot infer it, and a shopper told the parent's number is the shopper whose order
    // gets cancelled. This is D4 made visible, and nothing else in the product says it.
    if (card.stockSource === 'parent') {
        const note = document.createElement('p');
        note.className = 'swag-assistant-card__note';
        note.textContent = translations.parentStock;
        info.appendChild(note);
    }

    if (card.deliveryTime) {
        const delivery = document.createElement('p');
        delivery.className = 'swag-assistant-card__delivery';
        delivery.textContent = translations.delivery.replace('%time%', card.deliveryTime);
        info.appendChild(delivery);
    }

    info.appendChild(buildActions(card, { addToCartEnabled, translations }));
    el.appendChild(info);

    return el;
}

function buildMedia(card, translations) {
    const media = document.createElement('div');
    media.className = 'swag-assistant-card__media';

    if (!card.imageUrl) {
        // A designed absence. Every fixture product in the demo catalogue has imageUrl null, so this
        // is the common path, not the edge case.
        media.classList.add('swag-assistant-card__media--empty');
        media.setAttribute('role', 'img');
        media.setAttribute('aria-label', translations.noImage);
        return media;
    }

    const img = document.createElement('img');
    img.src = card.imageUrl;
    img.alt = card.name;
    img.loading = 'lazy';
    media.appendChild(img);

    return media;
}

function buildStock(card, translations) {
    const stock = document.createElement('p');
    stock.className = 'swag-assistant-card__stock';

    let state = 'out';
    let label = translations.outOfStock;

    if (card.inStock) {
        const low = typeof card.stock === 'number' && card.stock <= STOCK_LOW_THRESHOLD;
        state = low ? 'low' : 'in';
        label = low ? translations.lowStock : translations.inStock;
    }

    stock.classList.add(`swag-assistant-card__stock--${state}`);

    const dot = document.createElement('span');
    dot.className = 'swag-assistant-card__dot';
    dot.setAttribute('aria-hidden', 'true');
    stock.appendChild(dot);

    // The label is always present. Status is never signalled by colour alone.
    stock.appendChild(document.createTextNode(label));

    return stock;
}

function buildActions(card, { addToCartEnabled, translations }) {
    const actions = document.createElement('div');
    actions.className = 'swag-assistant-card__actions';

    const view = document.createElement('a');
    view.className = 'swag-assistant-card__view';
    view.href = card.url;
    view.textContent = translations.view;
    actions.appendChild(view);

    if (!addToCartEnabled) {
        return actions;
    }

    const add = document.createElement('button');
    add.className = 'swag-assistant-card__add';
    add.type = 'button';
    add.dataset.swagAssistantAdd = card.id;
    add.textContent = translations.add;

    if (!card.inStock) {
        add.disabled = true;
        // A disabled control must say why. Grey alone is not a reason.
        add.title = translations.outOfStockReason;
        add.setAttribute('aria-label', `${translations.add} — ${translations.outOfStockReason}`);
    }

    actions.appendChild(add);

    return actions;
}

/**
 * One card is an answer; several are a shortlist. A single decisive result gets the full-width hero
 * treatment because that is the moment price and stock must be unmissable; multiple results become a
 * scroll row so the panel itself never scrolls sideways.
 */
export function renderCards(container, cards, options) {
    if (!Array.isArray(cards) || cards.length === 0) {
        return null;
    }

    const wrapper = document.createElement('div');
    wrapper.className = cards.length === 1
        ? 'swag-assistant-cards swag-assistant-cards--hero'
        : 'swag-assistant-cards swag-assistant-cards--row';

    cards.forEach((card, index) => {
        const el = buildCard(card, options);
        // Stagger, capped so a long shortlist does not turn into a slow cascade.
        el.style.setProperty('--swag-assistant-card-delay', `${Math.min(index, 6) * 40}ms`);
        wrapper.appendChild(el);
    });

    container.appendChild(wrapper);

    return wrapper;
}
```

- [ ] **Step 2: Delegate from `render.js`**

In `renderMessage`, after `wrapper.appendChild(body)` and before the `<time>` element:

```javascript
    if (Array.isArray(cards) && cards.length > 0) {
        renderCards(wrapper, cards, { locale, addToCartEnabled, translations });
    }
```

Add `cards`, `addToCartEnabled` and `translations` to the destructured options, and
`import { renderCards } from './card';` at the top. `render.js` must not grow a second
responsibility beyond delegating — if it approaches 200 lines, the card work belongs entirely in
`card.js`.

- [ ] **Step 3: Pass translations and the cart flag from the panel**

The snippets are not reachable from JS, so read them from the DOM. Add to `panel.html.twig` inside
`swag_assistant_panel_inner`:

```twig
        {% block swag_assistant_translations %}
            <script type="application/json" data-swag-assistant-translations>
                {{ {
                    view: 'swagAssistant.card.view'|trans,
                    add: 'swagAssistant.card.add'|trans,
                    noImage: 'swagAssistant.card.noImage'|trans,
                    inStock: 'swagAssistant.card.inStock'|trans,
                    lowStock: 'swagAssistant.card.lowStock'|trans,
                    outOfStock: 'swagAssistant.card.outOfStock'|trans,
                    outOfStockReason: 'swagAssistant.card.outOfStockReason'|trans,
                    parentStock: 'swagAssistant.card.parentStock'|trans,
                    delivery: 'swagAssistant.card.delivery'|trans,
                    thinkingStart: 'swagAssistant.thinking.start'|trans,
                    thinkingWaiting: 'swagAssistant.thinking.waiting'|trans,
                    thinkingPatient: 'swagAssistant.thinking.patient'|trans,
                    warningAvailability: 'swagAssistant.warning.availability'|trans,
                    warningPrice: 'swagAssistant.warning.price'|trans,
                    errorNetwork: 'swagAssistant.error.network'|trans,
                    errorRetry: 'swagAssistant.error.retry'|trans,
                    errorUnavailable: 'swagAssistant.error.unavailable'|trans,
                    errorTooLong: 'swagAssistant.error.tooLong'|trans,
                    errorCartFailed: 'swagAssistant.error.cartFailed'|trans
                }|json_encode|raw }}
            </script>
        {% endblock %}
```

In `panel.plugin.js` `init()`:

```javascript
        this.translations = JSON.parse(
            this.el.querySelector('[data-swag-assistant-translations]')?.textContent || '{}',
        );
        this.addToCartEnabled = this.el.dataset.addToCartEnabled === 'true';
```

and pass `cards: reply.cards`, `addToCartEnabled: this.addToCartEnabled`,
`translations: this.translations` into the assistant `renderMessage` call.

- [ ] **Step 4: Write `_card.scss`**

```scss
.swag-assistant-cards {
    margin-top: 12px;

    &--row {
        display: flex;
        gap: 10px;
        // The row scrolls inside its own container. The panel never scrolls sideways.
        overflow-x: auto;
        padding-bottom: 4px;
        scroll-snap-type: x proximity;

        .swag-assistant-card {
            flex: 0 0 168px;
            flex-direction: column;
            scroll-snap-align: start;
        }

        .swag-assistant-card__media { width: 100%; height: 100px; }
    }
}

.swag-assistant-card {
    display: flex;
    gap: 12px;
    padding: 12px;
    // A 1px border and no shadow: cards sit in the flow, so a defined edge is the truthful signal.
    border: 1px solid $swag-assistant-hairline;
    border-radius: $swag-assistant-radius-card;
    animation: swag-assistant-card-in 240ms cubic-bezier(0.2, 0.9, 0.25, 1) var(--swag-assistant-card-delay, 0ms) both;
}

.swag-assistant-card__media {
    flex: 0 0 72px;
    width: 72px;
    height: 72px;
    border-radius: $swag-assistant-radius-control;
    overflow: hidden;

    img { width: 100%; height: 100%; object-fit: cover; }

    &--empty {
        background: $swag-assistant-bubble;
        // Replace with a Meteor Icon Kit image glyph in SW 200 (#9fc4ff).
        background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg"/>');
        background-repeat: no-repeat;
        background-position: center;
        background-size: 24px;
    }
}

.swag-assistant-card__info { flex: 1 1 auto; min-width: 0; }

.swag-assistant-card__name {
    margin: 0;
    font-size: $swag-assistant-font-body;
    font-weight: 600;
    line-height: 1.3;
}

.swag-assistant-card__options,
.swag-assistant-card__delivery,
.swag-assistant-card__note {
    margin: 2px 0 0;
    color: $swag-assistant-meta;
    font-size: $swag-assistant-font-meta;
}

.swag-assistant-card__note { color: $swag-assistant-text; }

.swag-assistant-card__price {
    margin: 6px 0 0;
    font-size: $swag-assistant-font-body;
    font-weight: 650;
    // A price that shifts as digits change reads as unreliable.
    font-variant-numeric: tabular-nums;
}

.swag-assistant-card__stock {
    display: flex;
    gap: 6px;
    align-items: center;
    margin: 4px 0 0;
    font-size: $swag-assistant-font-meta;
    font-weight: 600;

    &--in { color: $swag-assistant-in-stock; }
    &--low { color: $swag-assistant-low-stock; }
    &--out { color: $swag-assistant-out-of-stock; }
}

.swag-assistant-card__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
    flex: 0 0 auto;
}

.swag-assistant-card__actions {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 10px;
}

.swag-assistant-card__view {
    color: $swag-assistant-accent;
    font-size: $swag-assistant-font-meta;
    font-weight: 600;
    text-decoration: none;

    &:hover { text-decoration: underline; }
    &:focus-visible { outline: 2px solid $swag-assistant-accent; outline-offset: 2px; }
}

.swag-assistant-card__add {
    padding: 6px 10px;
    border: 1px solid $swag-assistant-accent;
    border-radius: $swag-assistant-radius-control;
    background: $swag-assistant-accent;
    color: #ffffff;
    font-size: $swag-assistant-font-meta;
    font-weight: 600;
    cursor: pointer;

    &:disabled {
        border-color: $swag-assistant-hairline;
        background: $swag-assistant-hairline;
        color: $swag-assistant-out-of-stock;
        cursor: not-allowed;
    }

    &:focus-visible { outline: 2px solid $swag-assistant-accent; outline-offset: 2px; }
}

@keyframes swag-assistant-card-in {
    from { transform: translateY(4px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
    .swag-assistant-card { animation: none; }
}
```

- [ ] **Step 5: Verify the three real card states**

Rebuild, then in the browser confirm each against the fixture catalogue:

1. *"show me the trail jersey in blue, size M"* → **one hero card**, `74,90 €`, **Out of stock** in
   grey with a label, Add button disabled.
2. *"what cycling jerseys do you have?"* → **a scroll row**, panel does not scroll sideways.
3. Every card shows the **no-image placeholder** (all fixtures have `imageUrl: null`).

Then check `stockSource`: query a variant that inherits its parent's stock and confirm the note
*"Stock shown for the product, not this variant."* appears.

- [ ] **Step 6: Commit**

```bash
git add src/Resources
git commit -m "feat: render product cards with honest stock and price"
```

---

### Task 5: The thinking animation

**Files:**
- Create: `src/Resources/app/storefront/src/assistant/thinking.js`
- Create: `src/Resources/app/storefront/src/scss/components/_thinking.scss`
- Modify: `src/Resources/app/storefront/src/assistant/panel.plugin.js`
- Modify: `src/Resources/app/storefront/src/scss/base.scss`

**Interfaces:**
- Produces: `createThinking(log, translations)` → `{ start(), stop() }`. `start()` appends the indicator and begins the phase schedule; `stop()` removes it. Safe to call `stop()` without `start()`.

- [ ] **Step 1: Write `thinking.js`**

```javascript
// A real turn takes ~19 s, measured against the running shop. A two-second loop played ten times is
// where "cute" dies, so the indicator is phased: the shopper sees state change, which reads as
// progress. The copy deliberately claims nothing about what the server is doing — trace events are
// persisted once, after the run completes, so there is no progress signal to read. Inventing stage
// names would put unbacked claims about server work into a product whose whole thesis is that the
// interface never states what the server did not produce.
const PHASES = [
    { at: 0, phase: 'wake', copy: 'thinkingStart' },
    { at: 3000, phase: 'search', copy: 'thinkingStart' },
    { at: 8000, phase: 'waiting', copy: 'thinkingWaiting' },
    { at: 15000, phase: 'patient', copy: 'thinkingPatient' },
];

export function createThinking(log, translations) {
    let el = null;
    let timers = [];

    function start() {
        stop();

        el = document.createElement('div');
        el.className = 'swag-assistant-thinking';

        const orb = document.createElement('span');
        orb.className = 'swag-assistant-thinking__orb';
        orb.setAttribute('aria-hidden', 'true');
        orb.innerHTML = '<span class="swag-assistant-thinking__eye"></span>'
            + '<span class="swag-assistant-thinking__eye"></span>';
        el.appendChild(orb);

        const copy = document.createElement('span');
        copy.className = 'swag-assistant-thinking__copy';
        el.appendChild(copy);

        log.appendChild(el);
        log.scrollTop = log.scrollHeight;

        // Four aria-live updates over 19 s, not a stream. The copy changes are the reassurance, and
        // they must survive prefers-reduced-motion — only the motion is optional.
        timers = PHASES.map(({ at, phase, copy: key }) => window.setTimeout(() => {
            if (!el) {
                return;
            }

            el.dataset.phase = phase;
            copy.textContent = translations[key] ?? '';
        }, at));
    }

    function stop() {
        timers.forEach(window.clearTimeout);
        timers = [];
        el?.remove();
        el = null;
    }

    return { start, stop };
}
```

- [ ] **Step 2: Write `_thinking.scss`**

```scss
.swag-assistant-thinking {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 20px;
    color: $swag-assistant-meta;
    font-size: $swag-assistant-font-meta;
    font-weight: 500;
}

.swag-assistant-thinking__orb {
    position: relative;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: radial-gradient(circle at 32% 28%, #4a8fff 0%, $swag-assistant-accent 45%, $swag-assistant-accent-dark 100%);
    flex: 0 0 auto;
}

.swag-assistant-thinking__eye {
    position: absolute;
    top: 38%;
    width: 4px;
    height: 8px;
    border-radius: 2px;
    background: #ffffff;

    &:first-of-type { left: 28%; }
    &:last-of-type { right: 28%; }
}

// Phase 1 — wake: blink twice, then scan.
[data-phase='wake'] .swag-assistant-thinking__orb {
    animation: swag-assistant-think-wake 900ms ease-out both;
}

// Phase 2 — search: tilt side to side, eyes tracking.
[data-phase='search'] .swag-assistant-thinking__orb {
    animation: swag-assistant-think-tilt 2400ms ease-in-out infinite;
}

[data-phase='search'] .swag-assistant-thinking__eye {
    animation: swag-assistant-think-scan 2400ms ease-in-out infinite;
}

// Phase 3 — waiting: slow bob, one eye squints.
[data-phase='waiting'] .swag-assistant-thinking__orb {
    animation: swag-assistant-think-bob 3200ms ease-in-out infinite;
}

[data-phase='waiting'] .swag-assistant-thinking__eye:last-of-type {
    height: 4px;
}

// Phase 4 — patient: slow pulse, eyes half-closed.
[data-phase='patient'] .swag-assistant-thinking__orb {
    animation: swag-assistant-think-pulse 2800ms ease-in-out infinite;
}

[data-phase='patient'] .swag-assistant-thinking__eye {
    height: 3px;
}

@keyframes swag-assistant-think-wake {
    0% { transform: scale(0.6); opacity: 0; }
    60% { transform: scale(1.04); opacity: 1; }
    100% { transform: scale(1); opacity: 1; }
}

@keyframes swag-assistant-think-tilt {
    0%, 100% { transform: rotate(-6deg); }
    50% { transform: rotate(6deg); }
}

@keyframes swag-assistant-think-scan {
    0%, 100% { transform: translateX(-1px); }
    50% { transform: translateX(1px); }
}

@keyframes swag-assistant-think-bob {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-3px); }
}

@keyframes swag-assistant-think-pulse {
    0%, 100% { transform: scale(1); opacity: 0.85; }
    50% { transform: scale(1.05); opacity: 1; }
}

// Motion is optional; the reassurance is not. Every phase still updates the copy.
@media (prefers-reduced-motion: reduce) {
    .swag-assistant-thinking__orb,
    .swag-assistant-thinking__eye { animation: none !important; }
}
```

- [ ] **Step 3: Wire it into `_submit()`**

In `panel.plugin.js` `init()`: `this.thinking = createThinking(this.log, this.translations);` with
`import { createThinking } from './thinking';`. In `_submit()`, `this.thinking.start()` immediately
after rendering the user message, and `this.thinking.stop()` as the first statement in `finally`.

- [ ] **Step 4: Verify all four phases actually fire**

Send a real message and watch the full ~19 s. Confirm the copy changes at roughly 8 s and 15 s and
that the orb's behaviour changes with it. Then enable reduced motion
(macOS: System Settings → Accessibility → Display → Reduce motion) and confirm **the copy still
changes** while the orb sits still.

If a turn happens to complete in under 8 s, the later phases never fire — that is correct, not a
missing feature.

- [ ] **Step 5: Commit**

```bash
git add src/Resources
git commit -m "feat: phase the thinking indicator across the real 19-second wait"
```

---

### Task 6: Correct the prose when it contradicts the cards

**Files:**
- Create: `src/Resources/app/storefront/src/scss/components/_warning.scss`
- Modify: `src/Resources/app/storefront/src/assistant/render.js`
- Modify: `src/Resources/app/storefront/src/scss/base.scss`

**Interfaces:**
- Consumes: `warnings: { unbackedPrices: string[], unbackedAvailabilityClaims: string[] }` from the `POST /assistant/chat` response.
- Produces: `renderMessage` honours a `warnings` option and inserts the notice **between** the prose and the cards.

> **Re-read `AssistantController::chat()` and `AssistantTurn` at `HEAD` before starting.** This field
> landed in `42ca719` while the spec was being written and `docs/HANDOFF.md` still documents the old
> contract. Confirm the key names are `unbackedPrices` and `unbackedAvailabilityClaims`.

- [ ] **Step 1: Add the notice builder to `render.js`**

```javascript
/**
 * Said out loud rather than logged and forgotten.
 *
 * The server tells us when the reply's own words contradict the cards beside them. It can be this
 * specific because the signal is narrow: `unbackedAvailabilityClaims` fires only when *every*
 * rendered card is out of stock, so the true state is known rather than guessed.
 *
 * Three things this deliberately does not do. It does not edit or delete the prose — rewriting a
 * reply to hide a mistake is how a product loses the right to be trusted. It does not dim the prose,
 * because reducing body-text contrast fails the accessibility floor. It does not underline the
 * offending phrase, because drawing the eye to one error undermines confidence in every other
 * sentence. Emphasis is added to the correction, never subtracted from the text.
 */
function buildWarning(warnings, translations) {
    const availability = warnings?.unbackedAvailabilityClaims ?? [];
    const prices = warnings?.unbackedPrices ?? [];

    if (availability.length === 0 && prices.length === 0) {
        return null;
    }

    const el = document.createElement('p');
    el.className = 'swag-assistant-warning';
    el.setAttribute('role', 'note');

    const icon = document.createElement('span');
    icon.className = 'swag-assistant-warning__icon';
    icon.setAttribute('aria-hidden', 'true');
    el.appendChild(icon);

    // Availability outranks price: being told a sold-out item is available is the failure that
    // cancels an order.
    el.appendChild(document.createTextNode(
        availability.length > 0 ? translations.warningAvailability : translations.warningPrice,
    ));

    return el;
}
```

- [ ] **Step 2: Insert it in the right place**

In `renderMessage`, between `wrapper.appendChild(body)` and the `renderCards` call:

```javascript
    const warning = buildWarning(warnings, translations);
    if (warning) {
        wrapper.appendChild(warning);
    }
```

Add `warnings` to the destructured options. Order matters: prose, then correction, then cards — the
notice must sit between the claim it corrects and the evidence that corrects it.

- [ ] **Step 3: Pass `warnings` through from the panel**

In `_submit()`, add `warnings: reply.warnings` to the assistant `renderMessage` call.

- [ ] **Step 4: Write `_warning.scss`**

```scss
.swag-assistant-warning {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    margin: 10px 0 0;
    padding: 8px 10px;
    border-radius: $swag-assistant-radius-control;
    // Orange, because the brand palette contains no red. Icon plus text: never colour alone.
    background: rgba(248, 129, 56, 0.10);
    color: $swag-assistant-text;
    font-size: $swag-assistant-font-meta;
    font-weight: 500;
}

.swag-assistant-warning__icon {
    flex: 0 0 auto;
    width: 14px;
    height: 14px;
    margin-top: 1px;
    // Replace with a Meteor Icon Kit warning glyph in $swag-assistant-low-stock.
    background: $swag-assistant-low-stock;
    border-radius: 50%;
}
```

- [ ] **Step 5: Reproduce the defect and confirm the correction appears**

The defect is intermittent by nature, so drive it directly. `TRAIL-JERSEY` Blue/M has `stock 0`, and
the recorded failing reply was *"Yes, the Trail Jersey is available in Blue, size M."*

```bash
curl -s -X POST http://127.0.0.1:8000/assistant/chat \
  -H 'Content-Type: application/json' \
  -d '{"message":"is the trail jersey available in blue, size M?"}' | python3 -m json.tool
```

Read the `warnings` object. If `unbackedAvailabilityClaims` is non-empty, send the same message in
the browser and confirm the notice renders between prose and card. If it is empty, the model
answered honestly this time — **do not chase it**; instead assert the rendering path directly by
temporarily returning a stubbed `warnings` from `transport.send`, confirm the notice appears, then
revert the stub.

- [ ] **Step 6: Commit**

```bash
git add src/Resources
git commit -m "feat: correct the prose in the UI when it contradicts the cards"
```

---

### Task 7: Add to cart from a card

**Files:**
- Modify: `src/Resources/app/storefront/src/assistant/panel.plugin.js`
- Modify: `src/Resources/app/storefront/src/scss/components/_card.scss`

**Interfaces:**
- Consumes: `transport.addToCart(productId, quantity)` from Task 3; `[data-swag-assistant-add]` from Task 4.
- Produces: no new exports. Delegated click handling on the log.

- [ ] **Step 1: Delegate the click**

In `panel.plugin.js` `init()`:

```javascript
        // Delegated: cards are created after this handler is bound, and rebinding per card would
        // leak listeners across a long conversation.
        this.log.addEventListener('click', (event) => {
            const button = event.target.closest('[data-swag-assistant-add]');

            if (button && !button.disabled) {
                this._addToCart(button);
            }
        });
```

- [ ] **Step 2: Implement `_addToCart`**

```javascript
    async _addToCart(button) {
        const card = button.closest('.swag-assistant-card');
        button.disabled = true;
        card?.querySelector('.swag-assistant-card__error')?.remove();

        try {
            await this.transport.addToCart(button.dataset.swagAssistantAdd);

            // Let the shop update its own cart widget. The offcanvas cart listens for this, so the
            // header count stays correct without us reaching into the theme's internals.
            document.$emitter?.publish('swag-assistant-cart-updated');
            window.PluginManager.getPluginInstances('OffCanvasCart')
                ?.forEach((instance) => instance.$emitter?.publish('fetchCart'));

            button.textContent = '✓';
        } catch (error) {
            button.disabled = false;

            // Inline on the card, not a toast. The failure belongs to this product.
            const message = document.createElement('p');
            message.className = 'swag-assistant-card__error';
            message.setAttribute('role', 'alert');
            message.textContent = this.translations.errorCartFailed;
            card?.querySelector('.swag-assistant-card__info')?.appendChild(message);
        }
    }
```

**Verify the cart-refresh mechanism against the installed theme before trusting it.** Read
`vendor/shopware/storefront/Resources/app/storefront/src/plugin/offcanvas-cart/` in the test shop and
use whatever event it actually listens for. If no clean hook exists, fall back to reloading the cart
widget via its own AJAX route rather than inventing an event name.

- [ ] **Step 3: Style the error**

```scss
.swag-assistant-card__error {
    margin: 6px 0 0;
    color: $swag-assistant-low-stock;
    font-size: $swag-assistant-font-meta;
    font-weight: 600;
}
```

- [ ] **Step 4: Verify a real cart write — this closes known issue 2**

```bash
cd ~/Workspace/shopping-assistant-test
bin/console dal:refresh:index >/dev/null 2>&1 || true
```

In the browser: ask for an **in-stock** product (Black/M, `stock 3`), click **Add to cart**, then open
the shop's cart. **The line item must actually be there.** Confirm:

1. The header cart count increases.
2. The cart page lists the product.
3. An **out-of-stock** card's Add button is disabled and cannot be clicked.
4. With `enableAddToCart` off, **no** Add button renders:

```bash
bin/console system:config:set SwagAssistantStarterKit.config.enableAddToCart false
# reload, confirm no Add buttons, then restore
bin/console system:config:set SwagAssistantStarterKit.config.enableAddToCart true
```

- [ ] **Step 5: Commit**

```bash
cd ~/Workspace/shopping-assistant-starter-kit
git add src/Resources
git commit -m "feat: add a product to the cart from an assistant card"
```

---

### Task 8: Error and limit states

**Files:**
- Modify: `src/Resources/app/storefront/src/assistant/panel.plugin.js`
- Modify: `src/Resources/app/storefront/src/scss/components/_message.scss`

**Interfaces:**
- Consumes: the `error.status` property set by `transport.js`.
- Produces: no new exports.

- [ ] **Step 1: Replace the bare `finally` in `_submit()`**

```javascript
    async _submit() {
        const message = this.input.value.trim();

        if (message === '' || this.isBusy) {
            return;
        }

        this.isBusy = true;
        this.send.disabled = true;
        this.input.value = '';

        const sent = renderMessage(this.log, { role: 'user', prose: message, locale: this.locale });
        this.thinking.start();

        try {
            const reply = await this.transport.send(message, window.sessionStorage.getItem(TOKEN_KEY));
            window.sessionStorage.setItem(TOKEN_KEY, reply.token);
            renderMessage(this.log, {
                role: 'assistant',
                prose: reply.prose,
                cards: reply.cards,
                warnings: reply.warnings,
                locale: this.locale,
                addToCartEnabled: this.addToCartEnabled,
                translations: this.translations,
            });
        } catch (error) {
            this._renderFailure(error, sent, message);
        } finally {
            this.thinking.stop();
            this.isBusy = false;
            this.send.disabled = false;
        }
    }

    /**
     * The shopper's message stays in the log. Losing what someone typed because a request failed is
     * the one outcome that makes a retry feel like starting over.
     */
    _renderFailure(error, sentMessage, originalText) {
        const notice = document.createElement('div');
        notice.className = 'swag-assistant-failure';
        notice.setAttribute('role', 'alert');

        const text = document.createElement('span');

        if (error.status === 503 || error.status === 429) {
            text.textContent = this.translations.errorUnavailable;
            this.input.disabled = true;
            this.send.disabled = true;
        } else if (error.status === 400) {
            text.textContent = this.translations.errorTooLong;
        } else {
            text.textContent = this.translations.errorNetwork;

            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'swag-assistant-failure__retry';
            retry.textContent = this.translations.errorRetry;
            retry.addEventListener('click', () => {
                notice.remove();
                sentMessage.remove();
                this.input.value = originalText;
                this._submit();
            });
            notice.appendChild(retry);
        }

        notice.insertBefore(text, notice.firstChild);
        this.log.appendChild(notice);
        this.log.scrollTop = this.log.scrollHeight;
    }
```

- [ ] **Step 2: Add the live character guard**

In `init()`:

```javascript
        // 2000 is the server's limit (ChatRequest::MAX_MESSAGE_LENGTH). Refuse before the request
        // rather than after: an oversized message is rejected server-side, never truncated, so
        // sending one wastes a round trip and returns an error the shopper could have been spared.
        this.input.addEventListener('input', () => {
            const tooLong = this.input.value.length > 2000;
            this.input.classList.toggle('is-too-long', tooLong);
            this.send.disabled = tooLong || this.isBusy;
        });
```

- [ ] **Step 3: Style the failure notice**

```scss
.swag-assistant-failure {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 20px;
    padding: 8px 10px;
    border-radius: $swag-assistant-radius-control;
    background: rgba(248, 129, 56, 0.10);
    color: $swag-assistant-text;
    font-size: $swag-assistant-font-meta;
    font-weight: 500;
}

.swag-assistant-failure__retry {
    padding: 4px 8px;
    border: 1px solid $swag-assistant-accent;
    border-radius: $swag-assistant-radius-control;
    background: none;
    color: $swag-assistant-accent;
    font: inherit;
    font-weight: 600;
    cursor: pointer;

    &:focus-visible { outline: 2px solid $swag-assistant-accent; outline-offset: 2px; }
}

.swag-assistant-composer__input.is-too-long { border-color: $swag-assistant-low-stock; }
```

- [ ] **Step 4: Verify each failure state deliberately**

- **Network failure:** open devtools → Network → Offline, send a message. The message stays, a retry
  button appears, clicking it re-sends once back online.
- **Too long:** paste 2100 characters. Send disables and the border turns Orange **before** any
  request goes out (confirm in the Network tab that none does).
- **Kill switch mid-conversation:**
  ```bash
  bin/console system:config:set SwagAssistantStarterKit.config.killSwitch true
  ```
  With the panel already open, send a message. Expect the unavailable notice and a disabled input.
  Restore afterwards.

- [ ] **Step 5: Commit**

```bash
git add src/Resources
git commit -m "feat: tell a shopper what went wrong without losing their message"
```

---

### Task 9 — BLOCKED: persist timestamps and warnings per turn

> **Do not start this task until the working tree is clean and the other session's work is
> committed.** It modifies `ConversationTurn`, `DalConversationStore` and `AssistantController` —
> all files another session was actively changing while this plan was written. Confirm with
> `git status` and re-read all three at `HEAD` first.

**Files:**
- Modify: `src/Core/Trace/ConversationTurn.php`
- Modify: `src/Core/Trace/DalConversationStore.php`
- Modify: `src/Controller/AssistantController.php`
- Modify: `tests/Core/Trace/ConversationStoreContractTest.php`
- Modify: `tests/Core/Trace/InMemoryConversationStore.php`
- Modify: `tests/Controller/AssistantControllerTest.php`
- Modify: `src/Resources/app/storefront/src/assistant/panel.plugin.js`

**Interfaces:**
- Produces: `ConversationTurn` gains `?\DateTimeImmutable $createdAt` and `array $warnings`. `GET /assistant/history` messages gain `createdAt` (ISO-8601 string or null) and `warnings`.

- [ ] **Step 1: Write the failing contract test**

Add to `tests/Core/Trace/ConversationStoreContractTest.php`:

```php
    public function testATurnKeepsItsTimestampAndWarningsAcrossAReadBack(): void
    {
        $store = $this->store();
        $token = $store->start(self::CHANNEL, 'en-GB');
        $written = new \DateTimeImmutable('2026-08-20T09:41:07+00:00');

        $store->append($token, new ConversationTurn(
            role: ConversationTurn::ROLE_ASSISTANT,
            prose: 'Yes, the Trail Jersey is available in Blue, size M.',
            cardIds: ['a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2'],
            outcome: 'product_shown',
            createdAt: $written,
            warnings: ['unbackedAvailabilityClaims' => ['is available'], 'unbackedPrices' => []],
        ), new TraceRecorder());

        $history = $store->history($token, 20);

        self::assertCount(1, $history);
        self::assertSame(
            $written->format(\DATE_ATOM),
            $history[0]->createdAt?->format(\DATE_ATOM),
        );
        self::assertSame(['is available'], $history[0]->warnings['unbackedAvailabilityClaims']);
    }

    public function testATurnStoredWithoutATimestampReadsBackAsNull(): void
    {
        // Rows written before this field existed must not break a read. A shopper with an older
        // conversation gets a message with no time, not an exception.
        $store = $this->store();
        $token = $store->start(self::CHANNEL, 'en-GB');

        $store->append($token, new ConversationTurn(
            ConversationTurn::ROLE_USER,
            'show me the trail jersey',
        ), new TraceRecorder());

        self::assertNull($store->history($token, 20)[0]->createdAt);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Trace/ConversationStoreContractTest.php`
Expected: FAIL — `Unknown named parameter $createdAt`

- [ ] **Step 3: Extend the DTO**

```php
    /**
     * @param list<string>                $cardIds
     * @param array<string, list<string>> $warnings the grounding warnings this turn produced —
     *        `unbackedPrices` and `unbackedAvailabilityClaims`. Stored with the turn because a
     *        reloaded conversation must not lose the correction: without it the misleading sentence
     *        comes back unannotated, which is the most shopper-visible defect in the product
     *        reappearing on every page load.
     */
    public function __construct(
        public string $role,
        public string $prose,
        public array $cardIds = [],
        public string $outcome = '',
        public ?\DateTimeImmutable $createdAt = null,
        public array $warnings = [],
    ) {}
```

Note the parameter count: `ConversationTurn` now takes 6 constructor parameters against a gate
threshold of 5. It is a DTO, and the existing pragma carve-outs in
`.superpowers/sdd/2026-08-18-grounded-core/standing-constraints.md` are the place to check whether
DTOs are already exempt. **If they are not, do not silently add a pragma** — raise it, because
splitting a conversation turn into two objects to satisfy a parameter count would be worse code.

- [ ] **Step 4: Map both fields in the store**

In `DalConversationStore`, wherever the transcript is serialised and deserialised, add `createdAt`
(as `\DATE_ATOM`) and `warnings`. Deserialisation must tolerate both being absent:

```php
        createdAt: isset($row['createdAt']) && \is_string($row['createdAt'])
            ? new \DateTimeImmutable($row['createdAt'])
            : null,
        warnings: isset($row['warnings']) && \is_array($row['warnings']) ? $row['warnings'] : [],
```

Update `tests/Core/Trace/InMemoryConversationStore.php` the same way so the contract test covers
both implementations.

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Trace`
Expected: PASS

- [ ] **Step 6: Emit from the controller**

In `chat()`, stamp both turns and pass the warnings onto the assistant turn. In `history()`, add to
each message:

```php
                'createdAt' => $turn->createdAt?->format(\DATE_ATOM),
                'warnings' => $turn->warnings,
```

Add a test to `tests/Controller/AssistantControllerTest.php` asserting `history()` returns
`createdAt` and `warnings` for a stored turn.

- [ ] **Step 7: Remove the client-side fallback**

`renderMessage` already accepts `createdAt`. Delete nothing else — rehydrated messages now simply
have a real value. Confirm the ISO string parses in `formatTime`.

- [ ] **Step 8: Verify across a reload, and run the whole suite**

```bash
composer run test && composer run quality
```

Then in the browser: hold a two-message conversation, reload, reopen. **Every** message must show a
timestamp, and any correction notice must still be attached to the message that earned it.

- [ ] **Step 9: Commit**

```bash
git add src tests
git commit -m "feat: persist each turn's timestamp and grounding warnings"
```

---

### Task 10: End-to-end verification and documentation

**Files:**
- Create: `tests/e2e/widget.spec.js`
- Create: `tests/e2e/README.md`
- Modify: `README.md`
- Modify: `ARCHITECTURE.md`
- Modify: `docs/HANDOFF.md`

**Interfaces:** none — this task produces evidence and docs.

- [ ] **Step 1: Write the Playwright spec**

```javascript
import { expect, test } from '@playwright/test';

const SHOP = process.env.SHOP_URL ?? 'http://127.0.0.1:8000';
// A real turn was measured at ~19s. 60s leaves room for a slow model without masking a hang.
const TURN_TIMEOUT = 60_000;

test.describe('assistant widget', () => {
    test('the orb renders and opens the panel', async ({ page }) => {
        await page.goto(SHOP);

        const orb = page.locator('[data-swag-assistant-orb]');
        await expect(orb).toBeVisible();
        await expect(orb).toHaveAttribute('aria-expanded', 'false');

        await orb.click();
        await expect(page.locator('[data-swag-assistant-panel]')).toBeVisible();
        await expect(orb).toHaveAttribute('aria-expanded', 'true');
    });

    test('escape closes the panel and returns focus to the orb', async ({ page }) => {
        await page.goto(SHOP);
        await page.locator('[data-swag-assistant-orb]').click();
        await page.keyboard.press('Escape');

        await expect(page.locator('[data-swag-assistant-panel]')).toBeHidden();
        await expect(page.locator('[data-swag-assistant-orb]')).toBeFocused();
    });

    test('a real turn answers with a card carrying a price and a stock label', async ({ page }) => {
        await page.goto(SHOP);
        await page.locator('[data-swag-assistant-orb]').click();
        await page.locator('[data-swag-assistant-input]').fill('show me the trail jersey in blue, size M');
        await page.locator('[data-swag-assistant-send]').click();

        // The thinking indicator must appear immediately — a silent 19s is the failure this exists
        // to prevent.
        await expect(page.locator('.swag-assistant-thinking')).toBeVisible();

        const card = page.locator('.swag-assistant-card').first();
        await expect(card).toBeVisible({ timeout: TURN_TIMEOUT });
        await expect(card.locator('.swag-assistant-card__price')).not.toBeEmpty();
        // Stock is never colour alone: there must be a readable label.
        await expect(card.locator('.swag-assistant-card__stock')).not.toBeEmpty();
    });

    test('the conversation survives a reload', async ({ page }) => {
        await page.goto(SHOP);
        await page.locator('[data-swag-assistant-orb]').click();
        await page.locator('[data-swag-assistant-input]').fill('show me the trail jersey');
        await page.locator('[data-swag-assistant-send]').click();
        await expect(page.locator('.swag-assistant-message--assistant').last())
            .toBeVisible({ timeout: TURN_TIMEOUT });

        await page.reload();
        await page.locator('[data-swag-assistant-orb]').click();

        await expect(page.locator('.swag-assistant-message--user')).toHaveCount(1);
    });
});
```

- [ ] **Step 2: Answer the open question from the spec**

Spec §11 item 1 is unverified: **does PHP complete the turn after a client disconnect?** Settle it:

```bash
# Start a turn, kill the client after 2s, then read the transcript back.
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/assistant/chat \
  -H 'Content-Type: application/json' \
  -d '{"message":"show me the trail jersey"}' --max-time 2 -o /dev/null -w '' ; echo)

# Without a token from the aborted call, list the newest conversation through the Admin API and
# check whether its transcript has two turns. If it does, the server finished without the client.
```

Record the answer in `docs/HANDOFF.md` either way. **If the server does not finish, the retry
affordance in Task 8 is the only recovery path and that must be stated**, not left implied.

- [ ] **Step 3: Document installation honestly**

Add to `README.md`:

```markdown
## Storefront widget

The widget ships compiled, so a merchant needs no Node toolchain:

    bin/console plugin:install --activate SwagAssistantStarterKit
    bin/console theme:compile

The orb only renders once a model is configured (base URL, model, API key). An unconfigured shop,
a shop with the kill switch on, or a shop with `widgetEnabled` off renders nothing at all — the
chat endpoint stays reachable either way, so a custom interface keeps working.

To change the widget, edit `src/Resources/app/storefront/src` and rebuild:

    composer run build:storefront

CI fails if the committed `dist` does not match `src`.
```

Also carry over the **Flex recipe warning** (known issue 9): installing into a Flex project writes
`config/packages/ai_generic_platform.yaml` and breaks the kernel. One line to delete. It must be in
the install docs.

- [ ] **Step 4: Update the architecture and handoff**

- `ARCHITECTURE.md`: the "Storefront widget markup — Twig template override" extension point is now
  real. Record the Twig blocks that form the override surface, and that `dist` is committed.
- `docs/HANDOFF.md`: its endpoint section is stale — it documents neither `warnings` nor the new
  `createdAt`. Update it. Move known issue 2 to closed if Task 7 wrote to a real cart, and state
  what the cart write proved.

- [ ] **Step 5: Run everything**

```bash
composer run test && composer run quality
npx playwright test tests/e2e/widget.spec.js
```

Expected: PHP suite green, `quality` exit 0, all four Playwright tests passing. **Paste the actual
output into the commit message or the handoff — a claim without a command behind it is not
evidence.**

- [ ] **Step 6: Commit**

```bash
git add tests/e2e README.md ARCHITECTURE.md docs/HANDOFF.md
git commit -m "test: verify the widget end to end against a real shop"
```

---

## Plan self-review

**Spec coverage.** W1–W3 → Tasks 1–2 and the CI job. W4 dependency policy → Global Constraints; the
Meteor icon and signet substitutions are called out as replacements in Tasks 1, 4 and 6. W5–W8 →
Task 1. W9–W14 → the tokens file in Task 1 plus per-component SCSS in Tasks 2–6. W15 → Task 3. W16
→ Task 5. W17 → Tasks 1–2. W18–W19 → every SCSS block ends with a reduced-motion rule and no
animation touches a layout property. W20 → Task 9. W21 → Tasks 3 and 7. W22 → Task 8. W23 → no
cancel is implemented anywhere, which is the intended absence. W24 → Task 6. §8 accessibility →
Tasks 1, 2, 4 and the Playwright focus test. §9 testing → Tasks 1, 9, 10.

**Two known gaps, deliberate rather than overlooked.** The Meteor icon glyphs and the Shopware
signet are `data:` URI placeholders in the SCSS; each has an inline comment naming what replaces it
and where the asset lives. Substituting them is part of the task that renders them, not a separate
task, because a placeholder that ships is a defect while a placeholder mid-task is scaffolding.

**Type consistency.** `renderMessage(log, {role, prose, cards, warnings, createdAt, locale, animate,
addToCartEnabled, translations})` is the single signature used in Tasks 3, 4, 6 and 8 — the options
bag grows across tasks but the name and the first parameter never change. `createTransport` →
`{send, history, addToCart}` is consumed under those exact names in Tasks 3, 7 and 8.
`createThinking` → `{start, stop}` in Task 5. `renderCards(container, cards, options)` in Task 4.
`AssistantWidgetExtension::isEnabled()` backs the Twig function `swag_assistant_widget_enabled` used
in Task 1's templates. Snippet keys in Task 1 match every `translations.*` property read in Tasks 4,
5, 6 and 8.

**Sequencing.** Task 9 is last but one and explicitly blocked; every other task creates new files or
touches only files this plan created, so the concurrent session cannot collide with Tasks 1–8.
