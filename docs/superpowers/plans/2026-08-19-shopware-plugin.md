# Shopware Plugin Implementation Plan (Plan 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the grounded core into a Shopware 6.7 plugin that installs into a real shop,
answers from the real catalogue at variant level, puts the asked-for variant into the real cart,
and persists every turn.

**Architecture:** Plan 1 built everything above `CommerceGatewayInterface` and proved it against
a real LLM using `FixtureCommerceGateway`. This plan adds the Shopware half *below* and *around*
that seam: a plugin skeleton, `DalCommerceGateway` over the DAL and `CartService`, custom entities
for conversation and trace, and a storefront controller plus widget. Nothing above the seam changes
except one deliberate seam repair (Task 2). **No Shopware type may appear in a signature above the
gateway** — that rule is the reason this plan is possible at all.

**Tech Stack:** PHP 8.2+ · Shopware 6.7.13 (`shopware/core`, `shopware/storefront`) ·
Symfony 7.4 · Symfony AI 0.12 · PHPUnit 11 · Mago (fmt/lint/analyze)

**Spec:** `docs/superpowers/specs/2026-08-18-shopping-assistant-design.md`
**Handoff that ordered this work:** `docs/HANDOFF.md`
**Architecture of record:** `ARCHITECTURE.md`
**Binding constraints:** `.superpowers/sdd/2026-08-18-grounded-core/standing-constraints.md`

---

## Global Constraints

Every task's requirements implicitly include this section. Where a task's text conflicts with it,
raise the conflict rather than guessing.

- PHP `^8.2`. Every file starts with `declare(strict_types=1);`. **English only** — strings,
  comments, identifiers, fixture values.
- Autoload: `Swag\AssistantStarterKit\` → `src/`, `Swag\AssistantStarterKit\Tests\` → `tests/`.
- **Version pins are load-bearing and must not move.** Symfony components `~7.4.0`, Symfony AI
  `0.12.*` exactly, `shopware/core` and `shopware/storefront` `~6.7.0` (added by Task 1).
- **`composer run quality` must exit 0** and does **not** run PHPUnit. Run `vendor/bin/phpunit`
  — the **full** suite, `--exclude-group eval` — separately before committing, and record both
  commands and their output in your report.
- **Never use a pragma or any other suppression to silence a finding that is true.** The four
  existing carve-outs are in `standing-constraints.md`; Task 1 adds a fifth (`config.audit.ignore`)
  and it is scoped to exactly one advisory id.
- A complexity or too-many-methods finding means the class does too much — **split it**, and say
  so in your report. Going beyond your brief's file list to split is not scope creep.
- **No Symfony AI and no Shopware API claim is valid unless read from the installed `vendor/`**
  (rulings R6, R48). A truncated read of vendor code is not a read of vendor code. The Shopware
  tree to read is `/Users/R.Schulte/Workspace/shopping-assistant-test/vendor/shopware/`.
- CaptainHook rejects a failing commit. Run `composer run format` first. **Never `--no-verify`.**
- `--group eval` needs a real LLM endpoint and is **never** started as a side effect of a task.
- Flag **every** deviation from the brief explicitly, including a file you decided not to create
  and why. A silent drop is a finding even when the decision was right.
- Product ids are written abbreviated in this plan's test code (`a2a2…a2`, `fafa…fa`) for
  readability. **Replace every one with the full 32-character id from the environment table before
  running a test.** An abbreviated id silently matches nothing.
- It is always OK to stop and report `BLOCKED` or `NEEDS_CONTEXT`. Bad work is worse than no work.

## The environment (already built — do not rebuild it)

| Fact | Value |
|---|---|
| Shop project | `/Users/R.Schulte/Workspace/shopping-assistant-test` |
| Core version | `shopware/core` v6.7.13.0, PHP 8.5 in the container |
| Storefront | `http://127.0.0.1:8000` — verified HTTP 200 |
| Containers | `docker compose` in the shop project; port 8080 remapped to 8081 in `compose.override.yaml` |
| Run a command | `docker compose exec -T web bin/console <cmd>` from the shop project dir |
| Catalogue | 128 products, 13 manufacturers, 810 categories (`framework:demodata`) |
| Sales channel id | `01a01b4af6567284ac9eeb3616598ac3` (Storefront) |
| Tax id (19%) | `01a01b4a12d370d7b648a4c867b04254` |
| Currency id (EUR) | `b7d2554b0ce847cd82f3ac9bd1c0dfca` |

**The purpose-built variant product** — seeded deliberately, mirroring eval fixture `fx-026`.
It is the acceptance fixture for Tasks 3–5:

| productNumber | id | stock | available | own price |
|---|---|---|---|---|
| `TRAIL-JERSEY` (parent) | `fafa…fa` (32×`fa`) | 35 | **1** | 79.90 |
| `TRAIL-JERSEY-BLUE-S` | `a1a1…a1` | 7 | 1 | inherits 79.90 |
| **`TRAIL-JERSEY-BLUE-M`** | `a2a2…a2` | **0** | **0** | **74.90** |
| `TRAIL-JERSEY-BLUE-L` | `a3a3…a3` | 12 | 1 | inherits 79.90 |
| `TRAIL-JERSEY-BLACK-S` | `a4a4…a4` | 4 | 1 | inherits 79.90 |
| `TRAIL-JERSEY-BLACK-M` | `a5a5…a5` | 3 | 1 | **69.90** |
| `TRAIL-JERSEY-BLACK-L` | `a6a6…a6` | 9 | 1 | inherits 79.90 |

Option groups: `Colour` (Blue, Black) and `Size` (S, M, L).

Three traps live in that table, and each one is a criterion this plan is judged by:
1. **The parent claims `available = 1` with stock 35 while Blue/M is sold out.** Reporting the
   parent's number for a variant question is D4's whole reason to exist.
2. **Blue/M carries its own price, 74.90, five euros below the parent.** A price read from the
   parent is wrong in a way the shopper only discovers at checkout.
3. **Four variants have no own price and inherit 79.90.** A mapper that reads a raw `price` column
   returns `null` for those.

## Decisions taken before this plan was written

| # | Decision | Why |
|---|---|---|
| P1 | `shopware/core` + `shopware/storefront` go into **`require`** | Robin's call. It is what a plugin genuinely needs, and it lets `mago analyze` resolve the base classes — without which nothing in this plan is checkable |
| P2 | The plugin is installed into the shop **via a Composer path repository**, never a `custom/plugins` symlink | Verified in `vendor/shopware/core/Framework/Plugin/KernelPluginLoader/KernelPluginLoader.php:191`: for plugins not managed by Composer, Shopware registers **only the plugin's own PSR-4 namespaces** — `symfony/ai-agent` would not be autoloadable and the first `new Agent(...)` would fatal. Measured: `symfony/ai-agent 0.12.*` + `ai-generic-platform 0.12.*` resolve cleanly against the real shop |
| P3 | `composer audit` ignores exactly `CVE-2026-53965` via `config.audit.ignore` | `shopware/core` v6.7.13.0 requires `mcp/sdk ^0.6.0`; the advisory is fixed in 0.7.1, so it is **unfixable from here**. Central, id-scoped, dated — the shape ruling R51 chose. It is not a suppression of a finding about our code |
| P4 | Persistence uses **custom entities**, not `EntityDefinition` + migration | The spec already chose them (Should 5, "generated `admin-ui` over the custom entities"). Shopware's `SchemaUpdater` creates the tables on install, so no migration exists to get wrong. **Divergence from `ARCHITECTURE.md`:** table names must carry the `ce_` prefix, so `ce_swag_assistant_conversation` / `ce_swag_assistant_trace_event`, not the documented `swag_assistant_*`. Task 7 corrects `ARCHITECTURE.md` |
| P5 | The retrieve-limit/return-limit seam split happens **now**, as Task 2 | Robin's call. It is Handoff known-issue 6's real repair, and Task 3 writes the second implementation of that seam. Doing it after would mean changing two gateways plus their tests twice. In a real catalogue a `limit: 10` truncation is the common case, not the fixture's rare one |
| P6 | The admin trace view (Handoff item 5) is **cut**, not deferred | Three working days remain for four Must-haves that all need the shop. Spec Q2 already doubts it belongs. P4 keeps it to ~20 lines of XML if time appears |

## Verified Shopware API — read from the installed 6.7.13 tree

Do not re-derive these; do verify anything **not** on this list before you use it.

| Concern | Verified API |
|---|---|
| Plugin base class | `Shopware\Core\Framework\Plugin` (abstract, extends `Bundle`, `final __construct`) |
| Product search | `Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository::search(Criteria, SalesChannelContext): EntitySearchResult` — plus `aggregate()` and `searchIds()`; service id `sales_channel.product.repository` |
| Product entity | `Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity` — `getCalculatedPrice(): CalculatedPrice`, and from `ProductEntity`: `$stock`, `$availableStock`, `getAvailable(): bool`, `$isCloseout`, `$parentId`, `$deliveryTime` (`DeliveryTimeEntity`), `$optionIds` |
| Price | `Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice::getUnitPrice(): float` (also `getTotalPrice()`) |
| Options / properties | `getOptions()` / `getProperties()` → `PropertyGroupOptionCollection` of `PropertyGroupOptionEntity` with `getName(): ?string` and `getGroup(): ?PropertyGroupEntity` |
| Filters | `Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\{EqualsFilter, EqualsAnyFilter, RangeFilter, ContainsFilter, NotFilter, AndFilter, OrFilter}` |
| Aggregations | `…\Search\Aggregation\Bucket\TermsAggregation`, `…\Aggregation\Metric\StatsAggregation`; results `…\AggregationResult\Bucket\TermsResult`, `…\AggregationResult\Metric\StatsResult` |
| Sorting | `…\Search\Sorting\FieldSorting` |
| Availability filter | `Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter(string $salesChannelId, int $visibility = VISIBILITY_ALL)` — ANDs `visibilities.visibility >= v`, `visibilities.salesChannelId`, `product.active = true` |
| Cart | `Shopware\Core\Checkout\Cart\SalesChannel\CartService` — `getCart(...)`, `add(Cart, LineItem\|array, SalesChannelContext): Cart`, `changeQuantity(...)`, `recalculate(...)` |
| Line items | `Shopware\Core\Checkout\Cart\LineItemFactoryRegistry::create(array $data, SalesChannelContext): LineItem` |
| Product URL | route `frontend.detail.page` (`vendor/shopware/storefront/Controller/ProductController.php:57`), parameter `productId` |
| Sales-channel context | `Shopware\Core\System\SalesChannel\SalesChannelContext` — `getSalesChannelId()`, `getCurrency(): CurrencyEntity`, `getCurrencyId()`, `getToken()`, `getContext()`, `getLanguageId()` |
| Context from request | `Shopware\Core\PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT` = `'sw-sales-channel-context'` |
| Context without a request | `Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory::create(string $token, string $salesChannelId, array $options = []): SalesChannelContext` |
| Merchant config | `Shopware\Core\System\SystemConfig\SystemConfigService` — `get()`, `getString()`, `getInt()`, `getBool()` |
| Custom entities | `src/Resources/config/entities.xml`; field types `int float string text bool json email price date` plus `many-to-one one-to-many many-to-many one-to-one`; table prefix **must** be `ce_` or `custom_entity_` (`System/CustomEntity/Schema/SchemaUpdater.php:21-23`) |
| Storefront controller | `Shopware\Storefront\Controller\StorefrontController` (abstract, `renderStorefront(string $view, array $parameters = []): Response`) |

