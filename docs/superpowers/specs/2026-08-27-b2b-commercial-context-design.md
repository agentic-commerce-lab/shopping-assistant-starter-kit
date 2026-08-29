# Design: B2B Commercial Shopping Context

- **Date:** 2026-08-27, revised 2026-08-28
- **Author:** Robin Schulte with Codex; revised by Robin Schulte with Claude after a fact check
  against Shopware core 6.7.13 in `vendor/`, this repository, and the B2B Components documentation
- **Status:** proposed for implementation
- **Target:** Shopware 6.7 plugin with optional Shopware Commercial B2B Components support

## 1. Context

The shopping assistant already runs inside a Shopware storefront request and uses the active
`SalesChannelContext`. Product search uses the sales-channel product repository, and Shopware
recalculates the cart. Both are right and stay.

The pricing half of that inheritance is **not** right, and the first revision of this design
repeated the error. `DalProductCardMapper` reads `SalesChannelProductEntity::getCalculatedPrice()`,
which is the product's own price at quantity one. Advanced prices — Rule Builder prices, customer
group prices expressed as rules, and every volume tier — live in `calculatedPrices`, a separate
collection built by `ProductPriceCalculator::calculateAdvancePrices()`. Shopware's own storefront
prefers the latter whenever it is non-empty: the listing card overrides `calculatedPrice` with
`calculatedPrices.last`, and the buy widget uses `calculatedPrices.first` for a single tier and
renders a tier table for more. The assistant therefore already shows a price the shopper's own
product page contradicts, for any rule-priced product, with or without Commercial. Shopware
Individual Pricing is exactly that case, so B2B does not introduce this defect — it makes it
unavoidable.

The second missing guarantee: a logged-in B2B shopper must receive recommendations that are valid
for the currently selected company or organisation unit. Shopware Commercial B2B Components can
assign individual prices and category-level product catalogues to organisation units. An employee
can switch that unit without changing login identity, so sales-channel ID and customer ID alone do
not identify the commercial scope.

Nor do they identify the shopper. A B2B employee is a **separate login under one business-partner
customer**: the business partner extends the storefront customer and pools employees, roles and
settings, while `SalesChannelContext::getCustomer()` returns that company customer with the employee
hanging off it as an extension. Every employee of one company therefore presents the same customer
ID, and every employee of one organisation unit the same pair.

The current conversation token is also accepted as a bearer credential. History lookup does not
check the current customer or commercial context, and the browser stores only one token. Combined
with the shared customer ID above, scoping conversations by customer and organisation alone would
still let colleagues read each other's transcripts.

This design adds the smallest context boundary that closes those gaps. Shopware remains responsible
for identity, pricing, catalogues, availability, quantity rules, and cart calculation. The assistant
resolves the active scope, prevents cross-scope conversation access, and consumes Shopware's results.

## 2. Goal

Fulfil this journey:

> As a B2B shopper, I want the assistant to respect my customer account, catalog permissions,
> pricing context and available products so that recommendations are commercially valid.

For this design, "commercially valid" means:

1. Search and direct lookup expose only products allowed in the active Shopware context.
2. Cards show the price Shopware's own storefront would show for the active customer, company or
   organisation unit — which means the applicable entry of `calculatedPrices` when the product has
   advanced prices, and `calculatedPrice` only when it does not.
3. A stated quantity uses the applicable Shopware-calculated volume tier.
4. Minimum, purchase-step and maximum-purchase constraints are enforced before cart mutation, and
   the cart the shopper is told about is the cart Shopware actually stored.
5. Shopware recalculates and validates the final cart.
6. Conversation history never crosses employee, customer, sales-channel, company or
   organisation-unit scope.
7. Logging out and back into the same context in the same browser session restores that context's
   conversation.
8. No commercial context reaches an HTTP-cacheable response body.

## 3. Scope

### In scope

- Ordinary guest and logged-in Shopware contexts.
- Correcting card prices to the storefront's own `calculatedPrices` precedence. This is a core
  defect, not a Commercial one, and it lands first — see section 16.
- Reporting the cart Shopware actually stored rather than the quantity that was requested. Also a
  core defect that lands first.
- Optional Shopware Commercial B2B Components integration.
- Active employee, company and organisation-unit resolution.
- Shopware Individual Pricing results, including an applicable volume tier for a requested quantity.
- Shopware Advanced Product Catalogue enforcement — category-level visibility per organisation unit
  — when B2B Components are active.
