# Design: Shopping Assistant Starter Kit (v0 prototype)

- **Date:** 2026-08-18
- **Author:** Robin Schulte (with Claude)
- **Stakeholder:** Juan — Linear `IDEA-9`, initiative *Human-to-agent experiences in owned surfaces*
- **Deadline:** Tuesday 2026-08-25 (≈ 4.5 working days)
- **Status:** approved for implementation

## 1. Context

Shopware ships no shopper-facing conversational assistant. Copilot is Admin-only; Agentic
Commerce points outward to third-party agents. See `VISION.md` for the full gap analysis.

The project brief (`IDEA-9`) asks for more than a chatbot shell: *"the safe, observable,
eval-driven path for piloting a real assistant on a real Shopware sales channel."*

**Two framings existed and have been reconciled.** The Linear text describes a *starter kit
for agencies* ("test in days", "two agencies produce demos"). The stakeholder has since
asked for *native Shopware integration across hosting models*. This build follows the
second framing, reduced to a lab prototype. The Linear description still carries the old
text and should be updated.

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | **One PHP plugin**, not an App + app server | SaaS is out of scope for now, so the App's multi-tenancy cost (registration handshake, tenant isolation, quota, uptime, DPA) buys nothing. Plugins are self-hosted/PaaS only — confirmed in the Shopware docs |
| D2 | **`CommerceGatewayInterface` as the single seam** | Three independent payoffs: SaaS portability, evals without a shop, agent work before the environment exists. See `ARCHITECTURE.md` |
| D3 | **Model emits product IDs; the server renders facts** | Makes inventing a price structurally impossible and removes the payoff from prompt injection |
| D4 | **Variant-level resolution is mandatory** | Parent aggregate stock is the failure that cancels orders. Highest-cost defect in this product class |
| D5 | **Blocklist as a filter, pre- and post-retrieval** | A prompt-level blocklist cannot pass a compliance review; blocked items must never enter model context |
| D6 | **Capability control by tool-list construction** | A disabled tool is never shown to the model. Never rely on a model declining |
| D6b | **Tool contract is public API and is shaped correctly now** | `ToolInterface`/`ToolResult` are the one thing expensive to change once third parties depend on them. `ToolResult.cards` keeps fact rendering and ID validation intact for foreign tools; `authority()` lets policy gate new tools without policy changes; grounding services are injected rather than reimplemented. Other extension points stay deferred |
| D7 | **OpenAI-compatible LLM interface** | Merchant flexibility (Azure, local, OpenRouter); Claude still reachable via its compat endpoint. Prior art: `PageAgentShopwareBridge` |
| D8 | **Retrieval Tier 0 only** (facet-grounded structured queries) | Query expansion (Tier 1) and a semantic index (Tier 2) are deferred. No vector store: it is a sync problem, and it would only ever be a candidate generator, never a source of truth |
| D9 | **Evals read the trace, not the prose** | Deterministic, no LLM judge, near-zero cost. Consequence: the trace is a prerequisite, not a nice-to-have |
| D10 | **Evals run against fixtures only in v0** | No Shopware integration-test harness this week. Keeps the suite runnable in seconds and before the environment exists |
| D11 | English only | Removes locale-mismatch grounding from v0 |
| D12 | Repo internal, opened with the demo | Agent-written code plus a public repo is an avoidable secret-leak risk while there is nothing to show |

## 3. Scope

### Must have — this is the demo

1. Chat widget in the storefront of a real 6.7 shop
2. A shopper question answered from real catalog data: correct variant, real price,
   **variant-level stock**, working URL
3. "add that to my cart" → the item is in the shopper's real cart; the cart page proves it
4. A terminal eval run: 6 journeys green, including the injection fixture

### Should have

5. Trace view in the Administration via generated `admin-ui` over the custom entities
6. `config.xml`: agent voice, blocklist, add-to-cart toggle, kill switch, daily cap

### Explicitly cut from v0

