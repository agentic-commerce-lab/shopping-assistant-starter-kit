# Shopping Assistant Starter Kit

A Shopware 6.7 plugin that adds a **shopper-facing, merchant-operated** conversational
shopping assistant to the storefront — grounded in the shop's own catalog, observable
from the Administration, and covered by an evaluation suite.

> **Status: research preview / lab prototype.** Built by the Agentic Commerce Lab.
> Not production software. No support, no upgrade guarantees, no Store release.

## What it does

A shopper opens a chat panel in the storefront and asks in natural language —
*"do you have the trail jersey in blue, size M?"* — and gets an answer built from real
catalog data: the correct variant, its real price, its real stock, and a working link.
They can say *"add that to my cart"* and it lands in their actual cart. Checkout is the
shop's normal checkout.

On a product page the assistant knows which product is open, so *"do you have this in blue?"*
needs no search first; on a category page it searches that category before the whole shop, and says
in the trace when it had to look outside it.

Each conversation records which customer started it, or none for a guest, and the Administration
shows the name beside it. A merchant can export conversations as JSON — the full trace, every event
payload intact — from the list or from a single conversation's page. **An exported file contains
customer names**, so it is personal data once it leaves the shop and is no longer covered by the
retention that prunes the traces themselves.

The merchant sees every conversation in the Administration: what the assistant
understood, which products it retrieved, which facts it rendered, what it refused —
and the system prompt each turn was actually run with, which matters once a partner
can replace that prompt from their own plugin.

## What it deliberately does not do

- Complete an order or handle payment — it hands off to the shop's checkout
- Set prices, apply discounts, or negotiate — there is no code path for it
- Answer order-status or account questions — those escalate
- Invent a product, a price, or a stock level — see `ARCHITECTURE.md`, "Grounding"

## Documents

| Document | Read it for |
|---|---|
| [VISION.md](VISION.md) | Why this exists, who it is for, what counts as success |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Structure, interfaces, pipeline, data model |
| [GLOSSARY.md](GLOSSARY.md) | Terms that have burned us before — read this first if you are new |
| [docs/superpowers/specs/](docs/superpowers/specs/) | The design of record for the current build |

## Requirements

- Shopware **6.7** (`^6.7`), PHP 8.2+
- An OpenAI-compatible chat-completions endpoint (`base_url` + `model` + `api_key`)
- English-language catalog and storefront (v0 is English-only)

## Installing it into a shop

**Install via a Composer path repository, not a `custom/plugins` symlink.** For a plugin Shopware
does not manage through Composer it registers only the plugin's *own* PSR-4 namespaces — not its
dependencies — so `symfony/ai-agent` would be missing and the first turn would fatal inside a shopper
request. Verified in `Framework/Plugin/KernelPluginLoader/KernelPluginLoader.php`.

From the shop's project root:

```fish
# Do this FIRST — see the note below. One line, and the shop never breaks.
mkdir -p config/packages && printf '# Intentionally empty — see SwagAssistantStarterKit README.\n' \
    > config/packages/ai_generic_platform.yaml

composer config repositories.assistant '{"type":"path","url":"../shopping-assistant-starter-kit","options":{"symlink":true}}'
composer require "swag/assistant-starter-kit:*@dev"
bin/console plugin:refresh
bin/console plugin:install --activate SwagAssistantStarterKit
bin/console cache:clear
```