- Product minimum purchase, purchase step, maximum purchase and pack unit.
- Context-bound conversation tokens and per-context browser-session persistence.
- A compact active commercial-context label, decided by the server and delivered outside the
  HTTP-cached document.
- A minimal, grounded answer to questions about the active shopping context.
- Safe degradation when Commercial is absent, and safe refusal when Commercial itself reports an
  organisation context that is required and unresolvable.

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
| B2 | Keep Shopware as the price authority, and read the same field the storefront reads | Reimplementing Individual Pricing would duplicate rule priority, dates, tax, currency, volume and organisation logic. But "Shopware calculated it" is not sufficient: `calculatedPrice` and `calculatedPrices` disagree, and only the second carries advanced and volume prices. |
| B3 | Make Commercial support optional | A shop without Commercial must retain the current plugin behavior and compilation path. |
| B4 | Scope conversations by employee, customer, sales channel and active commercial context | One employee can switch organisation units without changing customer identity — and one customer identity covers every employee of the company, so it cannot stand alone. |
| B5 | Keep guest history separate | Automatically attaching a guest transcript to the next login is unsafe on shared devices. |
| B6 | Store one browser token per opaque context key | This preserves logout/login and organisation switching without adding cross-device account memory. |
| B7 | Fail closed for commerce only on a positive Commercial signal that the context is required and unresolvable | Product and cart actions can become commercially wrong; general shop-information answers remain safe. The **absence** of an organisation is not that signal — Employee Management shipped in 6.5.6.0 and Organisation Units are a separate component, so an employee with no organisation is a valid, common configuration. Failing closed on absence would disable the assistant's entire commerce surface for those shops. |
| B8 | Verify repository filtering before adding a catalogue adapter | If Commercial already decorates the sales-channel repository, another filter would duplicate behavior and risk drift. |
| B9 | Apply an additional catalogue restriction before retrieval, never after | Unauthorized products must not enter model context, traces or rendered cards. |
| B10 | Show the active Commercial context in the widget, rendered outside the HTTP-cached document | A shopper must know which company or organisation unit owns the displayed prices and catalogue — but the storefront cache hash carries no customer or organisation, so the label cannot live in cacheable HTML. |
| B11 | Resolve the applicable tier only when quantity is stated | This supports common B2B questions without turning cards into complete price-list tables. |
| B12 | Report the cart Shopware stored, not the quantity that was asked for | `ProductCartProcessor` silently raises quantity to `minPurchase`, rounds it to `purchaseSteps` and caps it at stock, recording the correction as a cart error rather than refusing the line. Pre-validation reduces those corrections; only reading the result eliminates the lie. |
| B13 | Treat the Advanced Product Catalogue as category-level and undocumented for developers | Shopware documents it for merchants only. There is no developer extension point to rely on, which is what makes B8's verification the gate rather than a formality. |

## 5. Architecture

### 5.1 Shopping context

**Done 2026-08-29 (core paths; Commercial columns present but never populated).**

Add a Shopware-independent immutable `ShoppingContext` at the smallest shared core boundary. It
contains:

- Mode: `guest`, `customer`, `commercial`, or `commercial_unavailable`.
- Sales-channel ID.
- Customer ID for authenticated modes, server-side only. Under B2B this is the **business partner**,
  i.e. the company, not the person at the keyboard.
- Employee ID when a Commercial employee is logged in, server-side only. This is the only value that
  distinguishes two colleagues in one organisation unit, so it is part of every scope comparison and
  of the browser storage key.
- Commercial context ID for the active organisation unit, server-side only.
- Optional safe display label.
- Currency code.
- Whether product and cart capabilities are allowed.
- An opaque browser storage key.

The value object carries resolved facts. It does not query Shopware, calculate prices, or filter
products.

### 5.2 Context resolution

**Done 2026-08-29 (core paths; Commercial columns present but never populated).**

A `ShoppingContextResolver` resolves the value once per storefront request.

The core resolver reads the existing request-scoped `SalesChannelContext`:

- No customer produces `guest`.
- A customer without an active Commercial company or organisation context produces `customer`.
- Absence of Shopware Commercial is an ordinary supported state, not an error.

An optional Commercial bridge enriches the result. Shopware documents exactly one supported way to
read the scope, and the bridge uses it and nothing else:

