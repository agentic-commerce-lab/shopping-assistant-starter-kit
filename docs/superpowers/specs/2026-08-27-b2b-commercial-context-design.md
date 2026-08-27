# Design: B2B Commercial Shopping Context

- **Date:** 2026-08-27
- **Author:** Robin Schulte with Codex
- **Status:** proposed for implementation; awaiting final spec review
- **Target:** Shopware 6.7 plugin with optional Shopware Commercial B2B Components support

## 1. Context

The shopping assistant already runs inside a Shopware storefront request and uses the active
`SalesChannelContext`. Product cards read `SalesChannelProductEntity::getCalculatedPrice()`, product
search uses the sales-channel product repository, and Shopware recalculates the cart. Those choices
already make ordinary customer-group and Rule Builder prices contextual without an assistant-owned
pricing system.

The missing guarantee is stronger: a logged-in B2B shopper must receive recommendations that are
valid for the currently selected company or organisation unit. Shopware Commercial B2B Components
can assign individual prices and advanced product catalogues to companies and organisation units.
An employee can switch that context without changing login identity. Customer ID and sales-channel
ID alone therefore do not identify the commercial scope.

The current conversation token is also accepted as a bearer credential. History lookup does not
check the current customer or commercial context, and the browser stores only one token. That is
incompatible with account-aware B2B conversations.

This design adds the smallest context boundary that closes those gaps. Shopware remains responsible
for identity, pricing, catalogues, availability, quantity rules, and cart calculation. The assistant
resolves the active scope, prevents cross-scope conversation access, and consumes Shopware's results.

## 2. Goal

Fulfil this journey:

> As a B2B shopper, I want the assistant to respect my customer account, catalog permissions,
> pricing context and available products so that recommendations are commercially valid.

For this design, "commercially valid" means:

1. Search and direct lookup expose only products allowed in the active Shopware context.
2. Cards show Shopware's calculated price for the active customer, company or organisation unit.
3. A stated quantity uses the applicable Shopware-calculated volume tier.
4. Minimum, purchase-step and maximum-purchase constraints are enforced before cart mutation.
5. Shopware recalculates and validates the final cart.
6. Conversation history never crosses customer, sales-channel, company or organisation-unit scope.
7. Logging out and back into the same context in the same browser session restores that context's
   conversation.

## 3. Scope

### In scope

- Ordinary guest and logged-in Shopware contexts.
- Optional Shopware Commercial B2B Components integration.
- Active company or organisation-unit resolution.
- Shopware Individual Pricing results, including an applicable volume tier for a requested quantity.
- Shopware Advanced Product Catalogue enforcement when B2B Components are active.
- Product minimum purchase, purchase step, maximum purchase and pack unit.
- Context-bound conversation tokens and per-context browser-session persistence.
- A compact, server-rendered active commercial-context label.
- A minimal, grounded answer to questions about the active shopping context.
- Safe degradation when Commercial is absent and safe refusal when an expected Commercial context is
  invalid.

### Out of scope

- Assistant-owned price, discount, tax or currency calculations.
- Support for the deprecated Shopware B2B Suite.
- A provider-neutral framework for third-party B2B extensions.
- Employee, role or organisation-unit administration.
- Budgets, approvals, quotes, purchase orders and credit limits.
- Order history, returns, saved addresses and payment terms.
- Preference memory or purchase-history personalization.
- Merging guest and authenticated conversations.
- Cross-device conversation restoration.
- Persisting raw agreement definitions or exposing them to the model.

## 4. Decisions

| ID | Decision | Rationale |
|---|---|---|
| B1 | Add one immutable shopping-context contract | Identity and commercial scope are currently implicit and cannot be validated consistently. |
| B2 | Keep Shopware as the price authority | Reimplementing Individual Pricing would duplicate rule priority, dates, tax, currency, volume and organisation logic. |
| B3 | Make Commercial support optional | A shop without Commercial must retain the current plugin behavior and compilation path. |
| B4 | Scope conversations by customer, sales channel and active commercial context | One employee can switch organisation units without changing customer identity. |
| B5 | Keep guest history separate | Automatically attaching a guest transcript to the next login is unsafe on shared devices. |
| B6 | Store one browser token per opaque context key | This preserves logout/login and organisation switching without adding cross-device account memory. |
| B7 | Fail closed only for commerce when an expected B2B context is invalid | Product and cart actions can become commercially wrong; general shop-information answers remain safe. |
| B8 | Verify repository filtering before adding a catalogue adapter | If Commercial already decorates the sales-channel repository, another filter would duplicate behavior and risk drift. |
| B9 | Apply an additional catalogue restriction before retrieval, never after | Unauthorized products must not enter model context, traces or rendered cards. |
| B10 | Show the active Commercial context in the widget header | A shopper must know which company or organisation unit owns the displayed prices and catalogue. |
| B11 | Resolve the applicable tier only when quantity is stated | This supports common B2B questions without turning cards into complete price-list tables. |