## File structure

```
composer.json                                   MODIFY  T1  type, require, extra, audit ignore
src/SwagAssistantStarterKit.php                 CREATE  T1  plugin base class
src/Resources/config/services.xml               CREATE  T1  DI wiring, grows per task
src/Resources/config/config.xml                 CREATE  T1  merchant settings form
src/Resources/config/routes.xml                 CREATE  T8  attribute route import
src/Resources/config/entities.xml               CREATE  T7  ce_swag_assistant_* custom entities
src/Resources/views/storefront/base.html.twig   CREATE  T8  mounts the widget
src/Resources/app/storefront/src/               CREATE  T8  widget behaviour + styles
src/Core/Commerce/Dto/ProductQuery.php          MODIFY  T2  candidateLimit + retrievalLimit()
src/Core/Commerce/FixtureCommerceGateway.php    MODIFY  T2  honour retrievalLimit()
src/Core/Commerce/Fixture/FixtureQueryFilter.php MODIFY T2  same
src/Core/Tool/SearchProductsTool.php            MODIFY  T2  truncate AFTER variant resolution
src/Core/Commerce/Dal/DalCommerceGateway.php    CREATE  T3  implements the seam, composes below
src/Core/Commerce/Dal/DalProductCardMapper.php  CREATE  T3  entity → ProductCard (the allowlist)
src/Core/Commerce/Dal/DalCriteriaBuilder.php    CREATE  T3  ProductQuery + CatalogScope → Criteria
src/Core/Commerce/Dal/DalFacetReader.php        CREATE  T3  aggregations → FacetSet
src/Core/Commerce/Dal/SalesChannelContextProvider.php CREATE T3 request-scoped context access
src/Core/Commerce/Dal/DalVariantFinder.php      CREATE  T4  resolveVariant over the DAL
src/Core/Commerce/Dal/DalCartAdapter.php        CREATE  T4  CartService ↔ CartSummary
src/Command/ProbeCommand.php                    CREATE  T5  real-catalogue probe + trace dump
src/Core/Config/SystemConfigAssistantConfig.php CREATE  T6  SystemConfigService → AssistantConfig
src/Core/Config/SystemConfigLlmSettings.php     CREATE  T6  SystemConfigService → LlmSettings
src/Core/Trace/ConversationStore.php            CREATE  T7  persistence port (interface)
src/Core/Trace/DalConversationStore.php         CREATE  T7  custom-entity implementation
src/Controller/AssistantController.php          CREATE  T8  POST /assistant/chat, GET /assistant/history
```

---

### Task 1: Plugin skeleton — installable in a real 6.7 shop

Spec day-plan item "Wed 19: plugin skeleton", stated outcome *"plugin installable"*. This is not
mechanical: it lifts a standing constraint, changes load-bearing dependency declarations, and has
one unfixable advisory to route around (P3).

**Files:**
- Modify: `composer.json`
- Create: `src/SwagAssistantStarterKit.php`
- Create: `src/Resources/config/services.xml`
- Create: `src/Resources/config/config.xml`
- Modify: `.superpowers/sdd/2026-08-18-grounded-core/standing-constraints.md`
- Modify: `composer-dependency-analyser.php`
- Test: `tests/PluginManifestTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: class `Swag\AssistantStarterKit\SwagAssistantStarterKit extends Shopware\Core\Framework\Plugin`.
  Config keys under `SwagAssistantStarterKit.config.*` — Task 6 reads exactly these names:
  `llmBaseUrl`, `llmModel`, `llmApiKey`, `agentVoice`, `excludedCategories`, `blockedProducts`,
  `blockedCategories`, `enableAddToCart`, `maxItemQuantity`, `maxCartValue`, `killSwitch`,
  `dailyRequestCap`, `maxToolCallsPerTurn`.
  Service file `src/Resources/config/services.xml` with `<defaults autowire="true" autoconfigure="true"/>`
  — every later task adds its services there.

- [ ] **Step 1: Write the failing test**

The deliverable is "installable", which PHPUnit cannot assert. What it *can* assert is the
manifest coupling that silently breaks installability: a renamed plugin class, or a
`shopware-plugin-class` that no longer resolves. That is the regression worth a test.

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Plugin;
use Swag\AssistantStarterKit\SwagAssistantStarterKit;

final class PluginManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function composerJson(): array
    {
        $raw = file_get_contents(__DIR__ . '/../composer.json');
        self::assertIsString($raw);

        $decoded = json_decode($raw, associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testThePackageDeclaresItselfAShopwarePlugin(): void
    {
        self::assertSame('shopware-platform-plugin', $this->composerJson()['type'] ?? null);
    }

    public function testTheDeclaredPluginClassIsTheClassThatExists(): void
    {
        $extra = $this->composerJson()['extra'] ?? [];
        self::assertIsArray($extra);

        $declared = $extra['shopware-plugin-class'] ?? null;
        self::assertSame(SwagAssistantStarterKit::class, $declared);
        self::assertTrue(class_exists(SwagAssistantStarterKit::class));
    }

    public function testThePluginClassExtendsShopwaresPluginBaseClass(): void
    {
        self::assertInstanceOf(Plugin::class, new SwagAssistantStarterKit(true, __DIR__ . '/..'));
    }

    public function testEveryConfigKeyTaskSixReadsIsDeclaredInConfigXml(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../src/Resources/config/config.xml');
        self::assertNotFalse($xml);

        $names = [];
        foreach ($xml->xpath('//input-field/name') ?: [] as $node) {
            $names[] = (string) $node;
        }

        $required = [
            'llmBaseUrl', 'llmModel', 'llmApiKey', 'agentVoice', 'excludedCategories',
            'blockedProducts', 'blockedCategories', 'enableAddToCart', 'maxItemQuantity',
            'maxCartValue', 'killSwitch', 'dailyRequestCap', 'maxToolCallsPerTurn',
        ];

        foreach ($required as $key) {
            self::assertContains($key, $names, \sprintf('config.xml is missing "%s".', $key));
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/PluginManifestTest.php`
Expected: FAIL — `Shopware\Core\Framework\Plugin` does not exist and neither does the plugin class.

- [ ] **Step 3: Add the Shopware dependencies and the plugin manifest to `composer.json`**

Add to `require`, keeping the existing entries and `sort-packages` ordering intact:

```json
"shopware/core": "~6.7.0",
"shopware/storefront": "~6.7.0"
```

Add a top-level `extra` block:

```json
"extra": {
    "shopware-plugin-class": "Swag\\AssistantStarterKit\\SwagAssistantStarterKit",
    "label": {
        "en-GB": "Shopping Assistant Starter Kit"
    },
    "description": {
        "en-GB": "Shopper-facing, merchant-operated shopping assistant with server-side grounding."
    }
}
```

Change `"type": "library"` to `"type": "shopware-platform-plugin"`.

Add the advisory ignore to the existing `config` block, per P3 — **exactly this one id, with the
comment, and nothing wider**:

```json
"audit": {
    "ignore": {
        "CVE-2026-53965": "mcp/sdk SSE buffer growth, reached only through symfony/mcp-bundle's MCP client, which this plugin never constructs. shopware/core v6.7.13.0 requires mcp/sdk ^0.6.0 and the fix is 0.7.1, so it is not resolvable from here. Added 2026-08-19; re-check when shopware/core widens that constraint."
    }
}
```

Then run `composer update shopware/core shopware/storefront --no-interaction` and **read the
resolver output**. If it moves any Symfony component off `~7.4.0` or any `symfony/ai-*` off
`0.12.0`, stop and report `BLOCKED` — the pins are load-bearing (rulings R5, R9).

- [ ] **Step 4: Write the plugin base class**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit;

use Shopware\Core\Framework\Plugin;

/**
 * Plugin base class. Deliberately empty: install/update/activate hooks are not needed
 * because the custom entities of Task 7 are created by Shopware's own SchemaUpdater,
 * not by a migration this class would have to trigger.
 */
class SwagAssistantStarterKit extends Plugin {}
```

Note it is **not** `final` — Shopware instantiates and may decorate plugin classes, and the base
class already declares `__construct` as `final`.

- [ ] **Step 5: Write `services.xml` and `config.xml`**

`src/Resources/config/services.xml` — the container every later task extends:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services https://symfony.com/schema/dic/services/services-1.0.xsd">
    <services>
        <defaults autowire="true" autoconfigure="true"/>
    </services>
</container>
```

`src/Resources/config/config.xml` — one `<input-field>` per key named in the Interfaces block, in
three cards (LLM, Voice and scope, Guardrails). Use `type="text"` for `llmBaseUrl`/`llmModel`,
`type="password"` for `llmApiKey`, `type="textarea"` for `agentVoice` and the three list fields,
`type="bool"` for `enableAddToCart`/`killSwitch`, `type="int"` for `maxItemQuantity`/
`dailyRequestCap`/`maxToolCallsPerTurn`, `type="float"` for `maxCartValue`. Defaults must match
`AssistantConfig`'s own defaults exactly (`src/Core/Policy/AssistantConfig.php`): `enableAddToCart`
true, `maxItemQuantity` 5, `maxCartValue` 1000, `killSwitch` false, `dailyRequestCap` 500,
`maxToolCallsPerTurn` 5. Follow the shape of
`/Users/R.Schulte/Workspace/page-agent-shopware/src/Resources/config/config.xml`, which is a
verified 6.7 file, including its `xsi:noNamespaceSchemaLocation`.

Add a note in the `llmApiKey` field's `<helpText>` that Shopware system config has no real secret
storage and an env var is preferred — that is the spec's own wording and it must reach the merchant.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/PluginManifestTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 7: Record the constraint change in `standing-constraints.md`**