```php
$employee = $context->getCustomer()?->getExtension(
    SalesChannelContextFactoryDecorator::CUSTOMER_EMPLOYEE_EXTENSION,
);

if (!$employee instanceof EmployeeEntity) {
    return; // ordinary customer
}

$employeeId    = $employee->getId();
$organisationId = $employee->get('organizationId'); // may legitimately be null
```

From that:

- Employee present **and** organisation present produces `commercial`.
- Employee present and organisation absent produces `commercial` with a null commercial context ID:
  Employee Management without Organisation Units is a supported shop, its prices are the business
  partner's, and the ordinary sales-channel context already resolves them correctly. The employee ID
  still enters the scope, because conversation separation is needed either way.
- No employee extension produces `customer`, whether or not Commercial is installed.
- `commercial_unavailable` is produced **only** when the installed Commercial package positively
  reports that an organisation context is required for this shopper and cannot be resolved. If no
  such signal exists in the installed package, this mode is never produced, the resolver records a
  trace reason code instead, and section 6's fail-closed row is dead code. Confirming whether that
  signal exists is part of the first Commercial integration session; **it is not permitted to
  synthesise the signal from the absence of an organisation.**

The bridge is registered only when the required Shopware Commercial marker and context service are
available. The concrete Commercial symbols must be selected from the installed supported package;
the plugin must not use reflection, internal database tables, request-attribute guessing, or a
service locator to infer the active organisation.

`SalesChannelContextFactoryDecorator` is a Commercial class referenced here only for its public
constant, as the official guide does. If the installed package marks it `@internal`, the first
integration session records that and the bridge takes the constant's literal value from that
version, pinned by a test that fails when the constant moves.

Whether the Advanced Product Catalogue is scoped per organisation unit or additionally per employee
role is contradicted between Shopware's merchant documentation (per unit) and its marketing copy
(per role). The first Commercial session resolves it. If role also scopes the catalogue, the role ID
joins the commercial context ID in every scope comparison, because two colleagues would then see two
catalogues.

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
| Commercial, employee only | Enabled | Enabled when merchant configuration permits | Enabled | None |
| Commercial, organisation resolved | Enabled | Enabled when merchant configuration permits | Enabled | Always shown |
| Commercial unavailable | Removed | Removed | Enabled | Non-sensitive unavailable notice |

`commercial_unavailable` is an edge case, not the no-Commercial fallback and not the no-organisation
fallback. Per B7 it applies only when the Commercial integration positively states that an
organisation context is required for the current shopper and cannot identify it, and it does not
exist at all until such a signal is confirmed in the installed package.

Grounded tools are removed from the toolbox in that state. Prompt wording alone is not an authority
control. Page-product pre-grounding and stored-card rehydration follow the same restriction.

## 7. Pricing and purchasing quantities

### 7.1 Pricing authority

The plugin must read Shopware-calculated values, and must read them with the **same precedence
Shopware's own storefront uses**. Reading a Shopware-calculated field is not the same as reading the
right one.

The rule, derived from `component/product/card/price-unit.html.twig` and
`component/buy-widget/buy-widget-price.html.twig`:

| `calculatedPrices` | Applicable unit price | Storefront does |
|---|---|---|
| Empty | `calculatedPrice` | the same |
| One entry | That entry | the same |
| Several, quantity stated | The entry whose range contains that quantity (section 7.2) | n/a — the storefront has no stated quantity at render time |
| Several, no stated quantity | The entry that applies at `minPurchase` | **differs**: the storefront shows `calculatedPrices.last` labelled "from" |

The last row is a deliberate divergence and the only one. `calculatedPrices.last` is the
highest-volume tier, so a listing card saying *from EUR 70.00* is a price the shopper cannot obtain
by buying one. A grid of cards makes that convention legible; a sentence does not, and the assistant
speaks in sentences beside its cards. The card therefore quotes the price of the smallest order the
shopper may actually place, states the quantity that price assumes whenever `minPurchase` exceeds
one, and marks itself as a starting price when further tiers exist. Rendering `.last` instead would
satisfy storefront parity and fail the plugin's own rule that no figure may be stated without the
basis that makes it true.

Currency and tax display continue to come from the active sales-channel context, and the cart result
remains the authority for the final charged amount.

Two mechanics make this cheap and one makes it error-prone:

- `SalesChannelProductDefinition::processCriteria()` adds the `prices` association at root nesting
  level automatically, so `calculatedPrices` is already populated on every card the plugin loads. No
  criteria change is needed.