## 5. Architecture

### 5.1 Shopping context

Add a Shopware-independent immutable `ShoppingContext` at the smallest shared core boundary. It
contains:

- Mode: `guest`, `customer`, `commercial`, or `commercial_unavailable`.
- Sales-channel ID.
- Customer ID for authenticated modes, server-side only.
- Commercial context ID for the active company or organisation unit, server-side only.
- Optional safe display label.
- Currency code.
- Whether product and cart capabilities are allowed.
- An opaque browser storage key.

The value object carries resolved facts. It does not query Shopware, calculate prices, or filter
products.

### 5.2 Context resolution

A `ShoppingContextResolver` resolves the value once per storefront request.

The core resolver reads the existing request-scoped `SalesChannelContext`:

- No customer produces `guest`.
- A customer without an active Commercial company or organisation context produces `customer`.
- Absence of Shopware Commercial is an ordinary supported state, not an error.

An optional Commercial bridge enriches the result:

- An active and valid root company or organisation unit produces `commercial`.
- Commercial organisation functionality that should have an active scope but cannot resolve it
  produces `commercial_unavailable`.
- Commercial installed but inactive for this customer remains `customer`.

The bridge is registered only when the required Shopware Commercial marker and context service are
available. The concrete Commercial symbols must be selected from the installed supported package;
the plugin must not use reflection, internal database tables, request-attribute guessing, or a
service locator to infer the active organisation.

The repository currently does not contain Shopware Commercial. Implementation and final integration
verification therefore require a Shopware 6.7.8 or newer installation with the Commercial extension,
Organisation Units, Advanced Product Catalogues and Individual Pricing enabled. The project lock at
the time of this design is Shopware 6.7.13.0.

### 5.3 Consumers

The resolved context has four consumers:

1. `AssistantController` selects or validates the conversation token.
2. Agent construction includes or removes grounded commerce tools.
3. Product/card endpoints refuse commerce data in `commercial_unavailable` mode.
4. The storefront receives the opaque storage key and safe display label.

The existing `SalesChannelContextProvider` remains the sole request-context source inside the DAL
gateway. `ShoppingContextResolver` does not replace it.

## 6. Context state behavior

| State | Product tools | Cart tools | Shop information | Context label |
|---|---:|---:|---:|---|
| Guest | Enabled | Enabled when merchant configuration permits | Enabled | None |
| Ordinary customer | Enabled | Enabled when merchant configuration permits | Enabled | None |
| Commercial resolved | Enabled | Enabled when merchant configuration permits | Enabled | Always shown |
| Commercial unavailable | Removed | Removed | Enabled | Non-sensitive unavailable notice |

`commercial_unavailable` is an edge case, not the no-Commercial fallback. It applies only when the
Commercial integration says an organisation context is required for the current shopper but cannot
identify it.

Grounded tools are removed from the toolbox in that state. Prompt wording alone is not an authority
control. Page-product pre-grounding and stored-card rehydration follow the same restriction.

## 7. Pricing and purchasing quantities

### 7.1 Pricing authority

The plugin must continue reading Shopware-calculated values:

- `calculatedPrice` for the ordinary contextual unit price.
- `calculatedPrices` for applicable quantity tiers.
- Currency and tax display from the active sales-channel context.
- The cart result for the final charged amount.

The assistant must never receive a discount percentage and apply it itself. It must never submit a
client-supplied unit price to the cart.

This supports Shopware Individual Pricing outcomes such as percentage, fixed-amount, fixed-final and
volume discounts without coupling the plugin to their rule representation.

### 7.2 Requested quantity

Add an optional positive `quantity` to product search and exact-product tool arguments. Absence means
one unit. The server selects the Shopware-calculated tier applicable to that quantity and renders the
per-unit price. It does not show a complete tier table in the first version.