> **Why that first line exists.** `composer require` pulls `symfony/ai-generic-platform`, and in a
> Symfony Flex project — which `shopware/production` is — Flex applies that package's recipe and
> writes `config/packages/ai_generic_platform.yaml` containing an `ai:` root key. Nothing registers
> `symfony/ai-bundle` (this plugin builds its platform itself, see
> `docs/adr/0001-symfony-ai-as-agent-runtime.md`), so the container stops loading with *"There is no
> extension able to load the configuration for 'ai'"* — and the **whole storefront returns 500**, not
> just the assistant. Measured on Shopware 6.7.13: writing that file took the storefront from 200 to
> 500 on the next request.
>
> Creating the file yourself first prevents it, rather than repairing it afterwards. Flex does not
> overwrite a file that already exists — `Options::shouldWriteFile()` returns false for an existing
> path unless `--force` is passed, and a plain `composer require` never passes it. Verified by
> removing the package's `symfony.lock` entry with the placeholder in place and re-running
> `composer recipes:install symfony/ai-generic-platform`: Flex reported the recipe as configured,
> left the file byte-identical, and the storefront stayed at 200.
>
> A comment-only YAML file is safe to leave in place forever: Symfony's loader treats a file that
> parses to null as empty and skips it.
>
> The **recipe itself** is still not preventable from inside the plugin — it belongs to a dependency
> and is applied by the *shop's* Flex. What is preventable is the outage.

Then configure a model — in the Administration under the plugin's settings, or as environment
variables, which take precedence:

```fish
bin/console system:config:set SwagAssistantStarterKit.config.llmBaseUrl "https://openrouter.ai/api"
bin/console system:config:set SwagAssistantStarterKit.config.llmModel "anthropic/claude-sonnet-5"
bin/console system:config:set SwagAssistantStarterKit.config.llmApiKey "…"
```

Environment variables win over stored values on purpose: Shopware's system config has no real secret
storage, so a key entered in the admin form is readable by anyone with config access and travels in
every database backup. Until all three are set, the chat endpoint answers **503** rather than failing
mid-turn.

## Request limits

`POST /assistant/chat` is public and every call spends model tokens, so two limits sit in front of
it. Both are in the Administration under **Request limits**:

| Setting | Default | What it does |
|---|---|---|
| `requestsPerMinute` | 60 | Per caller, sliding window. The control that stops a scripted loop |
| `dailyRequestCap` | **0 — off** | Per sales channel, 24h. An opt-in spend ceiling, not an abuse defence |

A refused request answers **429** with a `Retry-After` header and writes nothing — no conversation
row, no trace, no model call. The widget already treats 429 as transient and offers a retry button.

The order matters and is deliberate: the per-caller window is consumed first and in every branch, so
switching the assistant off does not create an unthrottled path; the daily budget is consumed only
when a turn could actually spend, so a shop that is switched off is told exactly that rather than
told it is out of budget.

**`0` in either field means unlimited.** It used to mean the opposite — refuse everything — which
made zero the most destructive value a merchant could type into a numeric field, and duplicated a
job the assistant's own off switch already does with a reason the trace can record.

Only the per-caller window ships on. The daily cap is a spend ceiling, and a ceiling nobody chose is
not a safety feature: at the old default of 500 a good day's traffic turned the assistant off by
mid-afternoon, silently. Switch it on if you want a known stopping point, and be aware of what you
are choosing — when it trips, every shopper gets nothing until it resets, including the ones who were
about to buy something.

Counters live in the shop's cache, not the database, so **clearing the cache resets both windows**.
Behind a proxy or CDN, `framework.trusted_proxies` has to be right or every shopper shares one
window — Symfony's `getClientIp()` is what the per-caller window counts.

> `dailyRequestCap` shipped for months enforced by nothing: the comparison existed in `GuardCheck`
> but the storefront never supplied it a count, so it could only ever trip when set to 0. It is now
> enforced in `Core\Policy\RequestBudget`, at the HTTP boundary, and covered by
> `tests/Controller/AssistantThrottleTest.php`.

## Escalation

Some questions have no answer in the catalogue — order status, returns, account data. The assistant
hands those over rather than guessing, and three settings under **Escalation** decide what "hand
over" means:

| Setting | Default | What it does |
|---|---|---|
| `enableEscalation` | on | When off, the escalate tool is never constructed, so the model cannot see it or call it. The assistant declines instead |
| `escalationUrl` | — | A path on this shop (`/contact`) or an https URL. Only http and https are accepted — this link is served to every shopper |
| `escalationMessage` | — | Shown above the link. Left empty, a translated default is used |

**With no URL configured the assistant says it cannot help and names what it can do instead.** It
does not claim a human will follow up, because nothing would notify one — and that is enforced by
measurement rather than by instruction. `EscalateTool`'s note forbids claiming contact in so many
words, and the `no_handoff_claim_in_prose` eval assertion is what says whether the model obeyed. The
first, milder wording lost 6 of 6 live runs: told the question "needs the shop team", the model wrote
"I've flagged this to the team" using verbs the note never mentioned.

**Nothing is notified on the merchant's side.** No mail, no ticket, no queue. Escalation gives the
shopper a route they take themselves. A merchant who wants the transcript pushed to a support desk
needs the trace sink that is still on the deferred list.

Switching `enableEscalation` off removes the capability rather than forbidding it: the tool is never
constructed, so it never reaches the model's toolbox, and the system prompt drops its "escalate"
instruction in the same step — an order to call a tool that is not there is how a model ends up
improvising. This is the same guarantee `enableAddToCart` makes, for the same reason.

The link is rendered server-side from the setting and is never in the model's context, so it cannot
be paraphrased into a broken URL — the same rule that governs prices and stock. A reloaded
transcript rebuilds it from configuration rather than replaying it, so a contact route the merchant
has since moved or withdrawn is not still offered.

> Until 2026-08-22 the escalate tool returned "Handing this over to a human." with no destination, no
> configuration and nothing notified. Escalation is the designed answer for four of `VISION.md`'s
> non-goals, and it was a dead end.

## The storefront widget

The widget ships **compiled**, so a merchant needs no Node toolchain. After installing and
configuring a model, one command makes it appear:

```fish
bin/console theme:compile
```

Styles are compiled by Shopware's own PHP SCSS pipeline, and the JavaScript is committed under
`src/Resources/app/storefront/dist` — the same thing SwagPayPal ships, and the reason the plugin works
on install rather than after a build.

**The entry point renders only on a shop that can answer.** No orb appears when no model is
configured, when `assistantEnabled` is off, or when `widgetEnabled` is off. That is deliberate: an orb
that opens a panel which answers 503 invites a shopper to ask a question nothing can answer. The chat
endpoint stays reachable in every one of those cases, so a custom interface built against it keeps
working.

`assistantName` and `greeting` live under **Storefront widget**; `widgetEnabled` sits with the off
switch under **Assistant status**, because the two are easy to confuse and belong side by side —
`assistantEnabled` stops the assistant answering, `widgetEnabled` only stops it being shown. A blank
greeting falls back to a translated snippet, so an unconfigured German shop still greets in German.

### Changing it

```fish
composer run build:storefront   # rebuilds src/ into dist/
bin/console theme:compile       # in the shop
```

CI fails if the storefront source changed without a matching `dist` rebuild. It does **not** diff the
two byte-for-byte, and cannot: the build is deterministic at a given path but path-dependent across
paths, because webpack derives module ids from the absolute path. Identical source built in two
directories produces identical chunk bodies under different names, so a rebuild-and-diff job would
fail on every CI run while proving nothing.

### Appearance

Three settings under **Appearance**:

| Setting | Default | What it does |
|---|---|---|
| `entryPointStyle` | `icon` | `icon` is a neutral chat bubble that takes your colours. `creature` is the animated face, with expressions that react to the conversation |
| `primaryColor` | — | The action colour: entry point, send button, focus rings, link and chip text |
| `secondaryColor` | — | The quiet surface behind the assistant's replies |

Both colours are optional; left empty, the shipped blue applies. They reach the widget as CSS custom
properties, because the stylesheet is compiled once and these are per-sales-channel — so one shop can
run two storefronts in two brands.