- `ProductSubscriber::salesChannelLoaded()` assigns `calculatedMaxPurchase` on every sales-channel
  product load, so the maximum in section 7.2 is likewise free.
- `calculatedPrice` is calculated at quantity one from the product's own `price` field. It is
  therefore wrong twice over for a B2B product: it ignores advanced prices, and it ignores a
  `minPurchase` above one.

The assistant must never receive a discount percentage and apply it itself. It must never submit a
client-supplied unit price to the cart.

This supports Shopware Individual Pricing outcomes such as percentage, fixed-amount, fixed-final and
volume discounts without coupling the plugin to their rule representation. Whether Individual
Pricing writes its result into `calculatedPrice`, into `calculatedPrices`, or into both is decided
by the installed package and must be observed rather than assumed; following the storefront's
precedence is correct under all three.

### 7.2 Requested quantity

Add an optional positive `quantity` to product search and exact-product tool arguments. Absence means
`minPurchase`, not one: a product sold in cases of 24 has no meaningful price at one unit. The server
selects the Shopware-calculated tier applicable to that quantity and renders the per-unit price. It
does not show a complete tier table in the first version.

**Selecting the tier requires reconstructing ranges, and the obvious read is wrong.** There is no
`getQuantityPrice()` on `PriceCollection`. `ProductPriceCalculator::calculateAdvancePrices()` sorts
the source rows by `quantityStart` and stores each resulting `CalculatedPrice` with
`quantity = quantityEnd ?? quantityStart`, so the final open-ended tier carries its *start* while
every earlier tier carries its *end*. "The first entry whose quantity is at least N" therefore
selects nothing for any N above the last bounded tier. The applicable entry is the last one whose
reconstructed range start is less than or equal to the requested quantity, where the first range
starts at one and each subsequent range starts one above the previous entry's stored quantity. This
reconstruction is a named, unit-tested function with the open-ended tail as its first test case, not
an inline expression at a call site.

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
cart mutation occurs.

**Pre-validation is a courtesy, not the guarantee, because Shopware does not refuse a bad quantity —
it silently changes it.** `ProductCartProcessor` raises the line to `minPurchase`, rounds it onto the
purchase step, caps it at available stock, and records each correction as a cart error
(`MinOrderQuantityError`, `PurchaseStepsError`, `ProductStockReachedError`) rather than rejecting the
add. Today the plugin cannot see any of that: `DalCartSummariser` never reads `Cart::getErrors()`,
and `AddToCartTool` reports `Added %d to the cart` using the *requested* quantity. A shopper asking
for 10 of a 4-step product is currently told 10 and charged for 8.

So, after every cart mutation:

- The reported quantity is the quantity on the line Shopware stored, read back from the returned
  cart, never the argument that was passed in.
- Quantity-affecting cart errors are carried into `CartSummary` and stated plainly in the tool
  result, so the model cannot claim the requested figure.
- The trace records requested and stored quantity separately, so a divergence is visible to the
  merchant.

This is a defect in today's non-B2B behavior. It is corrected first (section 16) and B2B inherits the
fix rather than introducing it.

### 7.4 Rehydration

History currently stores card IDs only. Quantity-specific cards also need the quantity that selected
their tier. Replace the stored card reference with `{productId, quantity}` while retaining decode
support for legacy string IDs as quantity one.

The history card endpoint accepts those references, groups them by quantity and re-resolves current
facts. Prices are never persisted. A historical card therefore retains its quantity basis while its
price, availability and restrictions remain current.

The card's add button submits its reference quantity rather than always adding one.

**The request cap must be re-derived, not inherited.** `CardIdList::MAX_IDS` is 12, it de-duplicates
by product ID, and its docblock records that the client's batch size must stay equal to it — a
mismatch already cost a shopper three cards once. A `{productId, quantity}` reference de-duplicates
by *pair*, so one product quoted at two quantities now occupies two slots and a transcript that fit
before can overflow. The cap therefore counts references rather than products, the client batches on
the same number, and the existing end-to-end test that re-hydrates a 15-card transcript whole is
extended with a case where the same product appears at two quantities.

## 8. Product catalogue enforcement

The Advanced Product Catalogue restricts **categories** per organisation unit; products outside the
released categories are hidden, and Shopware's storefront answers a direct link to one with a 404.
Shopware documents the feature for merchants and not for developers, so there is no published
extension point and no published guarantee about which layer enforces it.

