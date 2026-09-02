# Manual

Use this guide to install, configure, and operate the Shopping Assistant Starter Kit in a Shopware
shop. It starts where the [README](../README.md) stops and covers the details that prevent common
installation and configuration failures.

Writing code against the assistant? Go to the [extension guide](extending.md).

## On this page

| Task | Section |
|---|---|
| Check compatibility and optional RAG requirements | [Requirements](#requirements) |
| Install from source or a release archive | [Installing it into a shop](#installing-it-into-a-shop) |
| Connect chat and embedding models | [Configuring a model](#configuring-a-model) |
| Configure behaviour, catalogue scope, and cart access | [Assistant behaviour and limits](#assistant-behaviour-and-limits) |
| Control spend, logging, retention, and escalation | [Request limits](#request-limits), [Logging](#logging), [Data retention](#data-retention), [Escalation](#escalation) |
| Configure or customise the storefront widget | [The storefront widget](#the-storefront-widget) |
| Verify a real catalogue or run eval journeys | [Checking the real catalogue](#checking-it-against-the-real-catalogue), [Running the eval suite](#running-the-eval-suite) |

## Requirements

### Core requirements

- Shopware **6.7** (`^6.7`), PHP 8.2+
- An OpenAI-compatible chat-completions endpoint (`base_url` + `model` + `api_key`)

### Shop knowledge (optional)

Shop-information retrieval needs `symfony/ai-store` in the **shop's** vendor directory. The plugin
declares the package, but Shopware only autoloads plugin dependencies from the shop-level vendor
directory. A plugin deployed by symlink or rsync can therefore run without it.

Missing the package does not break product questions. `Core\ShopInfo\ShopInfoAvailability` disables
the embedding model, and the `search_shop_info` tool never enters the model schema. Before this
runtime check existed, configuring embeddings without the package caused every assistant request to
return HTTP 500.

For larger document collections, use **MariaDB 11.7+** with `symfony/ai-maria-db-store`. This enables
native, indexed vector search. Without either requirement, the assistant uses an exact PHP comparison
against vectors stored as JSON. That fallback works with every database supported by Shopware, but
its scan time grows linearly with the number of passages.

> [!NOTE]
> MySQL cannot use the MariaDB store. MySQL before 9.0 has no `VECTOR` type, while MySQL 9 uses a
> different API (`STRING_TO_VECTOR` and `DISTANCE`, with `DISTANCE` limited to HeatWave). A MySQL shop
> therefore uses the portable PHP store.

The native and portable stores use different tables and do not share data. If a shop gains or loses
`symfony/ai-maria-db-store`, re-index every document. The trace event `retrieve.shopinfo.store`
records which implementation answered and why a fallback was selected.

<details>
<summary>Implementation detail: native and portable vector storage</summary>

The native store is `Symfony\AI\Store\Bridge\MariaDb\Store`. It uses `VECTOR` columns, a
`VECTOR INDEX`, and MariaDB's `VEC_DISTANCE_COSINE` / `VEC_FromText` functions. The portable store
uses full float64 values in JSON and compares them exactly in PHP; MariaDB's vector type uses
float32. The portable path is reasonable for the legal pages and uploads of a typical shop, but a
corpus with thousands of passages benefits from native indexing.

</details>

### Language support

- The storefront may be English or German. The assistant answers in the shopper's language and
  falls back to the storefront locale. Other locales fall back to English. Extend
  `Core\Prompt\ReplyLanguage` to add another reply language.
- The catalogue itself must currently be English. Retrieval is keyword-based, so a German query for
  `Kleid` will not find an English product named `Dress`. Reply language and catalogue language are
  separate concerns; only the first is handled automatically.

## Installing it into a shop

Choose the installation route that matches your use case:

- **Developing or modifying the plugin:** use a Composer path repository.
- **Evaluating a packaged release:** install the release zip and add its PHP dependencies to the
  shop separately.

> [!CAUTION]
> Create both placeholder files shown below **before** running `composer require`. Symfony Flex would
> otherwise add unsupported `ai:` configuration and can take down the entire storefront.

### From a Composer path repository

Do not use a bare `custom/plugins` symlink. When Shopware does not manage a plugin through Composer,
it registers only the plugin's own PSR-4 namespace—not the plugin's dependencies. The first shopper
request would therefore fail because `symfony/ai-agent` cannot be loaded.

From the shop's project root:

```fish
# Do this FIRST — see the note below. Two files, and the shop never breaks.
mkdir -p config/packages
for f in ai_generic_platform ai_maria_db_store
    printf '# Intentionally empty — see SwagAssistantStarterKit README.\n' > config/packages/$f.yaml
end

composer config repositories.assistant '{"type":"path","url":"../shopping-assistant-starter-kit","options":{"symlink":true}}'
composer require "swag/assistant-starter-kit:*@dev"
bin/console plugin:refresh
bin/console plugin:install --activate SwagAssistantStarterKit
bin/console cache:clear
```

<details>
<summary>Why the placeholder files are required</summary>

`composer require` installs `symfony/ai-generic-platform`. In a Symfony Flex project such as
`shopware/production`, its recipe creates `config/packages/ai_generic_platform.yaml` with an `ai:`
root key. This plugin constructs the platform itself and does not register `symfony/ai-bundle`, so
the container cannot load that key. The result is *"There is no extension able to load the
configuration for 'ai'"* and HTTP 500 for the entire storefront, not only the assistant.

Creating a comment-only file first prevents the outage. Flex does not overwrite an existing file
unless `--force` is used, and Symfony treats a YAML file containing only a comment as empty. This
behaviour was verified on Shopware 6.7.13 by reinstalling the recipe and confirming that both the
file and the working storefront remained unchanged.

`symfony/ai-maria-db-store` has the same issue: its recipe creates
`config/packages/ai_maria_db_store.yaml` with an `ai.store` key. That is why the command creates two
files. Apply the same precaution before adding any other `symfony/ai-*` package with a Flex recipe.

The recipe belongs to the dependency and runs in the shop, so the plugin cannot prevent it itself.

</details>

### From the release zip instead

The [latest release](https://github.com/agentic-commerce-lab/shopping-assistant-starter-kit/releases/latest)
contains `SwagAssistantStarterKit.zip` with compiled Administration and storefront assets. Unpack it
into the shop's `custom/plugins/` directory.

**It contains the plugin, not the plugin's PHP dependencies**, and that is not an oversight of the
build. Shopware registers only the plugin's own PSR-4 namespace for a manually installed plugin and
does not load a bundled `vendor/autoload.php`. Install the dependencies in the shop's vendor tree:

```fish
composer require symfony/ai-agent:0.12.* symfony/ai-platform:0.12.* \
    symfony/ai-generic-platform:0.12.* symfony/ai-store:0.12.* \
    masterminds/html5:^2.11 smalot/pdfparser:^2.12

# On MariaDB 11.7+, add this too — see Requirements. Without it the shop silently
# lands on the portable PHP store, which is exact but linear.
composer require symfony/ai-maria-db-store:0.12.*
```

Everything else the plugin needs already ships with Shopware. The placeholder files from the
previous section are mandatory here as well, because these `composer require` commands trigger the
same Flex recipes.

After installing the dependencies, refresh and activate the plugin:

```fish
bin/console plugin:refresh
bin/console plugin:install --activate SwagAssistantStarterKit
bin/console cache:clear
```

<details>
<summary>Release-installation verification</summary>

The release route was verified end to end on 2026-09-01 with a fresh `shopware-cli` shop running
Shopware 6.7.13.1, MariaDB 11.8, and PHP 8.5. The plugin installed and activated, all four tables were
created, all six tools were available, and both the storefront and Administration remained at HTTP
200. Flex left both placeholder files byte-identical.

Install `symfony/ai-maria-db-store` on MariaDB 11.7+ if you want the native store. The trace event
`retrieve.shopinfo.store` shows which store answered and why.

</details>

## Configuring a model

### Chat model

Configure the model in the plugin settings under **Language model**, or use the CLI:

```fish
bin/console system:config:set SwagAssistantStarterKit.config.llmBaseUrl "https://openrouter.ai/api"
bin/console system:config:set SwagAssistantStarterKit.config.llmModel "provider/model-id"
bin/console system:config:set SwagAssistantStarterKit.config.llmApiKey "…"
```

You can override these stored values with:

- `ASSISTANT_LLM_BASE_URL`
- `ASSISTANT_LLM_MODEL`
- `ASSISTANT_LLM_API_KEY`

Environment values take precedence because Shopware system configuration is not secret storage. An
API key entered in the Administration is visible to users with configuration access and included in
database backups.

The base URL must identify the host without the version path. The platform appends
`/v1/chat/completions`, so a base URL ending in `/v1` produces a duplicated path and fails.

Until all three settings are available, the chat endpoint returns **503** and the storefront widget
does not render.

<details>
<summary>How environment values are resolved</summary>

`Core\Config\EnvironmentValue` checks the process environment first—for example Docker
`environment:` / `env_file:`, Apache `SetEnv`, an nginx/php-fpm pool, or a systemd unit. It then reads
`$_ENV` and `$_SERVER`, where Symfony Dotenv places values from `.env` and `.env.local`. A file cannot
override a process value deliberately set by the host.

Before September 2026, settings were read through `getenv()` alone. Symfony Runtime boots Dotenv with
`usePutenv(false)`, so values from `.env` reached `$_ENV` but remained invisible to the assistant.
On Shopware 6.7.13.1 this produced a silent 503 and no widget; the fixed lookup returns 200 with the
same file-based configuration. The eval suite did not expose the issue because Shopware's test
bootstrap explicitly enables `usePutenv()`.

</details>

### Shop knowledge and embeddings

Shop knowledge is separate from catalogue search and is off by default. Enable the feature under
**Shop knowledge** and configure an embedding model. Both values are required; if either is missing,
documents are not offered to the model and the tool is absent from its schema.

```fish
bin/console system:config:set SwagAssistantStarterKit.config.enableShopKnowledge true
bin/console system:config:set SwagAssistantStarterKit.config.embeddingModel "baai/bge-m3"
```

The model must be one your provider serves at `/v1/embeddings` — it reuses the chat model's base URL
and key. Then index the shop's pages from the Administration's **Assistant shop information** screen,
or with `bin/console swag:assistant:shopinfo --index=…`. Changing the embedding model afterwards makes
every indexed document unusable: delete and index them again.

`autoIndexShopPages` re-indexes legal and shipping pages in the background after an edit. It is off
by default because every update spends an embedding call. When it is off, use **Index shop pages** in
the Administration after changing a page; otherwise the assistant continues using the last indexed
version.

## Assistant behaviour and limits

These settings control what the assistant can see and do:

| Setting | Default | What it controls |
|---|---|---|
| `assistantEnabled` | on | Whether the assistant answers at all |
| `agentVoice` | empty | Tone and personality only; it cannot grant capabilities or override safety rules |
| `blockedProducts` | empty | Products and all their variants that must never reach the model |
| `blockedCategories` | empty | Category branches whose products must never reach the model |
| `enableAddToCart` | on | Whether the add-to-cart tool and product-card buttons exist |
| `enableCompareProducts` | off | Whether the model can compare products side by side |
| `enableMatchReasons` | off | Whether retrieval exposes deterministic reasons for a match |

Disabled capabilities are removed from the toolbox instead of being described as forbidden in the
prompt. Blocked products and categories are filtered before model context is built.

The **Limits** card bounds cart actions and tool loops:

| Setting | Default | What it controls |
|---|---|---|
| `maxItemQuantity` | 0 — unlimited | Maximum units of one item that the assistant may place in the live cart |
| `maxCartValue` | 0 — unlimited | Highest cart total the assistant may reach, in the sales-channel currency |
| `maxToolCallsPerTurn` | 20 | Maximum lookups in one reply; blank or `0` falls back to 20 |

Item quantity and cart value are checked against the shopper's live cart, not one isolated tool call.
A model cannot bypass them by adding items one at a time. The tool-call limit cannot be disabled
because it stops a model that has entered a loop; reaching it ends the reply with the results already
available.

## Request limits

`POST /assistant/chat` is public and every accepted call can spend model tokens. Configure both
controls in the Administration under **Request limits**:

| Setting | Default | What it does |
|---|---|---|
| `requestsPerMinute` | 60 | Per caller, sliding window. The control that stops a scripted loop |
| `dailyRequestCap` | **0 — off** | Per sales channel, 24h. An opt-in spend ceiling, not an abuse defence |

A refused request returns **429** with a `Retry-After` header. It creates no conversation, trace, or
model call. The widget treats this as temporary and offers a retry button.

The per-caller window is checked first and applies even when the assistant is disabled, so there is
no unthrottled public route. The daily budget is consumed only when a turn could spend tokens. A
disabled shop therefore reports the correct state instead of claiming that its budget is exhausted.

**`0` in either field means unlimited.** It used to mean the opposite — refuse everything — which
made zero the most destructive value a merchant could type into a numeric field, and duplicated a
job the assistant's own off switch already does with a reason the trace can record.

Only the per-caller limit is enabled by default. Enable the daily cap only when you want a hard spend
ceiling: once it is reached, every shopper is blocked until the 24-hour window resets.

Counters live in the shop's cache, not the database, so **clearing the cache resets both windows**.
Behind a proxy or CDN, `framework.trusted_proxies` has to be right or every shopper shares one
window — Symfony's `getClientIp()` is what the per-caller window counts.

<details>
<summary>Compatibility note for earlier releases</summary>

Older releases exposed `dailyRequestCap` without supplying the current count to `GuardCheck`, so the
limit was not enforced except at zero. It is now enforced at the HTTP boundary by
`Core\Policy\RequestBudget` and covered by `tests/Controller/AssistantThrottleTest.php`.

The previous default of 500 could also disable a busy shop without the merchant choosing that trade.
That is why the current default is unlimited.

</details>

## Logging

The Administration conversation list always stores full conversations until the retention task
deletes them. `logTraces` is separate and enabled by default. It writes one operational line per
reply to `var/log/swag_assistant_<env>.log`, including the sales channel, outcome, and duration—but
no shopper messages. Log files are retained for 14 days.

The dedicated file is intentional: a standard production Shopware discards informational messages
sent to its main error log. Turn `logTraces` off if you do not need this operational view.

## Data retention

`swag_assistant_conversation.transcript` contains shopper messages. The plugin therefore prunes old
conversations instead of retaining them indefinitely. Configure the window under **Data retention**:

| Setting | Default | What it does |
|---|---|---|
| `traceRetentionDays` | 30 | Conversations created longer ago than this are deleted, and `ON DELETE CASCADE` takes their trace events |

There is no "keep forever" option. A blank value or `0` falls back to 30 days. This is the only
numeric plugin setting where zero does not mean unlimited.

Set a value per sales channel when retention requirements differ. Each channel uses its own value or
falls back to the shop-wide setting. Conversations whose sales channel has been deleted use the
shop-wide window.

> [!IMPORTANT]
> If you configured per-channel retention before September 2026, review those values. Earlier
> releases applied only the shop-wide setting, so shorter and longer channel-specific windows were
> not honoured.

### Make sure pruning runs

`PruneConversationsTask` is a Shopware `ScheduledTask` dispatched through Messenger. Daily pruning
therefore requires a `messenger:consume` worker plus `scheduled-task:run`, or Shopware's admin worker.
The admin worker runs only while someone has the Administration open. Without either worker, the
task remains `scheduled` and no data is deleted.

Force one run with:

```fish
bin/console scheduled-task:run-single swag_assistant.prune_conversations
```

<details>
<summary>Retention verification</summary>

This behaviour was verified against a real shop on 2026-09-01. With the default window,
conversations older than 30 days and their trace events were deleted, while a 29-day-old conversation
remained. A 7-day setting removed a 10-day-old conversation on the next run. In one run across two
channels, a 1-day channel deleted three-day-old data while a 90-day channel retained forty-day-old
data.

</details>

## Escalation

Order status, returns, and account questions cannot be answered from the catalogue. Escalation gives
the shopper a merchant-configured route instead of allowing the assistant to guess. Configure it
under **Escalation**:

| Setting | Default | What it does |
|---|---|---|
| `enableEscalation` | on | When off, the escalate tool is never constructed, so the model cannot see it or call it. The assistant declines instead |
| `escalationUrl` | — | A path on this shop (`/contact`) or an https URL. Only http and https are accepted — this link is served to every shopper |
| `escalationMessage` | — | Shown above the link. Left empty, a translated default is used |

With no URL configured, the assistant declines and explains what it can help with instead. It must
not claim that a human will follow up, because the plugin does not notify anyone. `EscalateTool` and
the `no_handoff_claim_in_prose` eval assertion enforce that boundary.

> [!IMPORTANT]
> Escalation sends no email, ticket, message, or queue event. It gives the shopper a route they must
> follow themselves. Forwarding transcripts to a support desk requires a custom integration.

Turning off `enableEscalation` removes both the tool and its prompt instruction. The model never sees
a capability it cannot use. `enableAddToCart` follows the same pattern.

The link is rendered server-side from the setting and is never in the model's context, so it cannot
be paraphrased into a broken URL — the same rule that governs prices and stock. A reloaded
transcript rebuilds it from configuration rather than replaying it, so a contact route the merchant
has since moved or withdrawn is not still offered.

<details>
<summary>Why hand-off claims are tested</summary>

An earlier prompt only said that a question "needs the shop team". In six of six live runs, the
model claimed that it had flagged the request even though no notification existed. Before
2026-08-22, the tool itself also returned "Handing this over to a human" without a destination.
That history is why the current wording and eval assertion are explicit.

</details>

## The storefront widget

The widget ships compiled, so a merchant does not need a Node toolchain. After installing the plugin
and configuring a model, compile the theme:

```fish
bin/console theme:compile
```

Shopware's PHP SCSS pipeline builds the styles. Compiled JavaScript is committed under
`src/Resources/app/storefront/dist`, so an installed plugin works without a frontend build.

The entry point renders only when a model is configured and both `assistantEnabled` and
`widgetEnabled` are on. The chat endpoint remains available when the widget alone is disabled, so a
custom client can continue using it. When the assistant itself is disabled or unconfigured, the
endpoint reports that state instead of presenting a broken panel to shoppers.

`assistantEnabled` controls whether the assistant answers. `widgetEnabled` controls only whether the
shipped widget is shown. Both are under **Assistant status**; `assistantName` is under
**Storefront widget**.

The greeting and three suggestion chips are snippets, not system-config values. Edit them through the
language-aware fields in the configuration form. Unlike the other fields, they are not scoped per
sales channel: Shopware assigns snippet sets by language and storefront domain. To show different
greetings on two channels, assign their domains different snippet sets under **Settings › Snippets**.

Leave a suggestion empty to hide that chip. If all three are empty, only the greeting is shown.

<details>
<summary>Greeting migration from versions before 0.2.0</summary>

Earlier versions stored the greeting in `greeting`, `greetingDe`, and `greetingEn` system-config
fields. A migration moves those values into the matching snippet sets and prefers the shop-wide value
when channels previously differed.

</details>

### Changing it

```fish
composer run build:storefront   # rebuilds src/ into dist/
bin/console theme:compile       # in the shop
```

CI fails when storefront source changes without a matching `dist` rebuild.

<details>
<summary>Why CI does not compare rebuilt assets byte for byte</summary>

The build is deterministic at one path but not across paths because webpack derives module IDs from
the absolute location. Identical source built in two directories produces identical chunk bodies
under different filenames. A rebuild-and-diff job would therefore fail while proving nothing.

</details>

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

**[extending.md](extending.md) has a worked example of each seam.** In short:

| I want to… | How |
|---|---|
| Add a tool answering from my own data | `ToolFactoryInterface`, tag `swag_assistant.tool_factory` |
| Add a tool answering from the catalogue | `GroundedToolFactoryInterface`, tag `swag_assistant.grounded_tool_factory` |
| Change the system prompt | decorate `PromptProviderInterface` |
| Use a different model provider | decorate `LlmPlatformInterface` |
| Send turns to my analytics | `TraceSinkInterface`, tag `swag_assistant.trace_sink` |
| Read a document format we do not | `TextExtractor`, tag `swag_assistant.text_extractor` |
| Embed with a different model, or store vectors elsewhere | replace `Embedder` / `PassageStore` |
| Swap the catalogue backend | decorate `CommerceGatewayInterface` — **and implement its four optional capability interfaces**, or the assistant quietly degrades |
| Open the panel from my own button | dispatch `swag-assistant:toggle` on the widget root |
| Assert something about my own tool in an eval journey | name your `Assertion` class in the journey's `assertions` map |

Ranking rules, a new knowledge *source* and MCP surfaces are **not** seams yet; `docs/extending.md`
says so plainly rather than leaving you to grep for them.

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

The orb, panel avatar, and thinking indicator share one implementation: the
`swag_assistant_face` Twig include, `swag-assistant-bubble` SCSS mixin, and
`assistant/creature.js` behaviour module. The `--swag-assistant-unit` custom property scales the
same markup from the 60px entry point to the 32px header avatar.

Expressions use one `data-mood` attribute: `idle`, `happy`, `laugh`, `curious`, `wow`, `sleepy`, or
`focus`. JavaScript sets only that state; CSS defines the appearance. `prefers-reduced-motion`
disables movement while preserving the expression, so meaning is not lost.

The widget uses CSS keyframes and a small Web Animations layer rather than an animation library. The
orb chunk is 2.3 KB gzipped. Gestures use `composite: 'add'`, allowing a hop to compose with the
resting float.

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

**Budget the time and the spend.** The suite currently contains 36 journeys, each with up to three
runs per archetype, and every run is a real turn. The full suite takes several minutes and costs real
tokens.

`tests/bootstrap.php` loads a local `.env`. On a credentialed machine,
`vendor/bin/phpunit tests/Eval/` therefore runs every journey. Use `--exclude-group eval` when you
only want deterministic tests. Composer's default five-minute timeout was too short for the full
suite, so `composer.json` sets `process-timeout: 1800`.

To spend less, filter to one journey:

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

## Running on Shopware 6.6

The plugin supports **6.6.10.23 or newer** alongside 6.7. Three things make that work, and each was
measured against a real 6.6 shop rather than reasoned about.

**The Symfony pin is the version gate.** `composer.json` keeps `symfony/* ~7.4.0` deliberately. The
Symfony AI packages this plugin is built on require Symfony 7.3 or newer, and 6.6 only reached 7.4 at
patch 6.6.10.23 — earlier 6.6 ships 7.2. Widening that pin would let the plugin install onto a shop
where the AI stack cannot run; leaving it narrow makes Composer refuse up front, with a message that
names the real reason.

**Doctrine DBAL is not actually a barrier.** 6.6 stays on DBAL 3.x and 6.7 moved to 4.x, so the
constraint reads `^3.9 || ^4.0`. Nothing here uses a DBAL 4 API: the plugin's queries are
`executeStatement`, `fetchAllAssociative`, `fetchFirstColumn`, `fetchOne`, `fetchAssociative` and
`ArrayParameterType`, all of which exist in 3.6+. All four migrations were verified running under
DBAL 3.10.

**The administration ships two bundles.** 6.6 loads plugin admin assets from
`Resources/public/administration/js/<name>.js` (webpack); 6.7 reads
`Resources/public/administration/.vite/entrypoints.json` (Vite). Both layouts are committed, and each
version picks up the one it knows. On a 6.6 shop with only the Vite layout present the plugin
installs, migrates and answers — but the administration silently loads nothing: no Assistant menu, no
settings form (its two custom components are missing), and none of the admin snippets. `shopware-cli
extension build .` selects the toolchain from the `shopware/core` constraint, so building on this
branch produces the webpack half; the Vite half must be built from a 6.7 checkout.

**One thing 6.6 cannot have.** `config.xml` used a `<subtitle>` on each card, which 6.6's
`config.xsd` does not define — and an unknown element makes it reject the whole file, so the plugin
settings return HTTP 400 and the merchant sees no form at all. The subtitles are gone. Four of the
eight only restated a field's own help text; the other four carried something of their own and were
moved into the help text of the field they were about.