The file currently says *"No `shopware/*` package anywhere in this plan."* An implementer who reads
it and follows the escalation rule will correctly stop. Replace that line with:

```markdown
- `shopware/core` and `shopware/storefront` at `~6.7.0` are **required** dependencies as of
  Plan 2 / ruling R56. The Plan 1 rule ("no `shopware/*` package anywhere") is retired, not
  bent: it existed so the grounded core could be built before an environment existed, and that
  job is done. The pins on Symfony (`~7.4.0`) and Symfony AI (`0.12.*`) are unchanged and still
  must not move.
- **Fifth pragma carve-out (2026-08-19, ruling R57):** `config.audit.ignore` in `composer.json`
  suppresses exactly `CVE-2026-53965`. It is transitive through `shopware/core`'s `mcp/sdk ^0.6.0`
  pin, unfixable from here, and about code this plugin never calls. Scoped to one advisory id,
  declared centrally, dated, with the reason in the value. Any request to widen it — a second id,
  or an `--no-dev` audit — should be refused.
```

- [ ] **Step 8: Update `composer-dependency-analyser.php`**

`shopware/core` and `shopware/storefront` are used only for classes we extend and type against.
Nothing in `src/` uses them yet, so the analyser will report them as unused. Add both to
`ignoreErrorsOnPackage(... [ErrorType::UNUSED_DEPENDENCY])` with a one-line comment saying the
entry must be removed when Task 3 lands, mirroring the pattern the file already uses for the
not-yet-consumed Symfony AI packages.

- [ ] **Step 9: Run the gate and the full suite**

```bash
composer run format
composer run quality
vendor/bin/phpunit --exclude-group eval
```

Expected: quality exits 0 — **including `composer audit`, which is the step P3 exists for.** If
audit still fails, read its output: a second advisory would mean the ignore list is incomplete, and
that is a report item, not a second ignore entry. PHPUnit: 192 tests (188 + the 4 new ones).

- [ ] **Step 10: Prove it installs into the real shop**

This is the task's actual acceptance criterion. From the shop project directory:

```bash
cd /Users/R.Schulte/Workspace/shopping-assistant-test
docker compose exec -T web composer config repositories.assistant '{"type":"path","url":"../shopping-assistant-starter-kit","options":{"symlink":true}}'
docker compose exec -T web composer require "swag/assistant-starter-kit:*@dev" --no-interaction
docker compose exec -T web bin/console plugin:refresh
docker compose exec -T web bin/console plugin:install --activate SwagAssistantStarterKit
docker compose exec -T web bin/console plugin:list | grep -i assistant
```

**The path repository must resolve.** The container mounts the shop project at `/var/www/html`;
`../shopping-assistant-starter-kit` is therefore **outside the mount** and will not resolve as
written. Add a second bind mount to `compose.override.yaml` before running the above:

```yaml
services:
    web:
        volumes:
            - ../shopping-assistant-starter-kit:/var/www/html/plugin-src
```

then use `"url": "plugin-src"`. Restart with `docker compose up -d`. Record the exact commands you
ran and their output — a plugin that "should install" is not an installed plugin.

Expected: `plugin:list` shows `SwagAssistantStarterKit` as Active, and
`curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/` still returns 200. A 500 here means
the plugin boots but breaks the container — read `var/log/` in the shop before reporting.

- [ ] **Step 11: Commit**

```bash
git add composer.json composer.lock src/SwagAssistantStarterKit.php src/Resources/config \
        tests/PluginManifestTest.php composer-dependency-analyser.php \
        .superpowers/sdd/2026-08-18-grounded-core/standing-constraints.md
git commit -m "feat: make this package an installable Shopware 6.7 plugin"
```

---

### Task 2: Split "how many to retrieve" from "how many to return"

Handoff known-issue 6's real repair, done now because Task 3 writes the second implementation of
this seam (P5). Today the gateway applies sort **and limit** together at pipeline stage 6, so the
in-stock ranking bias can truncate away the very variant the shopper asked about — and it does so
precisely when that variant is sold out, which is when the answer matters most. `MIN_LIMIT` is a
floor that makes it unlikely, not a fix.

**Files:**
- Modify: `src/Core/Commerce/Dto/ProductQuery.php`
- Modify: `src/Core/Commerce/Fixture/FixtureQueryFilter.php`
- Modify: `src/Core/Tool/SearchProductsTool.php`
- Test: `tests/Core/Commerce/Dto/ProductQueryTest.php` (create)
- Test: `tests/Core/Tool/SearchProductsToolTest.php` (extend)

**Interfaces:**
- Consumes: `ProductQuery(?string $term, list<FilterClause> $filters, int $limit, ?string $sort)`,
  `FixtureQueryFilter::apply(list<ProductCard> $units, ProductQuery $query): list<ProductCard>`.
- Produces:
  `ProductQuery(?string $term = null, list<FilterClause> $filters = [], int $limit = 10, ?string $sort = null, ?int $candidateLimit = null)`
  plus `ProductQuery::retrievalLimit(): int`. **Every gateway applies `retrievalLimit()`, never
  `$limit`.** `$limit` is what the *caller* narrows to after variant resolution. Task 3's
  `DalCriteriaBuilder` depends on exactly this.

- [ ] **Step 1: Write the failing tests**

`tests/Core/Commerce/Dto/ProductQueryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dto;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

final class ProductQueryTest extends TestCase
{
    public function testRetrievalFallsBackToTheReturnLimitWhenNoWindowIsGiven(): void
    {
        self::assertSame(10, (new ProductQuery(limit: 10))->retrievalLimit());
    }

    public function testAWiderCandidateWindowIsWhatRetrievalUses(): void
    {
        $query = new ProductQuery(limit: 3, candidateLimit: 20);

        self::assertSame(20, $query->retrievalLimit());
        self::assertSame(3, $query->limit);
    }

    public function testACandidateWindowNarrowerThanTheReturnLimitCannotShrinkRetrieval(): void
    {
        // Otherwise a caller could reintroduce the very truncation this split removes.
        self::assertSame(10, (new ProductQuery(limit: 10, candidateLimit: 2))->retrievalLimit());
    }
}
```

Add to `tests/Core/Tool/SearchProductsToolTest.php` — this is the test that must fail while
truncation still happens inside retrieval. It uses the existing fixture catalogue, whose measured
ranking for term `Jersey` is `fx-026-blue-l` (stock 12) → `fx-026-black-m` (3) → `fx-026-blue-m` (0):

```php
public function testTheAskedForVariantSurvivesEvenWhenRankingWouldSortItLast(): void
{
    // limit 1 plus an in-stock ranking bias used to answer "the blue jersey in M?"
    // with the blue L. The candidate window must be wide enough for variant
    // resolution to see blue-M, and narrowing to `limit` must happen after it.
    $result = ($this->tool())(
        term: 'Jersey',
        limit: 1,
        options: [['option' => 'Blue'], ['option' => 'M']],
    );

    self::assertSame(['fx-026-blue-m'], $result['productIds']);
}

public function testNarrowingToTheReturnLimitIsRecordedRatherThanSilent(): void
{
    ($this->tool())(term: 'Jersey', limit: 1);

    $payload = $this->trace->payload('retrieve');
    self::assertIsArray($payload);
    self::assertSame(1, $payload['returnLimit']);
    self::assertGreaterThan(1, $payload['candidateLimit']);
    self::assertGreaterThan(0, $payload['truncated']);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Core/Commerce/Dto/ProductQueryTest.php tests/Core/Tool/SearchProductsToolTest.php`
Expected: FAIL — `retrievalLimit()` undefined; `productIds` contains the blue L, not the blue M;
`retrieve` payload has no `returnLimit`.

- [ ] **Step 3: Widen `ProductQuery`**

```php
final readonly class ProductQuery
{
    /** @param list<FilterClause> $filters */
    public function __construct(
        public ?string $term = null,
        public array $filters = [],
        public int $limit = 10,
        public ?string $sort = null,
        public ?int $candidateLimit = null,
    ) {}

    /**
     * How many units to RETRIEVE, as opposed to how many to return.
     *
     * A gateway applies sort and limit together, so whatever it truncates is gone
     * before variant resolution can disambiguate it — and the in-stock ranking bias
     * sorts a sold-out unit last, which is exactly the unit a variant question is
     * usually about. Retrieval therefore reads this, and the caller narrows to
     * {@see self::$limit} only after resolution. A candidate window narrower than
     * the return limit is meaningless and is ignored rather than honoured.
     */
    public function retrievalLimit(): int
    {
        return max($this->limit, $this->candidateLimit ?? $this->limit);
    }
}
```

- [ ] **Step 4: Make the fixture gateway honour it**

In `FixtureQueryFilter::apply()`, replace the `$query->limit` used for slicing with
`$query->retrievalLimit()`. Do not touch the sorting — the sort itself is correct; it is only its
combination with a narrow limit that was wrong. Add a one-line comment saying why the method reads
`retrievalLimit()` and not `limit`, so the next reader does not "simplify" it back.

- [ ] **Step 5: Move truncation after variant resolution in `SearchProductsTool`**

Replace the `MIN_LIMIT` coercion (`src/Core/Tool/SearchProductsTool.php:112`) and its comment block.
Delete `MIN_LIMIT`; add:

```php
/**
 * How much wider than the return limit the retrieval window is, and its floor.
 *
 * Retrieval, ranking and truncation all used to happen together in the gateway, so a
 * narrow `limit` decided the answer before variant resolution ran. Now the gateway
 * retrieves this wider window, resolution runs over all of it, and the result is
 * narrowed to the model's own `limit` afterwards — the ordering ARCHITECTURE.md's
 * lifecycle table always claimed.
 */
private const CANDIDATE_MULTIPLIER = 4;
private const MIN_CANDIDATES = 20;
private const MAX_CANDIDATES = 50;
```

Build the query with both numbers:

```php
$candidateLimit = min(
    self::MAX_CANDIDATES,
    max($requestedLimit * self::CANDIDATE_MULTIPLIER, self::MIN_CANDIDATES),
);

$query = new ProductQuery(
    term: $buildResult->query->term,
    filters: $buildResult->query->filters,
    limit: $requestedLimit,
    sort: $buildResult->query->sort,
    candidateLimit: $candidateLimit,
);
```

`query.build`'s trace payload keeps `limitRequested` and replaces `limitApplied` with
`candidateLimit`. Then, **after** `$this->variantResolver->resolve(...)` and **after**
`$this->blocklist->apply(...)`, narrow and record:

```php
$survivors = $filtered['cards'];