Tier 1 query expansion · SSE streaming · catalog report card · Shopware
integration-test harness · live eval suite against a real catalog · rate/cost limiting
beyond a simple counter · escalation destination config · compatibility mapping ·
MCP/WebMCP/UCP · voice/avatar/video · headless storefront support · SaaS

## 4. Acceptance criteria

| # | Criterion | How it is verified |
|---|---|---|
| A1 | A variant question returns the variant's own stock, not the parent's | Journey `variant_stock`, `stock_source == variant` |
| A2 | No product outside the retrieved set ever appears in a reply | Journey assertions, `invented_product_ids` empty |
| A3 | A blocked product is absent from model context and from output | Journey `blocked_item`, 3/3 runs |
| A4 | The injection fixture produces no false price | Journey `injection_discount`, `no_unbacked_price_in_prose` |
| A5 | `add_to_cart` puts the correct variant in the real cart | Manual demo run plus cart page |
| A6 | Every turn produces a persisted trace with all pipeline stages | Admin trace view or DB query |
| A7 | The eval suite runs with no Shopware instance present | `vendor/bin/phpunit --group=eval` against `FixtureCommerceGateway` |

## 5. Fixtures and journeys (v0 set)

12 fixtures, English. Each carries one deliberate defect:

`fx-001` no description · `fx-004` variant missing an attribute · `fx-007`/`fx-008` near
duplicates · `fx-011` discontinued, stock 0 · `fx-014` age-restricted (blocklist target) ·
**`fx-017` injection payload in the description** · `fx-019` price 0.00 · `fx-021`
miscategorised · `fx-026` sold-out Blue/M variant with a differing Black/M price ·
`fx-030` 40 variants (variant-matrix and latency stress) · plus two filler products
for ranking.

Six journeys: `price_constraint`, `variant_stock`, `variant_price`, `blocked_item`,
`injection_discount`, `cart_add`.

## 6. Day plan

| Day | Build | Robin |
|---|---|---|
| Tue 18 (rest) | — | dev environment |
| Wed 19 | plugin skeleton · gateway + DTOs · `FixtureCommerceGateway` · LLM client · 3 assertions + 6 journeys (red) | environment done, plugin installable |
| Thu 20 | `DalCommerceGateway`: facets, search, **variant resolution** · `FactRenderer` → assertions green | verify against a real catalog |
| Fri 21 | agent loop + 4 tools · blocklist filter · trace entities + migration | first real conversation in the terminal |
| Mon 24 | storefront widget (no streaming) · `add_to_cart` · admin trace view · `config.xml` | demo run-through, bugfixing |
| Tue 25 AM | buffer | demo |

Wednesday is only half usable without a running environment, so the environment setup is
the single most important step of the week.

## 7. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Deadline pressure kills D3/D4/D5 and the demo shows a chatbot shell — failing its own purpose | **high** | These three total ~1.25 days. They are non-negotiable; cut Should-have items first |
| Wrong FQCNs for 6.7 services cost hours | medium | Verify against the installed instance before writing the gateway |
| Dev environment not ready Wednesday | medium | Fixture-based work is unblocked regardless |
| Catalog too thin for a convincing demo | medium | Choose the demo shop's catalog on Monday, once quality is measurable |
| Weak model behind the OpenAI-compatible endpoint reads as a bad product | low | Use a strong model for the demo; document a minimum capability |

## 8. Open questions

| # | Question | Blocks |
|---|---|---|
| Q1 | Is the Tuesday session a demo *with Juan* or an internal update? If internal, Monday is better spent on grounding quality than on the admin UI | Monday's priority |
| Q2 | Is the admin trace view Must or Should for that audience? | Monday's priority |
| Q3 | Which shop and catalog for the demo? | Monday |
| Q4 | Should the Linear `IDEA-9` description be rewritten to the new framing? | nothing technical |

## 9. Follow-ups after the demo

`SECURITY.md` (threat model: catalog injection, public chat endpoint, zero-authority) ·
`CONFIG.md` · ADRs for D1–D12 while the reasoning is still fresh · Tier 1 retrieval ·
catalog report card · live eval suite · SaaS path via `shopware/app-bundle`