The assistant already uses `sales_channel.product.repository` with `ProductAvailableFilter` and the
merchant's assistant blocklist. The first Commercial integration test must determine whether Advanced
Product Catalogues constrain this exact repository path for:

- Text search.
- Exact product lookup.
- Variant lookup.
- Card rehydration.
- Category orientation after no results.

There is one mechanism that would make all five true at once, and it is the first thing to check.
`SalesChannelRepository::_search()`, `_searchIds()` and `_aggregate()` all call `processCriteria()`,
which dispatches `sales_channel.product.process.criteria` before anything is read. A subscriber there
constrains every sales-channel product query in the installation, the plugin's included. Two facts
make that cover even the hostile case: the plugin's direct lookup in `DalCommerceGateway::product()`
uses an `EqualsFilter` on `id` **plus** a limit rather than `new Criteria([$id])`, so it goes through
the searcher and not the by-ID reader, and criteria filters therefore apply to it exactly as they do
to search.

The first command of the first Commercial session is therefore:

```console
bin/console debug:event-dispatcher sales_channel.product.process.criteria
```

followed by the same for `sales_channel.category.process.criteria`. A Commercial listener on either
is strong evidence, and the integration matrix in section 14.3 is what turns it into proof.

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

**Done 2026-08-29 (core paths; Commercial columns present but never populated).**

Extend `swag_assistant_conversation` with:

- `scope_type`: `guest`, `customer`, or `commercial`.
- Nullable Commercial **employee** ID stored as a Shopware UUID without a foreign key to the optional
  extension.
- Nullable Commercial **organisation** context ID, stored the same way.

The employee column is the one that carries the privacy guarantee. `customer_id` under B2B is the
business partner, so every employee of one company writes the same value; without the employee ID a
colleague's token would pass every check in the list below. It is stored without a foreign key for
the same reason as the organisation ID — the target table belongs to an optional extension — and it
is never exposed to the storefront.

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
- Employee ID matches for Commercial scope, including when both are null.
- Commercial organisation context ID matches for Commercial scope, including when both are null.

An invalid or unknown token returns no history and reveals no reason. A chat request starts a new
conversation in the current scope rather than appending to an unknown row. Append repeats the scope
check so future callers cannot bypass controller validation.

Administrative trace access remains ACL-controlled and does not use shopper scope.

### 9.2 Deleted customers

**Done 2026-08-29 (core paths; Commercial columns present but never populated).**

`customer_id` retains its `ON DELETE SET NULL` behavior. `scope_type` remains `customer` or
`commercial`, so the row cannot become a guest conversation after deletion. It remains available only
to authorized merchant trace tooling until the existing retention process deletes it.

### 9.3 Browser token map

**Done 2026-08-29 (core paths; Commercial columns present but never populated).**

Replace the single `swagAssistantToken` session-storage entry with a JSON map keyed by the opaque
shopping-context key. The key is an HMAC over the canonical server-side scope — scope type,
sales-channel ID, customer ID, employee ID, organisation context ID — using `%kernel.secret%`. It
exposes no raw customer, employee or organisation ID and is not an authorization credential.

The employee ID belongs in the key as well as in the row. Without it two colleagues sharing a browser
profile would select each other's map entry, and although server validation would then refuse the
token and start a fresh conversation, the visible result is a shopper losing their transcript for no
reason they can see.

The conversation token remains the bearer secret, and the server always validates it against the
actual current context rather than trusting the browser key.

Behavior:

- Logout selects the guest map entry and leaves authenticated entries untouched.
- Login selects the entry for the authenticated context.
- A different employee logging in on the same browser selects a different entry.
- Organisation switching selects the entry for that organisation.
- Switching back restores the previous entry.
- Reset removes only the current context's entry.
- A legacy single token is migrated once into the current map entry; server validation makes an
  incorrectly scoped legacy token harmless.
- Closing the browser session removes the map through normal `sessionStorage` behavior.

Guest-to-login transcript merging and cross-device lookup are deliberately absent.

## 10. Account information and storefront UX

When Commercial context is resolved, the widget header always shows a compact label:

> Shopping as Coca-Cola · Berlin Procurement

The label is secondary to the assistant name, text-based, and available to assistive technology. No
new context-switching UI is added; Shopware owns that interaction.