// Narrowing happens here, not in retrieval: everything above needed the full
// candidate window to be correct, and nothing below can recover a unit that
// retrieval already dropped.
$returned = \array_slice($survivors, 0, $requestedLimit);

$this->renderer->registerRetrieved($returned);
```

The `retrieve` trace event gains `returnLimit`, `candidateLimit` and
`truncated` (`count($survivors) - count($returned)`) — a bounded result that is not recorded reads
as complete coverage when it is not. `registerRetrieved()` **must** receive `$returned`, not
`$survivors`: `FactRenderer` treats the last registered set as the authority on what the model saw,
and handing it cards the model never received would reopen the invention gap from ruling R47.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Core/Commerce tests/Core/Tool`
Expected: PASS. If `testTheAskedForVariantSurvivesEvenWhenRankingWouldSortItLast` still returns the
blue L, the candidate window is reaching the gateway but the fixture filter is still slicing on
`limit` — check step 4 before changing anything else.

- [ ] **Step 7: Mutation-check the new assertion**

Ruling R47's lesson: a green test proves the tree it ran on. Temporarily set
`candidateLimit: $requestedLimit` in the query construction, re-run
`testTheAskedForVariantSurvivesEvenWhenRankingWouldSortItLast`, and **confirm it goes red**. Restore.
Record both outcomes in your report. A test that passes with the control removed is not a test.

- [ ] **Step 8: Correct `ARCHITECTURE.md`**

The 2026-08-19 correction "ranking happens at stage 6, not stage 9" ends with *"moving limit
application after variant resolution is the real repair. It needs the gateway seam to carry the
distinction between 'how many to retrieve' and 'how many to return', so it is recorded here rather
than done."* Append a dated note saying it is now done, name `ProductQuery::retrievalLimit()` as the
seam carrier, and state that `SearchProductsTool::MIN_LIMIT` is gone rather than merely superseded.

- [ ] **Step 9: Full suite, gate, commit**

```bash
composer run format && composer run quality && vendor/bin/phpunit --exclude-group eval
git add src/Core/Commerce src/Core/Tool tests/Core ARCHITECTURE.md
git commit -m "fix: apply the search limit after variant resolution, not during retrieval"
```

Expected: quality exits 0, suite green at 197 tests.

---

### Task 3: `DalCommerceGateway` — facets, search, product

The task the Handoff calls the critical path: *"where the real unknowns live and what turns a
library into a Shopware feature."* Nothing in this repo has ever seen a real product.

**Files:**
- Create: `src/Core/Commerce/Dal/DalCommerceGateway.php`
- Create: `src/Core/Commerce/Dal/DalProductCardMapper.php`
- Create: `src/Core/Commerce/Dal/DalCriteriaBuilder.php`
- Create: `src/Core/Commerce/Dal/DalFacetReader.php`
- Create: `src/Core/Commerce/Dal/SalesChannelContextProvider.php`
- Modify: `src/Resources/config/services.xml`
- Modify: `composer-dependency-analyser.php` (remove the Task 1 ignores — the packages are now used)
- Test: `tests/Core/Commerce/Dal/DalProductCardMapperTest.php`
- Test: `tests/Core/Commerce/Dal/DalCriteriaBuilderTest.php`
- Test: `tests/Core/Commerce/Dal/DalFacetReaderTest.php`

**Interfaces:**
- Consumes: `CommerceGatewayInterface` (unchanged), all DTOs in `Core\Commerce\Dto`,
  `ProductQuery::retrievalLimit()` from Task 2.
- Produces:
  - `SalesChannelContextProvider::current(): SalesChannelContext` — throws
    `\RuntimeException` when no sales-channel context is available. This is the **only** class in
    the plugin allowed to reach into `RequestStack` for it.
  - `DalProductCardMapper::map(SalesChannelProductEntity $product, StockSource $source): ProductCard`
  - `DalCriteriaBuilder::build(ProductQuery $query, CatalogScope $scope, string $salesChannelId): Criteria`
  - `DalFacetReader::read(AggregationResultCollection $aggregations): FacetSet`
  - `DalCommerceGateway` implements `facets()`, `search()`, `product()`; Task 4 adds
    `resolveVariant()`, `addToCart()`, `cart()`.

**Why five classes, not one:** `mago`'s complexity and too-many-methods rules will fire on a single
class that builds criteria, maps entities, reads aggregations and holds the context — and per the
standing constraints a complexity finding means split, not suppress. Splitting up front is cheaper
than a fix round.

**The rule that must not break:** only DTOs cross the seam. `SalesChannelProductEntity`,
`SalesChannelContext` and `Criteria` may appear in these five classes' signatures and nowhere else
in the plugin. `ProductCard` is an allowlist — never add a passthrough field, and never read
`purchasePrices` or custom fields.

- [ ] **Step 1: Write the failing mapper test**

The mapper is where the three catalogue traps get caught, so it gets real assertions rather than a
smoke test. Build the entity by hand — no shop, no database, so this stays in the fast suite.

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalProductCardMapper;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

final class DalProductCardMapperTest extends TestCase
{
    private function option(string $id, string $name, string $group): PropertyGroupOptionEntity
    {
        $groupEntity = new PropertyGroupEntity();
        $groupEntity->setId(str_pad($group, 32, '0'));
        $groupEntity->setName($group);

        $option = new PropertyGroupOptionEntity();
        $option->setId($id);
        $option->setName($name);
        $option->setGroup($groupEntity);

        return $option;
    }

    private function product(float $unitPrice, int $stock): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId('a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2');
        $product->setParentId('fafafafafafafafafafafafafafafafa');
        $product->setName('Trail Jersey');
        $product->setDescription('Lightweight long-sleeve jersey for trail riding.');
        $product->setStock($stock);
        $product->setCalculatedPrice(new CalculatedPrice(
            $unitPrice,
            $unitPrice,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
        ));
        $product->setOptions(new PropertyGroupOptionCollection([
            $this->option('b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1', 'Blue', 'Colour'),
            $this->option('5d5d5d5d5d5d5d5d5d5d5d5d5d5d5d5d', 'M', 'Size'),
        ]));