A quantity-aware card includes:

- Requested quantity.
- Applicable contextual unit price.
- Currency.
- Minimum purchase.
- Purchase step.
- Calculated maximum purchase.
- Optional pack unit.

The UI states the basis when quantity is greater than one, for example `EUR 70 each at 120 units`.
It does not calculate or promise the final cart total.

### 7.3 Quantity validation

Before any assistant cart mutation, quantity must satisfy all applicable constraints:

- Quantity is at least `minPurchase`.
- `(quantity - minPurchase)` is divisible by `purchaseSteps`.
- Quantity does not exceed `calculatedMaxPurchase`.
- Existing merchant `maxItemQuantity` remains an additional guardrail when configured.

Invalid input returns a typed, grounded correction with the permitted minimum, step and maximum. No
cart mutation occurs. Shopware still performs final cart validation and calculation.

### 7.4 Rehydration

History currently stores card IDs only. Quantity-specific cards also need the quantity that selected
their tier. Replace the stored card reference with `{productId, quantity}` while retaining decode
support for legacy string IDs as quantity one.

The history card endpoint accepts those references, groups them by quantity and re-resolves current
facts. Prices are never persisted. A historical card therefore retains its quantity basis while its
price, availability and restrictions remain current.

The card's add button submits its reference quantity rather than always adding one.

## 8. Product catalogue enforcement

The assistant already uses `sales_channel.product.repository` with `ProductAvailableFilter` and the
merchant's assistant blocklist. The first Commercial integration test must determine whether Advanced
Product Catalogues constrain this exact repository path for:

- Text search.
- Exact product lookup.
- Variant lookup.
- Card rehydration.
- Category orientation after no results.

If all paths are already restricted, no additional production filter is added.

If a path bypasses the active Advanced Product Catalogue, the Commercial bridge contributes one
official Commercial catalogue-scope collaborator. Product criteria consumers apply it before
repository execution; category orientation applies the same scope before returning categories. The
collaborator must not reconstruct catalogue semantics from Commercial database tables, and consumers
must not retrieve unauthorized rows and discard them afterward.

Knowing a restricted product ID never grants access. Direct lookup must return the same absence as
search.

## 9. Conversation ownership and browser persistence

### 9.1 Stored scope

Extend `swag_assistant_conversation` with:

- `scope_type`: `guest`, `customer`, or `commercial`.
- Nullable Commercial context ID stored as a Shopware UUID without a foreign key to the optional
  extension.

Existing `sales_channel_id` and `customer_id` remain part of the scope. Existing rows are backfilled
as `customer` when `customer_id` is present and `guest` otherwise. Pre-migration rows whose customer
was already deleted are indistinguishable from genuine guest rows; preserving existing guest
rehydration accepts that bounded legacy ambiguity. Every conversation created after the migration
retains its original scope type when a customer is deleted.

All shopper-facing conversation-store operations accept the resolved `ShoppingContext`. A supplied
token is valid only when:

- Scope type matches.
- Sales-channel ID matches.
- Customer ID matches for authenticated scopes.
- Commercial context ID matches for Commercial scope.

An invalid or unknown token returns no history and reveals no reason. A chat request starts a new
conversation in the current scope rather than appending to an unknown row. Append repeats the scope
check so future callers cannot bypass controller validation.

Administrative trace access remains ACL-controlled and does not use shopper scope.

### 9.2 Deleted customers

`customer_id` retains its `ON DELETE SET NULL` behavior. `scope_type` remains `customer` or
`commercial`, so the row cannot become a guest conversation after deletion. It remains available only
to authorized merchant trace tooling until the existing retention process deletes it.

### 9.3 Browser token map

Replace the single `swagAssistantToken` session-storage entry with a JSON map keyed by the opaque
shopping-context key. The key is an HMAC of the canonical server-side scope using the Shopware kernel
secret. It exposes no raw customer or organisation ID and is not an authorization credential.

The conversation token remains the bearer secret, and the server always validates it against the
actual current context rather than trusting the browser key.

Behavior:

- Logout selects the guest map entry and leaves authenticated entries untouched.
- Login selects the entry for the authenticated context.
- Organisation switching selects the entry for that organisation.
- Switching back restores the previous entry.
- Reset removes only the current context's entry.
- A legacy single token is migrated once into the current map entry; server validation makes an
  incorrectly scoped legacy token harmless.