**The label must not be rendered into the page document.** The widget is included from
`base.html.twig`, so it appears on category and home pages, which carry
`PlatformRequest::ATTRIBUTE_HTTP_CACHE => true` and are stored by the reverse proxy for logged-in
shoppers too. The cache key is `sw-cache-hash`, and `CacheHeadersService::buildCacheHash()` builds it
from rule IDs, version ID, currency ID, tax state and a **boolean** logged-in state — no customer, no
business partner, no organisation. Two employees of two different companies on one sales channel with
the same rules therefore share a cache entry, and a label baked into that HTML is served to whichever
of them requests it second. Individual Pricing is not rule-based either, so nothing about the
company's pricing widens that key on its own.

Two consequences:

- The label is delivered by the widget's own uncached JSON endpoint — the same request that already
  supplies the storage key — and rendered client-side. "Server-rendered" in the sense that matters is
  preserved: the server decides the text and the model never sees the raw IDs.
- The first Commercial session checks whether Commercial contributes an organisation part through
  `HttpCacheCookieEvent`. If it does, that is a useful safety net and is recorded; the design does not
  depend on it, because a merchant's reverse-proxy configuration is not ours to assume.

The label updates on the page load caused by Shopware's organisation-context switch, because the
endpoint that supplies it is uncached and resolves the context per request.

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
7. The server selects the applicable price by the section 7.1 precedence and maps allowlisted fields
   into `ProductCard`.
8. The model receives only the existing safe product summary; prices remain server-rendered.
9. The conversation stores product-and-quantity references, not price facts.
10. The response renders current cards plus the safe context label.

### Cart turn

1. The same context and conversation checks run.
2. The exact product and requested quantity are re-resolved in the current context.
3. Quantity constraints and existing assistant guardrails are checked.
4. The plugin sends only product ID and quantity to Shopware's cart service.
5. Shopware recalculates price, discount, availability and any Commercial checkout behavior, and
   corrects the quantity where it must.
6. The plugin reads the stored line quantity and the cart's errors back out.
7. The returned cart summary is authoritative, including where it contradicts the request.

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
| Employee resolved, no organisation unit | Full commerce behavior, no context label, employee-scoped conversation. Not a failure. |
| Active Commercial context resolved | Full contextual commerce behavior. |
| Commercial states the context is required and unresolvable | Product/cart capabilities fail closed; general information remains available. Only on a positive signal — see B7. |
| Conversation token belongs to another scope | Treat as unknown, expose no history, start fresh on chat. |
| Restricted product requested by ID | Return no product; never disclose that it exists in another catalogue. |
| Quantity below minimum or off-step | Return permitted constraints; do not mutate cart. |
| Quantity above calculated maximum | Return current maximum; do not mutate cart. |
| Price tier unavailable for requested quantity | Do not invent or extrapolate; show no quantity-specific price and direct the shopper to a valid quantity. |
| Shopware corrects the quantity it stored | Report the stored quantity and say it was adjusted; never restate the requested figure. Record both in the trace. |
| Shopware cart rejects the item outright | Preserve Shopware's refusal, return a generic shopper-safe error, record structured trace context. |

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
- Add nothing shopper-specific to an HTTP-cacheable response body, and add no route that returns
  commercial context with cache headers. The plugin's own endpoints carry no `ATTRIBUTE_HTTP_CACHE`
  and must not acquire one.
- Reading `calculatedPrices` costs no extra query: the `prices` association is already added by
  `SalesChannelProductDefinition::processCriteria()` on every root-level sales-channel criteria.
- Group quantity-aware card rehydration by quantity to avoid one query per card.
- Existing public DTO allowlists remain; no raw Shopware or Commercial entity crosses the gateway.

## 14. Verification strategy

### 14.1 Deterministic core tests

- Guest, customer, commercial-with-organisation, commercial-employee-only and unavailable context
  resolution.
- Commercial absence does not require Commercial classes or services.
- Opaque context keys are stable per scope and differ across customers, **employees** and
  organisations.
- Conversation tokens work only in their stored scope, including two employees of one organisation.
- A deleted authenticated conversation is not treated as guest.
- Reset removes only the current browser map entry.
- Legacy token migration remains scope-validated.
- Price precedence: empty `calculatedPrices` falls back to `calculatedPrice`; one entry wins over
  `calculatedPrice`; several entries select by quantity.
- Tier range reconstruction, with the open-ended final tier and a quantity above its start as the
  first case.
- A `minPurchase` above one selects the tier that applies at `minPurchase`, not at one.
- Minimum, step and maximum quantity validation.
- A cart whose stored quantity differs from the requested one is reported with the stored quantity
  and its cart error, and the trace holds both figures.