        return $product;
    }

    public function testItReadsTheVariantsOwnCalculatedPriceRatherThanARawColumn(): void
    {
        // Four of the seeded variants carry no own price and inherit the parent's.
        // getCalculatedPrice() is resolved by the DAL; a raw `price` read is null there.
        $card = (new DalProductCardMapper('EUR', static fn (string $id): string => '/detail/' . $id))
            ->map($this->product(74.90, 0), StockSource::Variant);

        self::assertSame(74.90, $card->price);
        self::assertSame('EUR', $card->currency);
    }

    public function testASoldOutVariantIsReportedAsSoldOutRatherThanOmitted(): void
    {
        $card = (new DalProductCardMapper('EUR', static fn (string $id): string => '/detail/' . $id))
            ->map($this->product(74.90, 0), StockSource::Variant);

        self::assertSame(0, $card->stock);
        self::assertFalse($card->isInStock());
        self::assertSame(StockSource::Variant, $card->stockSource);
    }

    public function testOptionsAreKeyedByTheirGroupNameSoAVariantQuestionCanBeAnswered(): void
    {
        $card = (new DalProductCardMapper('EUR', static fn (string $id): string => '/detail/' . $id))
            ->map($this->product(74.90, 0), StockSource::Variant);

        self::assertSame(['Colour' => 'Blue', 'Size' => 'M'], $card->options);
    }

    public function testTheParentIdSurvivesSoVariantResolutionKnowsWhereToLook(): void
    {
        $card = (new DalProductCardMapper('EUR', static fn (string $id): string => '/detail/' . $id))
            ->map($this->product(79.90, 12), StockSource::Variant);

        self::assertSame('fafafafafafafafafafafafafafafafa', $card->parentId);
        self::assertSame('/detail/a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2', $card->url);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Commerce/Dal/DalProductCardMapperTest.php`
Expected: FAIL — `DalProductCardMapper` does not exist.

- [ ] **Step 3: Write `DalProductCardMapper`**

Constructor: `public function __construct(private readonly string $currency, private readonly \Closure $urlFor)`.
A closure rather than the router, so the mapper is unit-testable without a routing container; the
service definition binds it to `RouterInterface::generate('frontend.detail.page', ['productId' => $id], UrlGeneratorInterface::ABSOLUTE_URL)`.

`map()` reads, and reads nothing else:
- `$product->getId()`, `getParentId()`, `getName() ?? ''`, `getDescription()`
- `$product->getCalculatedPrice()->getUnitPrice()` — **never** a raw price array; that is trap 3
- `$product->getStock()` for the number, and `$source` for `stockSource`
- `$product->getDeliveryTime()?->getName()`
- `getOptions()` → `array<groupName, optionName>`, skipping any option whose group or name is null
- `getProperties()` → `array<groupName, list<optionName>>`
- `$product->getCategories()` is **not** read here: category paths need the category tree and the
  DAL association is not loaded by `DalCriteriaBuilder`. Pass `categoryPath: []` and say so in your
  report — a silently empty field is a finding, a declared one is a decision.
- `imageUrl`: `$product->getCover()?->getMedia()?->getUrl()`. Verify both accessors against the
  installed tree before using them; if either does not exist, pass `null` and report it.

- [ ] **Step 4: Write the criteria-builder test, then the builder**

`DalCriteriaBuilderTest` asserts four things, each of them a guarantee this project already made
elsewhere and must not lose at the DAL boundary:

```php
public function testItRetrievesTheCandidateWindowAndNotTheReturnLimit(): void
{
    $criteria = $this->builder()->build(
        new ProductQuery(term: 'Jersey', limit: 1, candidateLimit: 20),
        new CatalogScope(),
        self::CHANNEL,
    );

    // Task 2's whole point: truncating to 1 here would put ranking back in charge.
    self::assertSame(20, $criteria->getLimit());
}

public function testABlockedProductCannotBeRetrievedAtAll(): void
{
    $criteria = $this->builder()->build(
        new ProductQuery(term: 'Jersey'),
        new CatalogScope(blockedProductIds: ['a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2']),
        self::CHANNEL,
    );

    // D5: blocked items must never enter model context, so the exclusion is a
    // retrieval filter, not a post-pass. BlocklistFilter still runs afterwards.
    self::assertNotEmpty(array_filter(
        $criteria->getFilters(),
        static fn (Filter $f): bool => $f instanceof NotFilter,
    ));
}

public function testItScopesToTheSalesChannelSoAnotherChannelsProductsCannotAppear(): void
{
    $criteria = $this->builder()->build(new ProductQuery(term: 'Jersey'), new CatalogScope(), self::CHANNEL);

    self::assertNotEmpty(array_filter(
        $criteria->getFilters(),
        static fn (Filter $f): bool => $f instanceof ProductAvailableFilter,
    ));
}

public function testASoldOutVariantIsStillRetrievable(): void
{
    // ProductAvailableFilter checks visibility and active, NOT stock. If a
    // close-out filter ever gets added here, "is the blue M in stock?" becomes
    // unanswerable — the assistant would say the product does not exist.
    $criteria = $this->builder()->build(new ProductQuery(term: 'Jersey'), new CatalogScope(), self::CHANNEL);

    foreach ($criteria->getFilters() as $filter) {
        self::assertNotInstanceOf(ProductCloseoutFilter::class, $filter);
    }
}
```

`build()` then: `setTerm($query->term)` when non-null; `setLimit($query->retrievalLimit())`;
`ProductAvailableFilter($salesChannelId)`; one filter per `FilterClause` mapped by
`FilterOperator` (`Equals` → `EqualsFilter`, `Range` → `RangeFilter`, `Contains` → `ContainsFilter`);
`NotFilter(NotFilter::CONNECTION_OR, [EqualsAnyFilter('id', blockedProductIds), EqualsAnyFilter('categoriesRo.id', blockedCategoryIds)])` for the scope's exclusions, and
`EqualsAnyFilter('categoriesRo.id', includeCategoryIds)` when that list is non-empty;
`FieldSorting` from `$query->sort`; `addAssociation('options.group')`, `addAssociation('properties.group')`,
`addAssociation('deliveryTime')`, `addAssociation('cover.media')`.

**Verify `ProductCloseoutFilter`'s FQCN before referencing it in the test.** If no such class
exists in 6.7, assert the absence differently (no `EqualsFilter` on `stock`/`available`) and say so.

`CatalogScope::$minDescriptionWords` has no DAL equivalent — a word count is not a filterable
field. Report it as unmapped rather than approximating it.

- [ ] **Step 5: Write the facet-reader test, then the reader**

`DalFacetReader::read()` turns `TermsResult` into `Facet(FacetType::Terms, values)` and `StatsResult`
into `Facet(FacetType::Range, min, max)`. Two assertions matter:

```php
public function testATermsAggregationBecomesAFacetCarryingTheCatalogsOwnSpelling(): void
{
    // Ruling R20: the field AND the value must originate in the catalogue. A facet
    // that lowercases its values makes the model's "BLUE" match nothing, silently.
    $facets = $this->reader()->read(new AggregationResultCollection([
        new TermsResult('properties.Colour', [new Bucket('Blue', 3, null), new Bucket('Black', 3, null)]),
    ]));

    $facet = $facets->get('properties.Colour');
    self::assertNotNull($facet);
    self::assertSame(['Blue', 'Black'], $facet->values);
}

public function testAStatsAggregationBecomesARangeFacetAndNeverATermsOne(): void
{
    // Ruling R54: Range facets are excluded from the catalogue-vocabulary block
    // because their min/max are numbers, and putting numbers in front of the model
    // is what this project forbids. Mistyping one here would leak them.
    $facets = $this->reader()->read(new AggregationResultCollection([
        new StatsResult('price', 12.9, 79.9, 46.4, 278.4),
    ]));

    $facet = $facets->get('price');
    self::assertNotNull($facet);
    self::assertSame(FacetType::Range, $facet->type);
}
```

**Verify `TermsResult`, `Bucket`, `StatsResult` and `AggregationResultCollection` constructor
signatures against the installed tree before writing the test** — these are the shapes most likely
to differ from what this brief guesses, and R6 applies.

- [ ] **Step 6: Write `SalesChannelContextProvider` and `DalCommerceGateway`**

`SalesChannelContextProvider::current()`: read
`$this->requestStack->getMainRequest()?->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT)`;
return it when it is a `SalesChannelContext`; otherwise throw a `\RuntimeException` naming the
problem ("no sales-channel context in this request — the assistant only runs in a storefront
request or via the probe command"). Add a `withContext(SalesChannelContext $context, callable $fn)`
escape hatch, or an explicit setter, so Task 5's console command can supply one — a provider that
only works inside HTTP makes the probe impossible.

`DalCommerceGateway` composes: `SalesChannelRepository $productRepository`,
`DalCriteriaBuilder`, `DalProductCardMapper`, `DalFacetReader`, `SalesChannelContextProvider`.

- `facets(CatalogScope)`: build a criteria with `setLimit(1)`, add a `TermsAggregation` per
  property group present in the catalogue plus a `StatsAggregation` on `price`, call
  `aggregate()`, hand the result to `DalFacetReader`. Cache is `FacetProbe`'s job, not this class's.
- `search(ProductQuery, CatalogScope)`: build criteria, `search()`, map each entity with
  `StockSource::Variant` when `getParentId() !== null`, else `StockSource::Parent`.
  **That conditional is the whole of D4 at this layer** — a card mapped from a parent must never
  claim `Variant`.
- `product(string, CatalogScope)`: criteria with `EqualsFilter('id', $productId)` **and** the
  scope's exclusions, `setLimit(1)`. Returns null when nothing comes back — including when the
  scope excluded it, which is the point of the `$scope` parameter.

Register all five in `services.xml`, with `Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface`
aliased to `DalCommerceGateway`, and bind the mapper's `$currency` and `$urlFor` arguments explicitly.

- [ ] **Step 7: Run the tests**

Run: `vendor/bin/phpunit tests/Core/Commerce/Dal`
Expected: PASS. Then the full suite — the alias means `FixtureCommerceGateway` is no longer the
default service, and any test that resolved it from a container rather than constructing it directly
will now fail. Fix such a test by constructing the fixture gateway explicitly; do **not** change the
alias.

- [ ] **Step 8: Gate and commit**

```bash
composer run format && composer run quality && vendor/bin/phpunit --exclude-group eval
git add src/Core/Commerce/Dal src/Resources/config/services.xml composer-dependency-analyser.php tests/Core/Commerce/Dal
git commit -m "feat: add DalCommerceGateway search, facets and product lookup"
```

---

### Task 4: `DalCommerceGateway` — variant resolution and the real cart

Must-have 2's variant half and Must-have 3 in full. `resolveVariant()` is the method D4 exists for,
and `addToCart()` is the only write authority in the plugin.

**Files:**
- Create: `src/Core/Commerce/Dal/DalVariantFinder.php`
- Create: `src/Core/Commerce/Dal/DalCartAdapter.php`
- Modify: `src/Core/Commerce/Dal/DalCommerceGateway.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Core/Commerce/Dal/DalVariantFinderTest.php`
- Test: `tests/Core/Commerce/Dal/DalCartAdapterTest.php`

**Interfaces:**
- Consumes: everything Task 3 produced.
- Produces:
  - `DalVariantFinder::find(string $parentId, list<VariantSelection> $selections, CatalogScope $scope, SalesChannelContext $context): ?ProductCard`
  - `DalCartAdapter::add(string $variantId, int $quantity, SalesChannelContext $context): CartSummary`
    and `DalCartAdapter::summary(SalesChannelContext $context): CartSummary`
  - `DalCommerceGateway` now satisfies `CommerceGatewayInterface` completely.

- [ ] **Step 1: Write the failing variant-finder test**

The contract `VariantResolver` depends on absolutely (`src/Core/Grounding/VariantResolver.php`
docblock: *"it never guesses — it returns null unless the selections narrow to exactly one
variant"*). These four cases are that contract:

```php
public function testExactlyOneMatchingVariantIsReturnedWithItsOwnStock(): void
{
    $finder = $this->finderReturning([$this->variant('a2…a2', ['Colour' => 'Blue', 'Size' => 'M'], 0, 74.90)]);

    $card = $finder->find('fafa…fa', [new VariantSelection('Blue', 'Colour'), new VariantSelection('M', 'Size')], new CatalogScope(), $this->context());

    self::assertNotNull($card);
    self::assertSame(0, $card->stock);
    self::assertSame(74.90, $card->price);
    self::assertSame(StockSource::Variant, $card->stockSource);
}

public function testAnUnderSpecifiedSelectionReturnsNullRatherThanAGuess(): void
{
    // "Blue" alone matches Blue/S, Blue/M and Blue/L. Returning any of them
    // would be the failure D4 exists to prevent, dressed as a helpful answer.
    $finder = $this->finderReturning([
        $this->variant('a1…a1', ['Colour' => 'Blue', 'Size' => 'S'], 7, 79.90),
        $this->variant('a2…a2', ['Colour' => 'Blue', 'Size' => 'M'], 0, 74.90),
        $this->variant('a3…a3', ['Colour' => 'Blue', 'Size' => 'L'], 12, 79.90),
    ]);

    self::assertNull($finder->find('fafa…fa', [new VariantSelection('Blue', 'Colour')], new CatalogScope(), $this->context()));
}

public function testNoMatchReturnsNullRatherThanFallingBackToTheParent(): void
{
    self::assertNull($this->finderReturning([])->find('fafa…fa', [new VariantSelection('Green', 'Colour')], new CatalogScope(), $this->context()));
}

public function testAnOptionValueMatchesRegardlessOfTheCasingTheModelUsed(): void
{
    // Ruling R46: GetProductTool still passes raw model casing here. A
    // case-sensitive match makes "the black jersey in m" answer "no such product".
    $finder = $this->finderReturning([$this->variant('a5…a5', ['Colour' => 'Black', 'Size' => 'M'], 3, 69.90)]);

    $card = $finder->find('fafa…fa', [new VariantSelection('black'), new VariantSelection('m')], new CatalogScope(), $this->context());

    self::assertNotNull($card);
    self::assertSame('a5…a5', $card->id);
}
```

Replace the `…` placeholders with the full 32-character ids from the environment table before
running — they are abbreviated here for readability only.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Commerce/Dal/DalVariantFinderTest.php`
Expected: FAIL — `DalVariantFinder` does not exist.

- [ ] **Step 3: Write `DalVariantFinder`**

Retrieve every child of `$parentId`: criteria with `EqualsFilter('parentId', $parentId)`, the scope's
exclusions, the same associations Task 3 added, and **no availability filter on stock** — a sold-out
variant must remain findable, which is trap 1.

