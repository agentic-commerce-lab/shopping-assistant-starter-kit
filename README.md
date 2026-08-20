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

The merchant sees every conversation in the Administration: what the assistant
understood, which products it retrieved, which facts it rendered, and what it refused.

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
composer config repositories.assistant '{"type":"path","url":"../shopping-assistant-starter-kit","options":{"symlink":true}}'
composer require "swag/assistant-starter-kit:*@dev"
bin/console plugin:refresh
bin/console plugin:install --activate SwagAssistantStarterKit
bin/console cache:clear
```

> **One manual step, and skipping it leaves a dead shop.** `composer require` pulls
> `symfony/ai-generic-platform`, and in a Symfony Flex project — which `shopware/production` is —
> Flex applies that package's recipe and writes `config/packages/ai_generic_platform.yaml` containing
> an `ai:` root key. Nothing registers `symfony/ai-bundle` (this plugin builds its platform itself,
> see `docs/adr/0001-symfony-ai-as-agent-runtime.md`), so the next `bin/console` call dies with
> *"There is no extension able to load the configuration for 'ai'"* — and the storefront with it.
>
> **Delete that file after installing:**
>
> ```fish
> rm config/packages/ai_generic_platform.yaml
> bin/console cache:clear
> ```
>
> It is not fixable from inside the plugin: the recipe belongs to a dependency and is applied by the
> *shop's* Flex.

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