- Closing the browser session removes the map through normal `sessionStorage` behavior.

Guest-to-login transcript merging and cross-device lookup are deliberately absent.

## 10. Account information and storefront UX

When Commercial context is resolved, the widget header always shows a compact server-rendered label:

> Shopping as Coca-Cola · Berlin Procurement

The label is secondary to the assistant name, text-based, and available to assistive technology. It
updates on the page load caused by Shopware's organisation-context switch. No new context-switching UI
is added; Shopware owns that interaction.

The safe account summary available to the agent contains only:

- Authenticated state.
- Active company/organisation-unit display label.
- Currency.
- Commercial-context availability.

Customer and organisation IDs, email, addresses, agreement names, discount formulas, budgets and
internal customer notes are excluded from model context.

A narrow read-only `get_shopping_context` tool returns that summary when the shopper explicitly asks
which account or organisation they are shopping as. The header remains authoritative. Product-specific
purchase constraints are returned with product cards, not by the account tool.

When Commercial context is unavailable, the header shows a translated non-sensitive notice and
product questions receive an honest response that the account's shopping context cannot currently be
verified. General shop-information questions continue to work.

## 11. Request data flow

### Normal product turn

1. Shopware injects `SalesChannelContext` into the storefront route.
2. `ShoppingContextResolver` resolves the scope once.
3. The controller validates the supplied conversation token against that scope or starts a scoped
   conversation.
4. Agent construction receives the commerce-capability decision.
5. Product tools query through the existing request-scoped DAL gateway.
6. Shopware and optional Commercial extensions calculate visibility, prices and quantities.
7. The server maps allowlisted fields into `ProductCard`.
8. The model receives only the existing safe product summary; prices remain server-rendered.
9. The conversation stores product-and-quantity references, not price facts.
10. The response renders current cards plus the safe context label.

### Cart turn

1. The same context and conversation checks run.
2. The exact product and requested quantity are re-resolved in the current context.
3. Quantity constraints and existing assistant guardrails are checked.
4. The plugin sends only product ID and quantity to Shopware's cart service.
5. Shopware recalculates price, discount, availability and any Commercial checkout behavior.
6. The returned cart summary is authoritative.

### Invalid Commercial context

1. Resolver returns `commercial_unavailable`.
2. Product pre-grounding is skipped.
3. Product, product-detail and cart tools are omitted.
4. Stored cards are not rehydrated.
5. Shop-information and safe account-context capabilities remain available.

## 12. Error handling

| Condition | Behavior |
|---|---|
| Commercial not installed | Ordinary core behavior; no warning and no missing capability. |
| Commercial installed but inactive for customer | Ordinary authenticated-customer behavior. |
| Active Commercial context resolved | Full contextual commerce behavior. |
| Expected Commercial context missing or inconsistent | Product/cart capabilities fail closed; general information remains available. |
| Conversation token belongs to another scope | Treat as unknown, expose no history, start fresh on chat. |
| Restricted product requested by ID | Return no product; never disclose that it exists in another catalogue. |
| Quantity below minimum or off-step | Return permitted constraints; do not mutate cart. |
| Quantity above calculated maximum | Return current maximum; do not mutate cart. |
| Price tier unavailable for requested quantity | Do not invent or extrapolate; show no quantity-specific price and direct the shopper to a valid quantity. |
| Shopware cart rejects the item | Preserve Shopware's refusal, return a generic shopper-safe error, record structured trace context. |

Commercial mode and failure reason codes may be traced, but raw account IDs and pricing agreements
must not be added to trace payloads or logs.

## 13. Compatibility and performance

- Keep `shopware/core` and `shopware/storefront` compatibility at `~6.7.0`.
- Enable the documented Individual Pricing behavior only where Shopware Commercial provides it
  (Shopware 6.7.8 or newer).
- Do not add Shopware Commercial as a required Composer dependency.
- A shop without Commercial performs no Commercial service calls and no additional product query.
- Resolve shopping context once per HTTP request.
- Do not call the shop's Store API over HTTP from inside the plugin.
- Keep catalogue restrictions inside DAL criteria to preserve pagination and ranking correctness.
- Group quantity-aware card rehydration by quantity to avoid one query per card.
- Existing public DTO allowlists remain; no raw Shopware or Commercial entity crosses the gateway.

## 14. Verification strategy

### 14.1 Deterministic core tests

