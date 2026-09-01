<img src="docs/assets/readme-header.svg" alt="Shopware — Shopping Assistant Starter Kit" width="100%">

[![Quality Gate](https://github.com/agentic-commerce-lab/shopping-assistant-starter-kit/actions/workflows/quality-gate-strict.yml/badge.svg)](https://github.com/agentic-commerce-lab/shopping-assistant-starter-kit/actions/workflows/quality-gate-strict.yml)
[![Release](https://github.com/agentic-commerce-lab/shopping-assistant-starter-kit/actions/workflows/release.yml/badge.svg)](https://github.com/agentic-commerce-lab/shopping-assistant-starter-kit/actions/workflows/release.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-189eff)](LICENSE)

A Shopware 6.7 plugin that puts a **shopper-facing, merchant-operated** conversational shopping
assistant in the storefront — grounded in the shop's own catalog, observable from the Administration,
and built to be forked.

> **Research preview / lab prototype.** Not production software: no support, no upgrade guarantees,
> no Store release.

A shopper asks *"do you have the trail jersey in blue, size M?"* and gets an answer built from real
catalog data — the correct variant, its real price, its real stock, a working link. *"Add that to my
cart"* lands in their actual cart, and checkout is the shop's normal checkout.

**Prices, stock, URLs and images are rendered server-side from the retrieved record**, so the model
never types a number a shopper reads. That is structural, not a prompt instruction.

## Download

**[⬇ SwagAssistantStarterKit.zip](https://github.com/agentic-commerce-lab/shopping-assistant-starter-kit/releases/latest/download/SwagAssistantStarterKit.zip)**
· [all releases](https://github.com/agentic-commerce-lab/shopping-assistant-starter-kit/releases)

Built on every `v*` tag by [`release.yml`](.github/workflows/release.yml). **The zip is the plugin,
not its PHP dependencies** — Shopware cannot autoload those from inside a plugin, so they have to
reach the shop's vendor tree. [The manual](docs/manual.md#from-the-release-zip-instead) has the
command.

## What it does

| | |
|---|---|
| **Grounded answers** | Search, compare and inspect products through the DAL, in the shopper's real `SalesChannelContext` — customer-group and rule-based prices are correct for free |
| **Six tools** | `search_products`, `get_product`, `add_to_cart`, `compare_products`, `search_shop_info`, `escalate`. A tool the merchant switches off is *never constructed*, so the model cannot see it |
| **Shop knowledge** | Legal pages, shipping info and uploaded PDFs as vectors — MariaDB 11.7+ natively, any other database in PHP |
| **Escalation** | Order status, returns and account questions get a merchant-configured route instead of a guess, and may not claim a human was notified |
| **Observability** | Every turn in the Administration: what was understood, retrieved, rendered, refused — and the system prompt it ran with. Exportable as JSON |
| **Widget** | Ships compiled, no Node toolchain in the shop. Neutral icon or animated creature, merchant colors, resizable, 2.3 KB gzipped on a page that never opens it |
| **Guard rails** | Per-caller rate limit and an opt-in daily cap, both refusing before any spend; a `maxCartValue` on the cart tool; SSRF guard on the model endpoint |
| **Eval suite** | Fifteen journeys with deterministic assertions, skipped cleanly without a real endpoint |

**It deliberately does not** complete an order or take payment, set prices or negotiate, answer
order-status or account questions, or invent a product, a price or a stock level.

## Architecture

**One Shopware plugin. No external service, no app server, nothing we operate.**

```
Storefront page
  └── chat widget (Twig + vanilla JS) ── POST /assistant/chat
        └── AssistantController          ← SalesChannelContext injected: real session,
              │                            customer group, rules, prices
              ├── RequestBudget           ← refuses before any spend
              ├── Symfony AI Agent        ← tool loop, messages, streaming
              │     ├── our InputProcessors   context window
              │     ├── our OutputProcessors  validate ids, render facts, audit prose
              │     └── BoundedToolbox        the real cap on tool calls
              │           └── Generic platform ──► OpenAI-compatible endpoint
              └── CommerceGatewayInterface
                    └── DalCommerceGateway  → DAL / SalesChannel services

Administration
  └── hand-written module over the trace entities
```

The runtime is [`symfony/ai-agent`](https://github.com/symfony/ai) 0.12 — we do not write a tool
loop. We own the grounding, and it plugs into `InputProcessorInterface`, `OutputProcessorInterface`
and the toolbox. The platform is the `Generic` bridge: OpenAI-compatible completions against a
configurable base URL. `CommerceGatewayInterface` is the one abstraction committed to up front, and
only our own DTOs cross it — which is what lets the eval suite run with no Shopware and no database.

Both AI packages are pinned exactly, for a reason worth reading before loosening it:
[ARCHITECTURE.md](ARCHITECTURE.md#agent-runtime-symfony-ai) and
[ADR 0001](docs/adr/0001-symfony-ai-as-agent-runtime.md).

## Extensibility

Every seam is a tagged service or a decoration — no core patches, no forking to add a tool.
**[docs/extending.md](docs/extending.md) has a worked example of each one**, including why tools are
factories and why a plain tool cannot reach the catalog (so it cannot return a price).

| I want to… | How |
|---|---|
| Add a tool answering from my own data | `ToolFactoryInterface`, tag `swag_assistant.tool_factory` |
| Add a tool answering from the catalog | `GroundedToolFactoryInterface`, tag `swag_assistant.grounded_tool_factory` |
| Change the system prompt | decorate `PromptProviderInterface` |
| Use a different model provider | decorate `LlmPlatformInterface` |
| Send turns to my analytics | `TraceSinkInterface`, tag `swag_assistant.trace_sink` |
| Read a document format we do not | `TextExtractor`, tag `swag_assistant.text_extractor` |
| Embed differently, or store vectors elsewhere | replace `Embedder` / `PassageStore` |
| Swap the catalog backend | decorate `CommerceGatewayInterface` — **and its four capability interfaces**, or the assistant quietly degrades |
| Change the widget's markup | override one of six named Twig blocks |
| Open the panel from my own button | dispatch `swag-assistant:toggle` on the widget root |

Ranking rules, a new knowledge *source* and MCP surfaces are **not** seams yet.

## Install it

**One step is not optional.** `composer require` pulls `symfony/ai-generic-platform`, whose Flex
recipe writes an `ai:` config key nothing can load — and the *whole storefront* returns 500. Creating
the files first prevents it; Flex never overwrites an existing one.

```fish
mkdir -p config/packages
for f in ai_generic_platform ai_maria_db_store
    printf '# Intentionally empty — see SwagAssistantStarterKit README.\n' > config/packages/$f.yaml
end

composer config repositories.assistant '{"type":"path","url":"../shopping-assistant-starter-kit","options":{"symlink":true}}'
composer require "swag/assistant-starter-kit:*@dev"
bin/console plugin:refresh
bin/console plugin:install --activate SwagAssistantStarterKit
bin/console cache:clear && bin/console theme:compile
```

## Configure it

### The model — required, nothing answers without it

Any OpenAI-compatible chat-completions endpoint. Set it in the Administration under **Language
model**, or as environment variables, which take precedence because Shopware's system config has no
secret storage — a key typed into the admin form is readable by anyone with config access and travels
in every database backup.

```fish
bin/console system:config:set SwagAssistantStarterKit.config.llmBaseUrl "https://openrouter.ai/api"
bin/console system:config:set SwagAssistantStarterKit.config.llmModel   "openai/gpt-4o-mini"
bin/console system:config:set SwagAssistantStarterKit.config.llmApiKey  "sk-…"

# or, winning over the above:
# ASSISTANT_LLM_BASE_URL, ASSISTANT_LLM_MODEL, ASSISTANT_LLM_API_KEY
```

Until all three are set the chat endpoint answers **503** and no orb renders. The base URL is the
host **without** the version path: the platform appends `/v1/chat/completions` itself, so
`https://openrouter.ai/api/v1` becomes `…/v1/v1/chat/completions` and fails.

### Shop knowledge (RAG) — off by default

Answers about legal pages, shipping and uploaded PDFs come from a vector index, not the catalog.
**It needs both halves** — the switch *and* an embedding model. Either one alone leaves the feature
off, with no tool in the model's schema.

```fish
bin/console system:config:set SwagAssistantStarterKit.config.enableShopKnowledge true
bin/console system:config:set SwagAssistantStarterKit.config.embeddingModel "baai/bge-m3"
```

The embedding model reuses the chat model's base URL and key, so it must be one your provider serves
at `/v1/embeddings`. Index from the Administration's **Assistant shop information** screen or with
`bin/console swag:assistant:shopinfo --index=…`; `autoIndexShopPages` keeps them current on change.
**Changing the embedding model makes every indexed document unusable** — delete and index again.

Two environment facts decide how well it runs, and neither breaks the shop: `symfony/ai-store` must
be in the shop's vendor tree or the feature stays off by design, and on MariaDB 11.7+ with
`symfony/ai-maria-db-store` the vectors are indexed natively — everywhere else they are compared in
PHP, which is exact but linear. [The manual](docs/manual.md#requirements) has the detail.

### Everything else

| Card | Settings |
|---|---|
| **Assistant status** | `widgetEnabled` — stops it being *shown*; the separate off switch stops it *answering* |
| **Limits** | `enableAddToCart`, `maxItemQuantity`, `maxCartValue`, `maxToolCallsPerTurn` (20) |
| **Request limits** | `requestsPerMinute` (60), `dailyRequestCap` (0 = unlimited) — both refuse before any spend |
| **Voice and catalogue scope** | `agentVoice`, `blockedProducts`, `blockedCategories` |
| **Handing over to a human** | `enableEscalation`, `escalationUrl`, `escalationMessage`, plus `enableMatchReasons` and `enableCompareProducts` |
| **Appearance** | `entryPointStyle` (`icon` or `creature`), `primaryColor`, `secondaryColor` |
| **Storefront widget** | `assistantName`, `greeting`, `greetingDe`, `greetingEn` |
| **Logging · Data retention** | `logTraces`, `traceRetentionDays` (30) |

A `0` means **unlimited** in every numeric limit but one: `maxToolCallsPerTurn` takes no zero and
falls back to 20, because it bounds a model that has already started looping. Every switch that turns
a capability off removes the tool rather than forbidding it, so the model never sees a tool it is not
allowed to call.
**[The manual](docs/manual.md)** explains what each one actually does, and why the defaults are what
they are.

## Documents

| Document | Read it for |
|---|---|
| [docs/manual.md](docs/manual.md) | Requirements, install, every setting, the widget, the probe commands |
| [docs/extending.md](docs/extending.md) | Every extension seam, with a worked example — read before forking anything |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Structure, interfaces, pipeline, data model, the rulings behind them |
| [VISION.md](VISION.md) | Why this exists, who it is for, what counts as success |
| [GLOSSARY.md](GLOSSARY.md) | Terms that have burned us before — read this first if you are new |
| [AGENTS.md](AGENTS.md) · [docs/adr/](docs/adr/) | Conventions the quality gate enforces; decisions of record |
| [docs/superpowers/](docs/superpowers/) · [docs/HANDOFF.md](docs/HANDOFF.md) | The plans each feature was built from, and the failures that shaped it — 29 source and test files cite one by path |

## Development

```fish
composer install
composer run test               # deterministic suite; never calls a model
composer run quality            # format, lint, typecheck, file length, dupes, deps, audit
composer run build:storefront   # rebuild src/Resources/app/storefront/dist
```

Never run bare `phpunit`: only the composer script excludes the eval group, which spends real money.
`composer run test:eval` drives fifteen journeys against a real endpoint — copy `.env.example` to
`.env` first, and budget both the minutes and the tokens.

To cut a release, bump `Version::CURRENT` in [src/Version.php](src/Version.php) (and
`tests/SmokeTest.php`), then `git tag v0.2.0 && git push origin v0.2.0`. The workflow refuses a tag
that disagrees with the constant.

---

[MIT](LICENSE). Built by the Agentic Commerce Lab.
