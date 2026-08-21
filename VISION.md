# Vision

## The gap

Shopware has no first-party, shopper-facing conversational assistant.

- **Copilot** is Admin-only — the docs are explicit: it helps merchants *manage* the store.
- **Shopware Intelligence+** extends Copilot; still merchant-facing.
- **Agentic Commerce** (product feed, UCP) points *outward* — it helps third-party agents
  like ChatGPT reach the catalog. The shopper conversation happens on someone else's surface.
- **Deep Search** is shopper-facing but ranked search, not conversation.

Meanwhile the Store carries at least six paid third-party chatbot extensions. So the demand
is demonstrated; the first-party answer is missing.

This project sits in the opposite quadrant from Agentic Commerce: **the merchant owns the
agent, the shopper talks to it, and it happens on the merchant's own storefront.**

## Purpose of this build

**A lab prototype to create appetite.** Not a pilot, not a product, not a Store release.

Success is measured on **Tuesday 2026-08-25**: does a working demo convince people that
this is worth continuing?

Stakeholder: **Juan** (owner of the *Human-to-agent experiences in owned surfaces*
initiative, Linear project *Shopping Assistant Starter Kit*, `IDEA-9`).

## Who has to be convinced

All four groups, and they need different evidence — which is why the documents matter as
much as the demo:

| Audience | What convinces them |
|---|---|
| Shopware product / leadership | `ARCHITECTURE.md`, the eval run, the SaaS portability path |
| Partners / agencies | extension points, setup docs |
| Merchants / design partners | the storefront experience against a real catalog |
| Community | reproducible setup, an honest README |

## What "good" means here

The project brief says the system must be **"more than a chatbot shell."** Three
properties carry that, and they are non-negotiable regardless of deadline pressure:

1. **The model emits product IDs, never facts.** Price, stock, URL and image are rendered
   server-side from the retrieved record. The model is structurally incapable of inventing
   a price — which also removes the payoff from prompt injection.
2. **Variant-level truth.** "Blue, size M, in stock" is a variant fact. Reporting the
   parent's aggregate stock is the single most expensive failure mode: it cancels orders.
3. **The blocklist is a filter, not a prompt instruction.** Blocked products never enter
   the model's context. A prompt-level blocklist cannot pass a compliance review.

A demo that shows a pretty chat window quoting wrong stock does not create appetite. It
creates scepticism, and it fails its own purpose.

## Honest limitations

**Quality depends on the merchant's catalog, and we cannot fix that from here.** The plugin
is installed into a shop that already has products. On a catalog with rich variant
attributes and real descriptions it will do well. On a thin catalog it will not, and no
amount of prompting changes that.

What we *can* do is make it visible: diagnose the catalog and degrade gracefully rather
than guessing. Compatibility questions ("does this fit my 2019 model") are the clearest
case — Shopware has no native compatibility concept, so if the merchant has not modelled
it, the assistant must refuse rather than invent an answer.

The goal is to turn *"your AI is bad"* into *"your products are missing attribute X."*

## Non-goals

| Not doing | Why |
|---|---|
| SaaS support | Plugins are self-hosted/PaaS only. Deferred deliberately; the gateway seam keeps the door open |
| Checkout completion / payment | Store API requires login plus a browser PSP redirect. Handing off is also the correct boundary |
| Discounts, price changes, negotiation | No code path. This is what makes the injection defence work |
| Order status, returns, account data | Needs authenticated context; guest orders are unreachable. Escalate instead |
| MCP / WebMCP / UCP surfaces | Different quadrant; `webmcp-plugin` already exists |
| Voice, avatar, video modalities | Separate Linear issues (ACL-119/120/121) |
| Headless / Frontends storefronts | Twig injection does nothing there; needs its own component |

Escalation means the shopper gets the merchant's configured contact route, rendered server-side. With
none configured — or with escalation switched off entirely — the assistant declines the question
honestly rather than implying a follow-up. Nothing is notified on the merchant's side; that is the
next step, not this one.

## If it continues

The gateway seam (see `ARCHITECTURE.md`) means the SaaS path is a swap of one class plus a
thin Symfony app server — Shopware app servers are PHP (`shopware/app-bundle`). Nothing
built here has to be thrown away to get there.