- Guest, customer, commercial and unavailable context resolution.
- Commercial absence does not require Commercial classes or services.
- Opaque context keys are stable per scope and differ across customers and organisations.
- Conversation tokens work only in their stored scope.
- A deleted authenticated conversation is not treated as guest.
- Reset removes only the current browser map entry.
- Legacy token migration remains scope-validated.
- Quantity tier selection uses Shopware-calculated tiers.
- Minimum, step and maximum quantity validation.
- Legacy string card references decode as quantity one.
- Quantity-aware card references rehydrate with current facts.
- `commercial_unavailable` removes product and cart tools but retains shop information.

### 14.2 Shopware core integration tests

Use guest and two customer groups against the same concrete variant:

- Each context receives its configured calculated price.
- Search, exact lookup, card and cart agree.
- Sales-channel visibility is respected.
- Core behavior passes with no Commercial package installed.

### 14.3 Shopware Commercial integration matrix

Run on a Commercial-enabled Shopware 6.7.8 or newer environment:

| Scenario | Required result |
|---|---|
| Guest searches shared product | Public contextual price. |
| Company A searches shared product | Company A Individual Price. |
| Company B searches shared product | Company B Individual Price. |
| Organisation A requests tier quantity | Applicable organisation-specific volume price. |
| Organisation A searches an allowed product | Product can be searched, retrieved and added. |
| Organisation B searches the same restricted product | Product is absent. |
| Organisation B requests restricted product ID | Product remains absent. |
| Employee switches organisation | Catalogue, price, label and conversation token change. |
| Employee switches back | Previous browser-session conversation returns. |
| Employee logs out | Authenticated transcript is hidden. |
| Same employee logs in again | Same-scope transcript returns. |
| Another account uses the token | No transcript or commercial data is disclosed. |

The catalogue tests must run first against the unmodified sales-channel repository. Their result
decides whether the optional criteria contribution is necessary; the acceptance outcome does not
change.

### 14.4 Agent evals

Add focused journeys using deterministic trace assertions where possible:

- `b2b_context_price`: only the server-rendered contextual price appears.
- `b2b_volume_price`: a stated quantity renders the applicable tier and preserves it on rehydration.
- `b2b_restricted_product`: no restricted product ID enters retrieved, model or rendered sets.
- `b2b_account_context`: the assistant names only the safe active context.
- `b2b_context_unavailable`: product tools are absent and the assistant does not quote a product.
- `b2b_quantity_invalid`: no cart call occurs for an invalid step or maximum.

Live-model repetition is useful for prose behavior, but price, catalogue, quantity and authorization
guarantees must be deterministic server assertions.

## 15. Acceptance criteria

| ID | Criterion |
|---|---|
| A1 | The plugin installs, compiles and retains current assistant behavior without Shopware Commercial. |
| A2 | A Commercial shopper's active company or organisation unit is resolved through a supported Commercial integration point. |
| A3 | Search, exact lookup, variants, rehydration and category orientation expose no product outside the active catalogue. |
| A4 | Product cards show Shopware's calculated price for guest, ordinary customer and active Commercial context. |
| A5 | A stated quantity shows the applicable Shopware-calculated tier without assistant-owned discount calculation. |
| A6 | Minimum, purchase-step and calculated-maximum constraints prevent invalid cart mutations. |
| A7 | Shopware recalculates every cart addition from product ID and quantity. |
| A8 | The widget always identifies a resolved active Commercial context and exposes no unnecessary account data. |
| A9 | Conversation history is inaccessible across customer, sales-channel or organisation scope. |
| A10 | Logout/login and organisation switching restore the matching conversation within the same browser session. |
| A11 | An unresolved expected Commercial context disables only product and cart capabilities. |
| A12 | Core and Commercial integration matrices pass, including direct-ID authorization checks. |

## 16. Delivery boundary

This is one cohesive implementation project with one external prerequisite: a Commercial-enabled
development shop. Work can begin on the core context contract, conversation scoping, quantity fields
and core regressions without that environment. The Commercial bridge and the claim that Advanced
Product Catalogues are enforced cannot be completed or accepted without it.

If the installed Commercial package exposes no supported way to obtain the active organisation or
apply its product scope to the existing repository, implementation stops at that boundary and the
design is revised. It must not compensate by querying private Commercial tables or duplicating its
pricing and catalogue rules.