Then match in PHP, not in the query: for each candidate card, every selection must match one of its
`options` entries, comparing **case-insensitively on the value** and, when
`VariantSelection::$group` is non-null, case-insensitively on the group too. Return the single match
or `null`; never the first of several. Reuse `FixtureVariantMatcher`'s semantics deliberately — read
`src/Core/Commerce/Fixture/FixtureVariantMatcher.php` first and keep the two consistent, because
`VariantResolver` cannot tell which gateway it is talking to. If the matching logic is genuinely
identical, extract it to a shared collaborator and say so in your report; jscpd is part of the gate.

- [ ] **Step 4: Write the failing cart test, then `DalCartAdapter`**

```php
public function testAddingAVariantPutsThatVariantInTheCartAndReportsTheRealTotal(): void
{
    $adapter = new DalCartAdapter($this->cartService, $this->lineItemFactory, $this->urlFor);

    $summary = $adapter->add('a5…a5', 2, $this->context());

    self::assertSame(2, $summary->itemCount);
    self::assertSame('a5…a5', $summary->lineItems[0]->variantId);
    // 2 x 69.90 — the variant's own price, not the parent's 79.90.
    self::assertSame(139.80, $summary->total);
}

public function testTheCartCurrencyComesFromTheSalesChannelAndIsNotAssumed(): void
{
    self::assertSame('EUR', (new DalCartAdapter($this->cartService, $this->lineItemFactory, $this->urlFor))
        ->summary($this->context())->currency);
}
```

`add()`: `$lineItem = $this->lineItemFactory->create(['type' => 'product', 'referencedId' => $variantId, 'quantity' => $quantity], $context)`,
then `$cart = $this->cartService->getCart($context->getToken(), $context)`,
`$cart = $this->cartService->add($cart, $lineItem, $context)`, then map to `CartSummary`.
`summary()` maps the current cart without touching it.

Mapping: one `CartLine` per line item — `getId()` for `lineId`, `getReferencedId()` for `variantId`,
`getLabel()` for `name`, `getQuantity()`, `getPrice()?->getUnitPrice()`, `getPrice()?->getTotalPrice()`.
`CartSummary::$total` from `$cart->getPrice()->getTotalPrice()`, `$currency` from
`$context->getCurrency()->getIsoCode()`, `$itemCount` as the sum of line quantities,
`$checkoutUrl` from the router for `frontend.checkout.confirm.page`. **Verify every one of those
accessors and the route name against the installed tree** — `LineItem`, `Cart` and `CartPrice` are
the classes this brief is least certain about, and R6 applies. Where an accessor differs, adjust and
record it.

Guardrails stay where they already are: `AddToCartTool` enforces `maxItemQuantity` and `maxCartValue`
against the **live** cart. Do not reimplement them here, and do not weaken them — read
`src/Core/Tool/AddToCartTool.php` to confirm it calls `cart()` before deciding.

- [ ] **Step 5: Complete `DalCommerceGateway`**

Add the three remaining methods, each delegating with the context from `SalesChannelContextProvider`.
If the class now trips a too-many-methods or complexity finding, split it — six interface methods
over four collaborators should stay under, but the gate decides, not this brief.

- [ ] **Step 6: Run everything and commit**

```bash
composer run format && composer run quality && vendor/bin/phpunit --exclude-group eval
git add src/Core/Commerce/Dal src/Resources/config/services.xml tests/Core/Commerce/Dal
git commit -m "feat: resolve variants and add to the real cart over the DAL"
```

---

### Task 5: `swag:assistant:probe` — the first look at a real product, and the first trace anyone reads

Two open items close here. The spec's day plan has Robin *"verify against a real catalog"* with no
mechanism to do it, and Handoff known-issue 8 says **no trace has ever been read end to end** —
every claim about model behaviour on this branch is inferred from assertion text.

**Files:**
- Create: `src/Command/ProbeCommand.php`
- Create: `src/Command/TraceDumper.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Command/TraceDumperTest.php`

**Interfaces:**
- Consumes: `CommerceGatewayInterface`, `TraceRecorder`, `AssistantRunner`,
  `SalesChannelContextProvider`, `AbstractSalesChannelContextFactory`.
- Produces: `TraceDumper::dump(TraceRecorder $recorder): string` — a stage-ordered, human-readable
  rendering with the four never-collapsed payload fields always shown.

- [ ] **Step 1: Write the failing dumper test**

`ARCHITECTURE.md` names four payload fields that *"are the four ways this class of product lies"* and
must never be collapsed. A dumper that hides one is worse than no dumper, because it makes the trace
look clean.

```php
public function testTheFourFieldsThatAreHowThisProductLiesAreAlwaysRendered(): void
{
    $recorder = new TraceRecorder();
    $recorder->record('query.build', ['filtersDropped' => ['properties.Colour']]);
    $recorder->record('retrieve', ['hits' => 3, 'retainedIds' => ['a1', 'a2', 'a3']]);
    $recorder->record('validate', ['inventedProductIds' => ['fx-999']]);
    $recorder->record('grounding.select', ['modelClaimsDiscarded' => 1]);
    $recorder->record('render', ['stockSource' => 'variant']);

    $out = (new TraceDumper())->dump($recorder);

    foreach (['filtersDropped', 'inventedProductIds', 'modelClaimsDiscarded', 'stockSource'] as $field) {
        self::assertStringContainsString($field, $out);
    }
}

public function testEveryRecordedStageAppearsInSequenceOrder(): void
{
    $recorder = new TraceRecorder();
    $recorder->record('guard', []);
    $recorder->record('understand', []);
    $recorder->record('retrieve', []);

    $out = (new TraceDumper())->dump($recorder);

    self::assertLessThan(strpos($out, 'understand'), strpos($out, 'guard'));
    self::assertLessThan(strpos($out, 'retrieve'), strpos($out, 'understand'));
}

public function testAStageThatRanTwiceIsShownTwiceRatherThanDeduplicated(): void
{
    // TraceRecorder::stages() de-duplicates by design (ruling R18); events() does not.
    // A dumper built on stages() would hide the second tool round entirely.
    $recorder = new TraceRecorder();
    $recorder->record('tool.call', ['name' => 'search_products']);
    $recorder->record('tool.call', ['name' => 'get_product']);

    self::assertSame(2, substr_count((new TraceDumper())->dump($recorder), 'tool.call'));
}
```

- [ ] **Step 2: Run it to verify it fails, then write `TraceDumper`**

Run: `vendor/bin/phpunit tests/Command/TraceDumperTest.php` → FAIL, class missing.

Implement over `TraceRecorder::events()` — **not `stages()`**, per the third test. One block per
event: `seq`, `stage`, then each payload key/value, JSON-encoding nested values. No truncation of
the four named fields; other long values may be shortened, and when you shorten one, say so in the
output rather than silently cutting it.

- [ ] **Step 3: Write `ProbeCommand`**

`#[AsCommand(name: 'swag:assistant:probe')]` with three modes, so it serves both open items:

- `--search=<term>` — runs `gateway->search()` with a `ProductQuery` and prints each `ProductCard`
  as a table: id, name, price, currency, stock, **stockSource**, options, url. This is the
  "verify against a real catalog" mechanism.
- `--variant=<parentId> --option=Blue --option=M` (repeatable) — runs `resolveVariant()` and prints
  the card or the literal word `null`. Against the seeded jersey this must print stock 0 and price
  74.90 for Blue/M, and it must print `null` for `--option=Blue` alone.
- `--ask="<question>"` — runs `AssistantRunner` end to end and then prints `TraceDumper`'s output.
  **This mode requires a live LLM and must fail with a clear message when `ASSISTANT_LLM_BASE_URL`,
  `ASSISTANT_LLM_MODEL` or `ASSISTANT_LLM_API_KEY` is missing** — all three, per ruling R43. It is
  never run as part of a task; it is the tool Robin uses.

The command has no HTTP request, so `SalesChannelContextProvider::current()` would throw. Build a
context with `AbstractSalesChannelContextFactory::create(Uuid::randomHex(), $salesChannelId)` and
hand it to the provider through the escape hatch Task 3 added. Default the sales-channel id to
`01a01b4af6567284ac9eeb3616598ac3` via a `--sales-channel` option so it is overridable.

- [ ] **Step 4: Run the probe against the real catalogue**

This is the step that makes the branch's central claim true for the first time.

```bash
cd /Users/R.Schulte/Workspace/shopping-assistant-test
docker compose exec -T web bin/console swag:assistant:probe --search="Trail Jersey"
docker compose exec -T web bin/console swag:assistant:probe --variant=fafafafafafafafafafafafafafafafa --option=Blue --option=M
docker compose exec -T web bin/console swag:assistant:probe --variant=fafafafafafafafafafafafafafafafa --option=Blue
```

**Expected, and each of these is an acceptance criterion — paste the real output into your report:**
1. The search lists the jersey variants with `stockSource = variant` and per-variant stock.
2. Blue/M prints **stock 0** and **price 74.90** — not the parent's 35 and 79.90. That is A1.
3. Blue alone prints **`null`**. An under-specified selection that answers anyway is the defect
   this project is most concerned with.

If any of the three disagrees with the table in the environment section, **stop and report** — do
not adjust the expectation to match the output. The catalogue was built to these numbers.

- [ ] **Step 5: Gate and commit**

```bash
composer run format && composer run quality && vendor/bin/phpunit --exclude-group eval
git add src/Command src/Resources/config/services.xml tests/Command
git commit -m "feat: add a probe command that reads the real catalogue and dumps a trace"
```

---

### Task 6: Merchant configuration reaches the pipeline

`AssistantConfig` and `LlmSettings` exist with defaults and nothing populates them from the shop.
Until this lands, `config.xml` is a form that changes nothing — including the kill switch, which is
a security control, not an ops nicety.

**Files:**
- Create: `src/Core/Config/SystemConfigAssistantConfig.php`
- Create: `src/Core/Config/SystemConfigLlmSettings.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Core/Config/SystemConfigAssistantConfigTest.php`
- Test: `tests/Core/Config/SystemConfigLlmSettingsTest.php`

**Interfaces:**
- Consumes: `AssistantConfig`, `CatalogScope`, `LlmSettings`, the `config.xml` keys Task 1 declared.
- Produces:
  - `SystemConfigAssistantConfig::forSalesChannel(string $salesChannelId): AssistantConfig`
  - `SystemConfigLlmSettings::forSalesChannel(string $salesChannelId): LlmSettings`

- [ ] **Step 1: Write the failing tests**

