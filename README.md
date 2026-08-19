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