- Legacy string card references decode as quantity one.
- Quantity-aware card references rehydrate with current facts, including one product at two
  quantities inside one transcript.
- The reference cap counts references, and the client's batch size equals it.
- `commercial_unavailable` removes product and cart tools but retains shop information.
- An employee with no organisation keeps product and cart tools.

### 14.2 Shopware core integration tests

Use guest and two customer groups against the same concrete variant:

- Each context receives its configured calculated price.
- **A product carrying Rule Builder advanced prices shows the same figure the storefront's own
  listing card and buy widget show, for each context.** This test fails against today's `main` and is
  the regression that proves the section 7.1 correction.
- A product with graduated prices and a `minPurchase` above one shows the tier that applies at
  `minPurchase`.
- Adding an off-step quantity reports the quantity Shopware stored, not the one requested.
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
| Second employee of the **same** organisation uses the first one's token | No transcript is disclosed. |
| Second employee of the same organisation logs in on the same browser | Own conversation, not the colleague's. |
| Another account uses the token | No transcript or commercial data is disclosed. |
| Employee of Company A requests a page cached for Company B | No Company B label or price appears anywhere in the response. |
| Employee with Employee Management but no organisation unit | Product and cart tools remain available. |

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
- `b2b_quantity_corrected`: where Shopware does adjust a quantity, the reply states the stored
  figure and never the requested one.

Live-model repetition is useful for prose behavior, but price, catalogue, quantity and authorization
guarantees must be deterministic server assertions.

## 15. Acceptance criteria

| ID | Criterion |
|---|---|
| A1 | The plugin installs, compiles and retains current assistant behavior without Shopware Commercial. |
| A2 | A Commercial shopper's active company or organisation unit is resolved through a supported Commercial integration point. |
| A3 | Search, exact lookup, variants, rehydration and category orientation expose no product outside the active catalogue. |
| A4 | Product cards show the price Shopware's own storefront shows, for guest, ordinary customer and active Commercial context — including products carrying advanced or volume prices. |
| A5 | A stated quantity shows the applicable Shopware-calculated tier without assistant-owned discount calculation. |
| A6 | Minimum, purchase-step and calculated-maximum constraints prevent invalid cart mutations. |
| A7 | Shopware recalculates every cart addition from product ID and quantity, and the shopper is told the quantity Shopware stored. |
| A8 | The widget always identifies a resolved active Commercial context, exposes no unnecessary account data, and places none of it in an HTTP-cacheable response. |
| A9 | Conversation history is inaccessible across employee, customer, sales-channel or organisation scope. |
| A10 | Logout/login, employee change and organisation switching restore the matching conversation within the same browser session. |
| A11 | A positively-reported unresolvable Commercial context disables only product and cart capabilities, and no other state does. |
| A12 | Core and Commercial integration matrices pass, including direct-ID authorization checks. |

## 16. Delivery boundary

This is one cohesive implementation project with one external prerequisite: a Commercial-enabled
development shop. Work can begin on the core context contract, conversation scoping, quantity fields
and core regressions without that environment. The Commercial bridge and the claim that Advanced
Product Catalogues are enforced cannot be completed or accepted without it.

### Sequencing

Two items in this design are corrections to **current, shipped, non-B2B behavior**. They are
shopper-visible today, they need no Commercial installation, and every B2B guarantee is built on top
of them. They land first, as their own change, with their own regression tests:

1. **Done 2026-08-28.** **Price precedence** (section 7.1). `DalProductCardMapper` reads `calculatedPrice`
   unconditionally, so every rule-priced product is quoted at a figure its own product page
   contradicts. The class docblock asserts the opposite and is corrected with the code.
2. **Done 2026-08-28.** **Cart read-back** (section 7.3). `DalCartSummariser` discards `Cart::getErrors()` and
   `AddToCartTool` reports the requested quantity, so a silently corrected line is misreported.

Only then does the B2B work proper begin: context contract and employee-aware conversation scoping,
then quantity-aware cards and rehydration, then the Commercial bridge and the catalogue verification
of section 8.

If the installed Commercial package exposes no supported way to obtain the active organisation or
apply its product scope to the existing repository, implementation stops at that boundary and the
design is revised. It must not compensate by querying private Commercial tables or duplicating its
pricing and catalogue rules. Items 1 and 2 keep their value regardless of that outcome.