```php
public function testEveryMerchantSettingReachesTheConfigObject(): void
{
    $factory = new SystemConfigAssistantConfig($this->configService([
        'SwagAssistantStarterKit.config.agentVoice' => 'Be concise.',
        'SwagAssistantStarterKit.config.enableAddToCart' => false,
        'SwagAssistantStarterKit.config.maxItemQuantity' => 2,
        'SwagAssistantStarterKit.config.maxCartValue' => 250.0,
        'SwagAssistantStarterKit.config.killSwitch' => true,
        'SwagAssistantStarterKit.config.dailyRequestCap' => 50,
        'SwagAssistantStarterKit.config.maxToolCallsPerTurn' => 3,
    ]));

    $config = $factory->forSalesChannel(self::CHANNEL);

    self::assertSame('Be concise.', $config->agentVoice);
    self::assertFalse($config->enableAddToCart);
    self::assertSame(2, $config->maxItemQuantity);
    self::assertSame(250.0, $config->maxCartValue);
    self::assertTrue($config->killSwitch);
    self::assertSame(50, $config->dailyRequestCap);
    self::assertSame(3, $config->maxToolCallsPerTurn);
}

public function testTheKillSwitchDefaultsToOffButAnUnreadableValueDoesNotSilentlyDisableIt(): void
{
    // getBool() returns false for an absent key, which is the safe default here.
    // What must NOT happen is a typo'd key name reading as "kill switch off" while
    // the merchant believes it is on — so the key names are asserted, not assumed.
    self::assertFalse((new SystemConfigAssistantConfig($this->configService([])))
        ->forSalesChannel(self::CHANNEL)->killSwitch);
}

public function testBlockedIdsAreSplitPerLineAndTrimmedRatherThanTakenAsOneString(): void
{
    $config = (new SystemConfigAssistantConfig($this->configService([
        'SwagAssistantStarterKit.config.blockedProducts' => "a2a2\n  b3b3  \n\nc4c4",
    ])))->forSalesChannel(self::CHANNEL);

    // One long string here would mean the blocklist matches nothing — a compliance
    // control that silently does nothing is worse than an absent one (D5).
    self::assertSame(['a2a2', 'b3b3', 'c4c4'], $config->scope->blockedProductIds);
}

public function testAnEmptyBaseUrlIsRejectedRatherThanPassedToTheEgressGuard(): void
{
    $this->expectException(LlmException::class);

    (new SystemConfigLlmSettings($this->configService([])))->forSalesChannel(self::CHANNEL);
}
```

Build `$this->configService()` as a small in-test subclass or a PHPUnit mock of
`SystemConfigService` whose `get`/`getBool`/`getInt`/`getString` read the supplied array. **No
pragmas in test files** — where the analyzer objects to a mixed return, bind to a local and narrow
with `assertNotNull`/`assertIsString`.

- [ ] **Step 2: Run to verify failure, then implement both classes**

Run: `vendor/bin/phpunit tests/Core/Config` → FAIL, classes missing.

`SystemConfigAssistantConfig` reads each key with the typed accessor that matches its `config.xml`
field type, splits the three list fields on newlines with `array_filter` after `trim`, assembles a
`CatalogScope`, and returns an `AssistantConfig` **constructed with named arguments** (ruling R17's
carve-out for that class depends on it).

`SystemConfigLlmSettings` reads `llmBaseUrl`, `llmModel`, `llmApiKey`, throws `LlmException` when
base url or model is empty, and never logs or interpolates the key — `LlmSettings::$apiKey` already
carries `#[\SensitiveParameter]` and that intent must not be undone here.

- [ ] **Step 3: Wire both in `services.xml`, run everything, commit**

```bash
composer run format && composer run quality && vendor/bin/phpunit --exclude-group eval
git add src/Core/Config src/Resources/config/services.xml tests/Core/Config
git commit -m "feat: read merchant configuration into the assistant config objects"
```

---

### Task 7: Conversation and trace persistence

Acceptance criterion A6 (*"every turn produces a persisted trace"*) is unmet — `TraceRecorder` is
in-memory only. But the reason this task comes before the widget is **not** the trace: per
`ARCHITECTURE.md`, *"Across page loads is not optional"* and *"one table, two readers — do not build
a second one."* The demo sentence is "show me the trail jersey in blue, size L" → click through →
"add that to my cart". Without re-hydration the assistant does not know what "that" is, and
Must-have 3 fails. The trace comes along for free in the same table.

**Files:**
- Create: `src/Resources/config/entities.xml`
- Create: `src/Core/Trace/ConversationStore.php` (interface)
- Create: `src/Core/Trace/DalConversationStore.php`
- Create: `src/Core/Trace/ConversationTurn.php` (DTO)
- Modify: `src/Resources/config/services.xml`
- Modify: `ARCHITECTURE.md` (the P4 divergence)
- Test: `tests/Core/Trace/ConversationStoreContractTest.php`

**Interfaces:**
- Consumes: `TraceRecorder::events()`, `TraceEvent{seq, stage, payload}`, `AssistantTurn`.
- Produces:
  - `ConversationStore::start(string $salesChannelId, string $locale): string` — returns a
    conversation token.
  - `ConversationStore::append(string $token, ConversationTurn $turn, TraceRecorder $trace): void`
  - `ConversationStore::history(string $token, int $limit = 10): list<ConversationTurn>` — for the
    widget's re-hydration, oldest first.
  - `ConversationTurn(string $role, string $prose, list<string> $cardIds, string $outcome)`
  - Task 8's controller depends on exactly these four signatures.

- [ ] **Step 1: Write `entities.xml`**

Two custom entities. Names **must** carry the `ce_` prefix
(`System/CustomEntity/Schema/SchemaUpdater.php:21-23`); this is the P4 divergence from
`ARCHITECTURE.md`'s documented `swag_assistant_*` names.

`ce_swag_assistant_conversation`: `sales_channel_id` (string), `locale` (string),
`turn_count` (int), `outcome` (string), `total_ms` (int), `transcript` (json), and a
`one-to-many` to the events entity.

`ce_swag_assistant_trace_event`: `seq` (int), `stage` (string), `payload` (json), and a
`many-to-one` back to the conversation with `on-delete="cascade"`.

**Two fields `ARCHITECTURE.md` lists are deliberately absent, and this is the reason.**
`TraceEvent` is `{seq, stage, payload}` — it carries no timing at all, so a `duration_ms`
column could only ever be written as 0, and a column that is always 0 is worse than an absent
one: someone will query it and believe the answer. `first_token_ms` is a streaming metric and
streaming is on the spec's cut list. `total_ms` survives because the controller can measure it
honestly with a wall clock around the runner call. If per-stage timing is wanted later, the
change belongs in `TraceRecorder::record()`, not in this schema — record that in your report.

Validate the file against `vendor/shopware/core/System/CustomEntity/Xml/entity-1.0.xsd` before
moving on — a schema error surfaces as a silent boot failure, not a readable message.

`transcript` as json on the conversation is deliberate: it is what the widget re-hydrates from, and
keeping it beside the trace honours "one table, two readers" rather than adding a third entity for
messages.

- [ ] **Step 2: Write the failing contract test**

The store is the one class in this task with logic worth testing without a database, so the test
targets the contract through an in-memory implementation that the production class must also satisfy.
Write the test against the **interface**, and provide an `InMemoryConversationStore` test double in
`tests/` — that double is also what Task 8's controller test uses.

```php
public function testAConversationRoundTripsSoTheWidgetCanRehydrateAfterAPageLoad(): void
{
    $store = $this->store();
    $token = $store->start(self::CHANNEL, 'en-GB');

    $store->append($token, new ConversationTurn('user', 'show me the trail jersey in blue, size L', [], 'product_shown'), new TraceRecorder());
    $store->append($token, new ConversationTurn('assistant', 'The Trail Jersey in Blue / L is available.', ['a3a3…a3'], 'product_shown'), new TraceRecorder());

    $history = $store->history($token);

    // Oldest first: the widget replays them in order, and "add that to my cart"
    // only resolves if the assistant turn carrying the card id is still there.
    self::assertCount(2, $history);
    self::assertSame('user', $history[0]->role);
    self::assertSame(['a3a3…a3'], $history[1]->cardIds);
}

public function testAnUnknownTokenYieldsAnEmptyHistoryRatherThanAnError(): void
{
    // A shopper with a stale sessionStorage token must get a fresh conversation,
    // not a 500 on page load.
    self::assertSame([], $this->store()->history('deadbeefdeadbeefdeadbeefdeadbeef'));
}

public function testEveryTraceEventOfATurnIsPersistedInSequenceOrder(): void
{
    $store = $this->store();
    $token = $store->start(self::CHANNEL, 'en-GB');

    $trace = new TraceRecorder();
    $trace->record('guard', ['verdict' => 'allow']);
    $trace->record('retrieve', ['hits' => 3]);
    $trace->record('render', ['stockSource' => 'variant']);

    $store->append($token, new ConversationTurn('assistant', 'ok', [], 'product_shown'), $trace);

    // A6: every turn produces a persisted trace with all pipeline stages. A store
    // that keeps the last event per stage would satisfy the letter and lose the turn.
    self::assertSame(['guard', 'retrieve', 'render'], $this->persistedStages($store, $token));
}
```

- [ ] **Step 3: Run to verify failure, then implement**

Run: `vendor/bin/phpunit tests/Core/Trace/ConversationStoreContractTest.php` → FAIL.

`DalConversationStore` takes `DefinitionInstanceRegistry` (or the two repositories directly, via
`services.xml` arguments) and `Context::createDefaultContext()` for writes — the assistant writes
its own trace, not on behalf of a user. `start()` generates a `Uuid::randomHex()` and returns it.
`append()` writes one `upsert` for the conversation (incrementing `turn_count`, appending to
`transcript`) and one `create` per trace event, preserving `TraceEvent::$seq`. Reads use
`events()`, never `stages()` — `stages()` de-duplicates by design (ruling R18) and would drop the
second tool round.

**Verify the repository access pattern against the installed tree**: custom entities are reachable as
`$this->definitionInstanceRegistry->getRepository('ce_swag_assistant_conversation')`. If that entity
name is not registered until the plugin is active, the probe from Task 5 is how you check — say so
in your report rather than guessing.

- [ ] **Step 4: Prove the tables exist in the real shop**

```bash
cd /Users/R.Schulte/Workspace/shopping-assistant-test
docker compose exec -T web bin/console plugin:update SwagAssistantStarterKit
docker compose exec -T database mariadb -uroot -proot -e "SHOW TABLES LIKE 'ce_swag_assistant%';" shopware
```

Expected: both tables present. Custom-entity schema is applied by Shopware's `SchemaUpdater` on
install/update — if the tables are absent, the XML did not validate, and the fix is the XML, not a
migration.

- [ ] **Step 5: Correct `ARCHITECTURE.md`**