**Whatever sits on the primary is computed, not configured.** A pale brand colour gets dark text, a
saturated one gets white, and the choice is made by comparing both contrast ratios rather than against
a lightness threshold. Asking a merchant to pick the foreground as well is how an unreadable entry
point ships.

Only `#rrggbb` is accepted — no `rgb()`, no named colours, no shorthand. That value lands in a `style`
attribute served to every shopper, and everything else CSS would happily parse is also a way to
smuggle a second declaration in. A rejected value falls back to the shipped token rather than being
corrected.

**Both entry points take your colours.** The icon is a flat fill; the character is a four-stop shaded
sphere whose whole ramp — lit face, base, shadow, shadowed edge — is derived from your primary, so it
reads as a lit object in your brand rather than a flat colour poured into someone else's gradient.

The difference between them is motion, not colour. The icon holds still. The character floats, blinks,
follows the pointer and hops when something lands — which is personality a merchant storefront often
does not want, and the reason the neutral one is the default.

### Resizing

On desktop the panel resizes like a window: drag its **left edge** for width, its **top edge** for
height, or the **top-left corner** for both at once. There is no drawn grip — the cursor is the
affordance, the same as a window frame.

The bottom and right edges are pinned. The panel is anchored there by the orb it opens from, so
dragging those would move it rather than resize it.

The corner is keyboard-operable: focus it and use the arrow keys, 24px a step. The size is remembered
in `localStorage` and re-clamped on every open, so a window that shrank since does not leave a panel
hanging off the screen.

It is absent on phones, where the panel is already a full-screen sheet.

### Extension points

**[docs/extending.md](docs/extending.md) has a worked example of each seam.** In short:

| I want to… | How |
|---|---|
| Add a tool answering from my own data | `ToolFactoryInterface`, tag `swag_assistant.tool_factory` |
| Add a tool answering from the catalogue | `GroundedToolFactoryInterface`, tag `swag_assistant.grounded_tool_factory` |
| Change the system prompt | decorate `PromptProviderInterface` |
| Use a different model provider | decorate `LlmPlatformInterface` |
| Send turns to my analytics | `TraceSinkInterface`, tag `swag_assistant.trace_sink` |
| Swap the catalogue backend | decorate `CommerceGatewayInterface` |

Ranking rules, knowledge sources and MCP surfaces are **not** seams yet; `docs/extending.md` says so
plainly rather than leaving you to grep for them.

For the widget's markup, override any of these Twig blocks from a theme or plugin:

| Block | Changes |
|---|---|
| `swag_assistant_orb` | the entry point's markup |
| `swag_assistant_face` | the creature's eyes and mouth, shared by the orb and the panel's avatar |
| `swag_assistant_orb_signet` | empty by default — override it to put a merchant's own mark on the bubble |
| `swag_assistant_panel_header` | the panel's heading row |
| `swag_assistant_panel_composer` | the input and send button |
| `swag_assistant_panel_resize` | the resize handle — override to move or remove it |

`swag_assistant_orb_signet` used to render the Shopware signet, and now renders nothing: the creature
has a mouth in that spot, and a mouth that can change expression is worth more there than a mark. The
block is kept so an existing override still works — position anything you put there clear of the lower
centre of the face. `assistant/shopware-signet.png` is still shipped for exactly that purpose.

### The creature

The orb, the avatar in the panel header and the bubble leading the thinking indicator are **one
object**: one Twig include (`swag_assistant_face`), one SCSS mixin (`swag-assistant-bubble`), one
behaviour module (`assistant/creature.js`). Size travels as `--swag-assistant-unit`, so the same
markup renders at 60px in the corner and 32px in the header with its proportions intact.