Its "Trace data model" section names `swag_assistant_conversation` and
`swag_assistant_trace_event`, and its directory layout shows `src/Entity/` plus `src/Migration/`.
Add a dated correction: custom entities were chosen (the spec's own Should 5 wording), the `ce_`
prefix is mandatory, there is no migration, and `src/Entity/` and `src/Migration/` do not exist.
State that `duration_ms` and `first_token_ms` are **not** implemented and why (step 1). Keep the four
never-collapsed payload fields exactly as they are — those are unchanged.

- [ ] **Step 6: Gate and commit**

```bash
composer run format && composer run quality && vendor/bin/phpunit --exclude-group eval
git add src/Core/Trace src/Resources/config tests/Core/Trace ARCHITECTURE.md
git commit -m "feat: persist conversations and traces as custom entities"
```

---

### Task 8: Storefront controller and chat widget

Must-have 1 and Must-have 3 — the thing a shopper actually touches. **No streaming**, per the spec's
cut list.

**Files:**
- Create: `src/Controller/AssistantController.php`
- Create: `src/Resources/config/routes.xml`
- Create: `src/Resources/views/storefront/base.html.twig`
- Create: `src/Resources/views/storefront/component/assistant/widget.html.twig`
- Create: `src/Resources/app/storefront/src/main.js`
- Create: `src/Resources/app/storefront/src/assistant/assistant.plugin.js`
- Create: `src/Resources/app/storefront/src/scss/base.scss`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Controller/AssistantControllerTest.php`

**Interfaces:**
- Consumes: `AssistantRunner`, `AssistantTurn`, `ConversationStore` (Task 7),
  `SystemConfigAssistantConfig` and `SystemConfigLlmSettings` (Task 6), `FactRenderer`.
- Produces: `POST /assistant/chat` accepting `{"message": string, "token": string|null}` and
  returning `{"token": string, "prose": string, "cards": [...], "outcome": string}`;
  `GET /assistant/history?token=…` returning `{"messages": [...]}`.

- [ ] **Step 1: Write the failing controller test**

The controller has no Shopware test harness (spec cut it), so test the **request-to-response
translation** with a hand-built `Request` and test doubles, which is where the mistakes are:

```php
public function testAMissingMessageIsRejectedBeforeAnyModelSpend(): void
{
    $response = $this->controller()->chat($this->jsonRequest([]), $this->salesChannelContext());

    self::assertSame(400, $response->getStatusCode());
    self::assertSame(0, $this->runner->callCount, 'A malformed request must not reach the LLM.');
}

public function testTheKillSwitchStopsTheTurnWithoutCallingTheModel(): void
{
    // The cap and the kill switch are security controls, not ops niceties. Guard
    // runs before any spend (lifecycle stage 1) and the controller must not
    // reorder that.
    $this->config->killSwitch = true;

    $response = $this->controller()->chat($this->jsonRequest(['message' => 'hi']), $this->salesChannelContext());

    self::assertSame(0, $this->runner->callCount);
    self::assertSame(503, $response->getStatusCode());
}

public function testAFreshConversationGetsATokenTheWidgetCanKeep(): void
{
    $payload = $this->decode($this->controller()->chat($this->jsonRequest(['message' => 'hi']), $this->salesChannelContext()));

    self::assertNotSame('', $payload['token']);
}

public function testTheResponseCarriesRenderedCardsAndNeverAModelSuppliedPrice(): void
{
    // D3: every figure in the response comes from FactRenderer, so the JSON is
    // built from rendered cards — never from anything parsed out of the prose.
    $payload = $this->decode($this->controller()->chat($this->jsonRequest(['message' => 'blue jersey in M']), $this->salesChannelContext()));

    self::assertSame(74.90, $payload['cards'][0]['price']);
    self::assertSame(0, $payload['cards'][0]['stock']);
    self::assertSame('variant', $payload['cards'][0]['stockSource']);
}

public function testHistoryReplaysAnExistingConversationSoThatResolvesAfterAPageLoad(): void
{
    $token = $this->store->start(self::CHANNEL, 'en-GB');
    $this->store->append($token, new ConversationTurn('assistant', 'The Trail Jersey in Blue / L.', ['a3a3…a3'], 'product_shown'), new TraceRecorder());

    $payload = $this->decode($this->controller()->history($this->request(['token' => $token])));

    self::assertCount(1, $payload['messages']);
}
```

- [ ] **Step 2: Run to verify failure, then write the controller**

Run: `vendor/bin/phpunit tests/Controller/AssistantControllerTest.php` → FAIL.

Extend `Shopware\Storefront\Controller\StorefrontController`. Route attributes, following the
verified 6.7 pattern in
`/Users/R.Schulte/Workspace/page-agent-shopware/src/Storefront/Controller/PageAgentProxyController.php`:

```php
#[Route(
    path: '/assistant/chat',
    name: 'frontend.assistant.chat',
    defaults: ['XmlHttpRequest' => true],
    methods: ['POST'],
)]
public function chat(Request $request, SalesChannelContext $context): Response
```

`SalesChannelContext` is injected by Shopware for a `frontend.*` route — that is what makes customer
group and rule-based prices correct for free, and it is what `SalesChannelContextProvider` reads.

Order inside `chat()`, and this order is the design: decode and validate the body → build
`AssistantConfig` for this sales channel → **guard** → `AssistantRunner` → read cards from
`FactRenderer` → persist through `ConversationStore` → build the JSON from the **rendered cards**.
A price or stock number must never be read from anywhere but a rendered card.

`routes.xml` imports the controller by attribute:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<routes xmlns="http://symfony.com/schema/routing"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:schemaLocation="http://symfony.com/schema/routing https://symfony.com/schema/routing/routing-1.0.xsd">
    <import resource="../../Controller/*Controller.php" type="attribute"/>
</routes>
```

- [ ] **Step 3: Write the widget**

`base.html.twig` mounts it, following the verified 6.7 pattern
(`page-agent-shopware/src/Resources/views/storefront/base.html.twig`):

```twig
{% sw_extends '@Storefront/storefront/base.html.twig' %}

{% block base_body_script %}
    {{ parent() }}

    {% if config('SwagAssistantStarterKit.config.llmModel') %}
        {% sw_include '@SwagAssistantStarterKit/storefront/component/assistant/widget.html.twig' %}
    {% endif %}
{% endblock %}
```

`widget.html.twig`: a launcher button, a panel with a message list, a text input and a send button.
No product markup is written by JavaScript from prose — cards render from the JSON `cards` array,
field by field, because that is where D3 lands in the UI.

`assistant.plugin.js`: a Shopware storefront plugin class that keeps the conversation token in
`sessionStorage`, calls `GET /assistant/history` on mount to re-hydrate, `POST`s to
`/assistant/chat`, and renders prose plus cards. Escape every value into the DOM with
`textContent`, never `innerHTML` — the prose comes from a model reading merchant catalogue text, and
fixture `fx-017` exists because that text is attacker-controlled.

Register the plugin in `main.js` via `window.PluginManager.register(...)`. Verify the 6.7 storefront
plugin registration idiom against `vendor/shopware/storefront/Resources/app/storefront/src/` before
writing it — 6.7 moved storefront blocks and `storefront-sales-chatbot`'s 6.6-era code is
orientation only, per D16.

- [ ] **Step 4: Build the storefront and see it**

```bash
cd /Users/R.Schulte/Workspace/shopping-assistant-test
docker compose exec -T web bin/console plugin:update SwagAssistantStarterKit
docker compose exec -T web bin/console theme:compile
docker compose exec -T web bin/console cache:clear
curl -s http://127.0.0.1:8000/ | grep -c assistant
```

Expected: the widget markup is present in the page. Then open `http://127.0.0.1:8000/` and ask
"do you have the trail jersey in blue, size M?" — this needs a live LLM, so it is **Robin's step,
not the task's**. Report the build output and the grep count; do not claim the conversation works
without having run it.

- [ ] **Step 5: Gate and commit**

```bash
composer run format && composer run quality && vendor/bin/phpunit --exclude-group eval
git add src/Controller src/Resources tests/Controller
git commit -m "feat: add the storefront chat endpoint and widget"
```

---

## What this plan does not do

Named so they are decisions rather than omissions:

- **Admin trace view** — cut (P6). Spec Q2 already doubted it. P4 keeps it cheap if time appears.
- **The eight known issues from `docs/HANDOFF.md`** — item 6 is repaired by Task 2 because Task 3
  writes the second implementation of that seam. The other seven are untouched by decision.
- **Re-measuring the eval suite against real product names.** Tempting after Task 3, but D10 pins
  evals to fixtures and A7 requires the suite to run with no Shopware present; a live eval suite is
  on the spec's own cut list and in §9 as a post-demo follow-up.
- **The prose audit's coverage gap** (known issue 7): currency figures only. A model describing
  option values it never verified is still uncaught. Unbuilt, not solved.
- **DNS-rebind TOCTOU in the egress guard** (ruling R15) — parked, documented, and a stated blocker
  for a pilot rather than a demo.
- **`CatalogScope::$minDescriptionWords`** has no DAL equivalent and is reported unmapped by Task 3.
- **Category paths on DAL-sourced cards** are empty (Task 3, step 3) because the category-tree
  association is not loaded.

## Self-review against the spec

| Spec requirement | Task |
|---|---|
| Must 1 — widget in a real 6.7 storefront | T1 (installable), T8 (widget) |
| Must 2 — real catalogue data, variant-level stock | T3 (search, mapping), T4 (resolution), T5 (proof) |
| Must 3 — "add that to my cart" reaches the real cart | T4 (cart), T7 (memory across page loads), T8 (endpoint) |
| Must 4 — terminal eval run, 6 journeys | already built in Plan 1; T2 keeps it green and repairs the ranking defect |
| Should 6 — `config.xml` | T1 (form), T6 (it reaching the pipeline) |
| Should 5 — admin trace view | **cut, P6** |
| A1 variant stock, not parent | T4 test 1, T5 step 4 expectation 2 |
| A2 no product outside the retrieved set | unchanged from Plan 1; T2 step 5 keeps `registerRetrieved()` honest |
| A3 blocked product absent from context | T3 step 4 test 2 (retrieval-level exclusion) |
| A4 injection fixture yields no false price | unchanged from Plan 1 |
| A5 `add_to_cart` puts the correct variant in the real cart | T4, verified manually per the spec |
| A6 every turn produces a persisted trace | T7 test 3 |
| A7 eval suite runs with no Shopware present | preserved — no task adds Shopware to the eval path; T3's alias is the one risk and T3 step 7 checks it |