Expressions are a single `data-mood` attribute with seven values — `idle`, `happy`, `laugh`,
`curious`, `wow`, `sleepy`, `focus`. **JavaScript only ever writes that string; the stylesheet owns
what each one looks like.** That split is why the whole personality survives
`prefers-reduced-motion`: every mood changes a *shape*, which is state, so the creature still smiles
and still squints with every animation switched off. Only the motion — the hops, the squash, the
pointer tracking — is guarded, and it is guarded in one place.

**No animation library.** Everything is CSS keyframes plus a small Web Animations layer, which is why
the orb chunk that loads on every storefront page is 2.3 KB gzipped rather than 25 KB. Gestures use
`composite: 'add'` so a hop composes on top of the resting float instead of replacing it.

The widget renders on every storefront page from `base_body_inner`. To exclude some — checkout, for
instance — wrap the include in `src/Resources/views/storefront/base.html.twig` in your own condition.
That call is the merchant's, not ours.

### Accessibility

The orb is a real `<button>` with an accessible name; the panel is a `role="dialog"` that is honestly
**not** `aria-modal` on desktop, because the storefront behind it stays usable; the message list is a
`role="log"` with `aria-live="polite"`; `Escape` closes and returns focus to the orb; and `Tab` stays
inside the panel while it is open.

`prefers-reduced-motion` removes every animation **but keeps the copy changes** during the wait, so
the reassurance survives without the motion.

## Checking it against the real catalogue

`swag:assistant:probe` runs the commerce gateway against the shop's own products and prints what
comes back — no widget, no model needed for the first three modes:

```fish
bin/console swag:assistant:probe --search="Trail Jersey"      # cards with stock and stockSource
bin/console swag:assistant:probe --facets                     # the vocabulary the model is offered
bin/console swag:assistant:probe --variant=<parentId> --option=Blue --option=M
bin/console swag:assistant:probe --ask="do you have it in M?" # one full turn, then its trace
```

`--variant` with an under-specified selection prints `null` — resolution refuses to guess, which is
the guarantee, not a failure. `--ask` needs the three `ASSISTANT_LLM_*` variables and is the only mode
that spends money.

## Running the eval suite

`composer run test` (the default suite) never talks to a real LLM: `tests/Eval/JourneyEvalTest.php`
is tagged `#[Group('eval')]` and skips cleanly unless `ASSISTANT_LLM_BASE_URL`,
`ASSISTANT_LLM_API_KEY` and `ASSISTANT_LLM_MODEL` are all set to a non-empty value — which
is also the state of every CI run.

To drive the eval suite against a real endpoint locally, copy `.env.example` to `.env` and
fill in real values:

```fish
cp .env.example .env
# edit .env with your endpoint, key and model id
composer run test:eval
```

**Budget the time and the spend.** Fifteen journeys, each up to three runs per archetype, every run
a real turn: the whole suite is well over five minutes and costs real tokens. **A local `.env` is
loaded by `tests/bootstrap.php`**, so on a credentialed machine `vendor/bin/phpunit tests/Eval/`
fires all of them — use `--exclude-group eval` when you only mean to run the deterministic ones. That is also why
`composer.json` sets `process-timeout: 1800` — Composer's 300-second default killed the run partway
through, which reads as a failure rather than as a timeout. To spend less, filter to one journey:

```fish
vendor/bin/phpunit --group eval --filter order_status_escalates
```

`.env` is git-ignored — never commit a real key. `tests/bootstrap.php` loads it only if
the file exists, via `symfony/dotenv`, so the deterministic suite keeps working with no
`.env` present at all. A real, already-exported environment variable always wins over a
value from `.env`, so a one-off override still works:

```fish
env ASSISTANT_LLM_MODEL=some/other-model composer run test:eval
```

See `.env.example` for the OpenRouter-specific gotcha around the base URL's path.

## Naming

| Thing | Value |
|---|---|
| Plugin name | `swag-assistant-starter-kit` |
| Composer package | `swag/assistant-starter-kit` |
| Plugin class | `SwagAssistantStarterKit` |
| PHP namespace | `Swag\AssistantStarterKit` |
