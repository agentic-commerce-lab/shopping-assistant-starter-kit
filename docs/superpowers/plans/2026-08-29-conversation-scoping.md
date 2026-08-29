# Conversation Scoping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a conversation belong to the shopper who started it, so a token cannot open someone else's transcript.

**Architecture:** A `ShoppingContext` value object is resolved once per storefront request from the sales-channel context, and travels into every shopper-facing conversation-store call. The store writes that scope onto the row and refuses to read or append across it. The browser keeps one token per opaque context key instead of one token overall, so logging out and back in restores the right conversation rather than the last one.

**Tech Stack:** PHP 8.2, Shopware 6.7 (core + storefront in `vendor/`), Doctrine DBAL migrations, PHPUnit 11, vanilla ES modules for the widget.

**Spec:** `docs/superpowers/specs/2026-08-27-b2b-commercial-context-design.md` — sections 5.1, 5.2 (core paths only), 9.1, 9.2, 9.3. Section 16's sequencing items 1 and 2 are **Done**.

**What this deliberately does not build:** the Commercial bridge, employee and organisation *resolution*, the context label, catalogue enforcement, and the `commercial` / `commercial_unavailable` modes. The demo instance's `commercial:feature:list` shows B2B is not in its licence, so none of that can be verified — see the spec's section 16 delivery boundary. The **columns and the comparison** for employee and organisation land here anyway, always null, so the bridge later only has to populate them and no second migration is needed.

## Why this is worth doing before any B2B work

A conversation token is currently a pure bearer credential: `DalConversationStore::history()` looks the row up by primary key and returns the transcript to whoever presents it. There is no owner check anywhere. The token is a 128-bit random value, so this is a bounded risk rather than an open door — but the fix is much cheaper now than after production conversations accumulate, and every B2B guarantee in the spec is built on top of it.

## Global Constraints

- `php: ^8.2`; `shopware/core: ~6.7.0`; `shopware/storefront: ~6.7.0`. **No new Composer or npm dependencies.**
- Shopware types may appear only inside `src/Core/Commerce/Dal/` and the entity/migration layer. `ShoppingContext` is Shopware-free.
- **A conversation token stays the bearer secret.** The browser's context key is a lookup convenience and is never trusted as authorisation — the server always validates the token against the *actual* current context.
- An invalid or foreign token returns **no history and no reason**. Never disclose why.
- Administrative trace access stays ACL-controlled and is **not** scope-checked. `traceEvents()` must keep working for the merchant's export.
- Files stay at or under **400 physical lines** (`composer quality:filesize`).
- `composer test` must pass with **no `.env` file present**; `composer quality` must pass end to end.
- mago's gates fail at error level via the pre-commit hook: **11 methods per class** and a class-scoped **cyclomatic-complexity budget of 10**. The house resolution is a sibling file or class with a docblock explaining the split — see `tests/Core/Commerce/Dal/DalProductCardMapperAdvancedPriceTest.php` and `src/Core/Tool/CartCorrectionNote.php`.
- Storefront DOM-assembly functions get no unit tests (`tests/e2e/README.md`); pure functions do.

---

### Task 1: The shopping context and what makes two of them the same

**Files:**
- Create: `src/Core/Context/ShoppingMode.php`
- Create: `src/Core/Context/ShoppingContext.php`
- Test: `tests/Core/Context/ShoppingContextTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `enum ShoppingMode: string { case Guest = 'guest'; case Customer = 'customer'; }` and `final readonly class ShoppingContext` with public `ShoppingMode $mode`, `string $salesChannelId`, `?string $customerId`, `?string $employeeId`, `?string $organisationId`, plus `matches(self $other): bool`. Every later task uses these.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Context/ShoppingContextTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Context;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;

/**
 * `matches()` is the whole security boundary of this plan in one method: it decides whether a
 * presented conversation token may open a stored transcript. Every case here is a way two shoppers
 * could be confused for each other.
 */
final class ShoppingContextTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';
    private const OTHER_CHANNEL = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
    private const ALICE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const BOB = 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

    private function customer(string $customerId, string $channel = self::CHANNEL): ShoppingContext
    {
        return new ShoppingContext(ShoppingMode::Customer, $channel, $customerId);
    }

    public function testTheSameCustomerOnTheSameChannelMatches(): void
    {
        self::assertTrue($this->customer(self::ALICE)->matches($this->customer(self::ALICE)));
    }

    public function testTwoCustomersNeverMatch(): void
    {
        // The defect this plan exists to close: Bob presenting Alice's token.
        self::assertFalse($this->customer(self::ALICE)->matches($this->customer(self::BOB)));
    }

    public function testTheSameCustomerOnADifferentSalesChannelDoesNotMatch(): void
    {
        // One account, two storefronts — different catalogue, different currency, different
        // conversation. A transcript from one must not surface in the other.
        self::assertFalse($this->customer(self::ALICE)->matches($this->customer(self::ALICE, self::OTHER_CHANNEL)));
    }

    public function testAGuestNeverMatchesACustomer(): void
    {
        // Both directions, because a guest token surviving a login and a customer token surviving a
        // logout are two different bugs with the same fix.
        $guest = new ShoppingContext(ShoppingMode::Guest, self::CHANNEL);

        self::assertFalse($guest->matches($this->customer(self::ALICE)));
        self::assertFalse($this->customer(self::ALICE)->matches($guest));
    }

    public function testTwoGuestsOnTheSameChannelMatch(): void
    {
        // Guests are not told apart, and must not be: there is no identity to compare. What keeps
        // one guest out of another's transcript is the token itself, which never leaves their
        // browser. See the class docblock.
        $guest = new ShoppingContext(ShoppingMode::Guest, self::CHANNEL);

        self::assertTrue($guest->matches(new ShoppingContext(ShoppingMode::Guest, self::CHANNEL)));
    }

    public function testAnEmployeeIsComparedEvenThoughNothingSetsOneYet(): void
    {
        // Null today, populated when the Commercial bridge lands. Comparing it now means the bridge
        // adds a resolver and nothing else — and that two colleagues of one company, who present
        // the *same* customer id, are already told apart by this method.
        $alice = new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::ALICE, employeeId: 'e1');
        $colleague = new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::ALICE, employeeId: 'e2');

        self::assertFalse($alice->matches($colleague));
        self::assertTrue($alice->matches(new ShoppingContext(
            ShoppingMode::Customer,
            self::CHANNEL,
            self::ALICE,
            employeeId: 'e1',
        )));
    }

    public function testAnOrganisationIsComparedTheSameWay(): void
    {
        $berlin = new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::ALICE, organisationId: 'o1');
        $munich = new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::ALICE, organisationId: 'o2');

        self::assertFalse($berlin->matches($munich));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Context/ShoppingContextTest.php
```

Expected: FAIL — `Class "Swag\AssistantStarterKit\Core\Context\ShoppingContext" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Core/Context/ShoppingMode.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Context;

/**
 * Who the assistant is talking to, at the coarsest useful grain.
 *
 * Two cases, not four. The spec's `commercial` and `commercial_unavailable` modes need a Commercial
 * bridge that cannot be built or verified without a B2B licence, and an enum case nothing can
 * produce is a branch nothing can test. They are added with the bridge.
 *
 * The backed values are what `swag_assistant_conversation.scope_type` stores, so renaming a case is
 * a migration, not a refactor.
 */
enum ShoppingMode: string
{
    case Guest = 'guest';
    case Customer = 'customer';
}
```

Create `src/Core/Context/ShoppingContext.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Context;

/**
 * Who is shopping, resolved once per request and compared on every conversation access.
 *
 * Deliberately Shopware-free: it holds resolved facts and asks nothing. Resolution lives in
 * {@see ShoppingContextResolver}, which is the only class here allowed to see a `SalesChannelContext`.
 *
 * **Two guests match, and that is not a hole.** There is no identity to compare, so what keeps one
 * guest out of another's transcript is the conversation token itself — 128 bits of randomness that
 * never leaves the browser that was issued it. Pretending otherwise would mean inventing a guest
 * identity, which is a tracking identifier with better manners and no better security.
 *
 * `employeeId` and `organisationId` are always null today. They are compared anyway so the
 * Commercial bridge only has to populate them: under Shopware's B2B Components every employee of one
 * company presents the *same* customer id, so customer identity alone would let colleagues read each
 * other's conversations.
 */
final readonly class ShoppingContext
{
    public function __construct(
        public ShoppingMode $mode,
        public string $salesChannelId,
        public ?string $customerId = null,
        public ?string $employeeId = null,
        public ?string $organisationId = null,
    ) {}

    public function matches(self $other): bool
    {
        return $this->mode === $other->mode
            && $this->salesChannelId === $other->salesChannelId
            && $this->customerId === $other->customerId
            && $this->employeeId === $other->employeeId
            && $this->organisationId === $other->organisationId;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
vendor/bin/phpunit tests/Core/Context/ShoppingContextTest.php
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Context tests/Core/Context
git commit -m "feat(context): a shopping context, and what makes two of them the same"
```

---

### Task 2: Resolving the context from the request

**Files:**
- Create: `src/Core/Context/ShoppingContextResolver.php`
- Modify: `src/Resources/config/services.xml` — register it
- Test: `tests/Core/Context/ShoppingContextResolverTest.php`

**Interfaces:**
- Consumes: `ShoppingContext`, `ShoppingMode` (Task 1); the existing `SalesChannelContextProvider` in `src/Core/Commerce/Dal/`, whose `current()` returns the request-scoped `SalesChannelContext` and throws `\RuntimeException` when there is none.
- Produces: `ShoppingContextResolver::current(): ShoppingContext`. Tasks 3, 5 and 6 use it.

**Read first:** `src/Core/Commerce/Dal/SalesChannelContextProvider.php`. Its docblock states it is **the single place in this plugin allowed to reach for the request-scoped `SalesChannelContext`**. Do not add a second `RequestStack` reader — depend on that provider.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Context/ShoppingContextResolverTest.php`. Nothing in this suite constructs a real `SalesChannelContext` — `tests/Controller/AssistantEndpointTestCase.php:130` mocks it, and that is the established pattern here because the constructor takes a dozen collaborators none of which this test cares about. Follow it, and drive the provider through its `use()` callback so no request is needed:

```php
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';
    private const ALICE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

    private function resolveWith(?string $customerId): ShoppingContext
    {
        $customer = null;
        if ($customerId !== null) {
            $customer = new CustomerEntity();
            $customer->setId($customerId);
        }

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getCustomer')->willReturn($customer);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::CHANNEL);

        $provider = new SalesChannelContextProvider(new RequestStack());

        return $provider->use(
            $salesChannelContext,
            static fn(): ShoppingContext => (new ShoppingContextResolver($provider))->current(),
        );
    }
```

Cases to write, each calling `$this->resolveWith(...)`:

```php
    public function testNoCustomerResolvesAsGuest(): void
    {
        $context = $this->resolveWith(null);

        self::assertSame(ShoppingMode::Guest, $context->mode);
        self::assertNull($context->customerId);
        self::assertSame(self::CHANNEL, $context->salesChannelId);
    }

    public function testALoggedInCustomerResolvesAsThatCustomer(): void
    {
        $context = $this->resolveWith(self::ALICE);

        self::assertSame(ShoppingMode::Customer, $context->mode);
        self::assertSame(self::ALICE, $context->customerId);
    }

    public function testTheCommercialFieldsAreNullBecauseNothingResolvesThemYet(): void
    {
        // Pinned so the Commercial bridge has to change this test deliberately rather than by
        // accident, and so nobody reads a null here as "not implemented" when it means "no B2B".
        $context = $this->resolveWith(self::ALICE);

        self::assertNull($context->employeeId);
        self::assertNull($context->organisationId);
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Context/ShoppingContextResolverTest.php
```

Expected: FAIL — the resolver does not exist.

- [ ] **Step 3: Write the implementation**

Create `src/Core/Context/ShoppingContextResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Context;

use Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider;

/**
 * Turns the request's sales-channel context into a {@see ShoppingContext}.
 *
 * The only class in this namespace that touches Shopware, and it does so through
 * {@see SalesChannelContextProvider} rather than a `RequestStack` of its own — that provider's
 * docblock claims to be the single place allowed to reach for the request context, and a second
 * reader would quietly make that false.
 *
 * Absence of Shopware Commercial is an ordinary supported state, not an error: a shop without it has
 * guests and customers, and both are fully served. The employee and organisation fields stay null
 * until the Commercial bridge exists.
 */
final readonly class ShoppingContextResolver
{
    public function __construct(
        private SalesChannelContextProvider $contexts,
    ) {}

    public function current(): ShoppingContext
    {
        $context = $this->contexts->current();
        $customerId = $context->getCustomer()?->getId();

        return new ShoppingContext(
            mode: $customerId === null ? ShoppingMode::Guest : ShoppingMode::Customer,
            salesChannelId: $context->getSalesChannelId(),
            customerId: $customerId,
        );
    }
}
```

Register it in `src/Resources/config/services.xml` beside the other `Core` services, with `SalesChannelContextProvider` as its single argument.

- [ ] **Step 4: Run the tests**

```bash
composer test
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Context src/Resources/config/services.xml tests/Core/Context
git commit -m "feat(context): resolve the shopping context from the storefront request"
```

---

### Task 3: An opaque storage key for the browser

The widget needs to keep one token per context without ever holding a customer id. The key is an HMAC over the canonical scope; it identifies a slot in `sessionStorage` and authorises nothing.

**Files:**
- Create: `src/Core/Context/ContextStorageKey.php`
- Modify: `src/Resources/config/services.xml` — register it with `%kernel.secret%`
- Test: `tests/Core/Context/ContextStorageKeyTest.php`

**Interfaces:**
- Consumes: `ShoppingContext` (Task 1).
- Produces: `ContextStorageKey::for(ShoppingContext $context): string` — 32 lowercase hex characters. Tasks 6 and 7 use it.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Context/ContextStorageKeyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Context;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Context\ContextStorageKey;
use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;

/**
 * The key reaches the browser, so the one thing it must never do is carry an identity. It is a slot
 * name, not a credential — the server re-validates the conversation token against the real context
 * on every request regardless of which slot it came from.
 */
final class ContextStorageKeyTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';
    private const ALICE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const BOB = 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

    private function keys(): ContextStorageKey
    {
        return new ContextStorageKey('test-kernel-secret');
    }

    private function customer(string $id): ShoppingContext
    {
        return new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, $id);
    }

    public function testTheSameScopeAlwaysProducesTheSameKey(): void
    {
        // Otherwise a shopper loses their conversation on every page load.
        self::assertSame($this->keys()->for($this->customer(self::ALICE)), $this->keys()->for($this->customer(self::ALICE)));
    }

    public function testTwoCustomersGetDifferentSlots(): void
    {
        self::assertNotSame($this->keys()->for($this->customer(self::ALICE)), $this->keys()->for($this->customer(self::BOB)));
    }

    public function testGuestAndCustomerGetDifferentSlots(): void
    {
        // This is what makes logout leave the authenticated conversation untouched instead of
        // overwriting it with the guest one.
        $guest = new ShoppingContext(ShoppingMode::Guest, self::CHANNEL);

        self::assertNotSame($this->keys()->for($guest), $this->keys()->for($this->customer(self::ALICE)));
    }

    public function testTheKeyContainsNoIdentityInPlainText(): void
    {
        $key = $this->keys()->for($this->customer(self::ALICE));

        self::assertStringNotContainsString(self::ALICE, $key);
        self::assertStringNotContainsString(self::CHANNEL, $key);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
    }

    public function testADifferentSecretProducesADifferentKey(): void
    {
        // Two shops sharing a browser profile — a staging and a live domain, say — must not share
        // conversation slots.
        self::assertNotSame(
            (new ContextStorageKey('one-secret'))->for($this->customer(self::ALICE)),
            (new ContextStorageKey('another-secret'))->for($this->customer(self::ALICE)),
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Context/ContextStorageKeyTest.php
```

Expected: FAIL — the class does not exist.

- [ ] **Step 3: Write the implementation**

Create `src/Core/Context/ContextStorageKey.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Context;

/**
 * The `sessionStorage` slot a shopper's conversation token lives in.
 *
 * **A slot name, never a credential.** It reaches the browser, so it carries no customer id, no
 * organisation id and nothing reversible: it is an HMAC over the canonical scope using the shop's
 * kernel secret. A shopper who edits it selects a different slot and gets a token the server then
 * refuses, because the token is validated against the *actual* request context and not against
 * whatever key it arrived beside.
 *
 * Keyed by the kernel secret rather than plain hashing so that two shops sharing a browser profile —
 * a staging and a live domain on the same host, say — cannot collide, and so the key cannot be
 * recomputed off-site from a guessed customer id.
 *
 * Truncated to 128 bits: this names one of a handful of slots in one browser session, and a full
 * SHA-256 in a storage key is noise.
 */
final readonly class ContextStorageKey
{
    public function __construct(
        #[\SensitiveParameter]
        private string $secret,
    ) {}

    public function for(ShoppingContext $context): string
    {
        // Field-separated rather than concatenated: without the separator a customer id ending in
        // the channel id's first characters could canonicalise to the same string as a different
        // pairing, and two scopes sharing a slot is the one failure this class must not have.
        $canonical = implode("\0", [
            $context->mode->value,
            $context->salesChannelId,
            $context->customerId ?? '',
            $context->employeeId ?? '',
            $context->organisationId ?? '',
        ]);

        return substr(hash_hmac('sha256', $canonical, $this->secret), 0, 32);
    }
}
```

Register it in `services.xml` with `%kernel.secret%` as its argument:

```xml
        <service id="Swag\AssistantStarterKit\Core\Context\ContextStorageKey">
            <argument>%kernel.secret%</argument>
        </service>
```

- [ ] **Step 4: Run the tests**

```bash
composer test
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Context src/Resources/config/services.xml tests/Core/Context
git commit -m "feat(context): an opaque per-scope storage key for the widget"
```

---

### Task 4: The conversation row remembers whose it is

**Files:**
- Create: `src/Migration/Migration1788307200AddConversationScope.php`
- Modify: `src/Entity/Conversation/ConversationDefinition.php` — three fields
- Modify: `src/Entity/Conversation/ConversationEntity.php` — three properties with getters and setters
- Test: `tests/Migration/AddConversationScopeTest.php`

**Interfaces:**
- Consumes: `ShoppingMode`'s backed values, which are what `scope_type` stores.
- Produces: columns `scope_type` (VARCHAR(16) NOT NULL), `commercial_employee_id` and `commercial_organisation_id` (both BINARY(16) NULL); entity properties `scopeType`, `commercialEmployeeId`, `commercialOrganisationId`. Task 5 writes and reads them.

**Read first:** `src/Migration/Migration1787356800AddConversationCustomerId.php`. It guards with `SHOW COLUMNS ... LIKE` before altering, and its `updateDestructive()` does nothing. Follow both.

**The backfill is the interesting part.** Existing rows have no scope. A row with a `customer_id` was a customer's; a row without one is indistinguishable from a guest's — including a row whose customer has since been deleted, because the foreign key is `ON DELETE SET NULL`. That ambiguity is bounded and historical: every row written after this migration keeps its scope type even if the customer is later deleted, which is exactly what stops a deleted customer's transcript becoming readable as a guest's.

- [ ] **Step 1: Write the failing test**

Create `tests/Migration/AddConversationScopeTest.php`, following the shape of `tests/Migration/MergeExcludedIntoBlockedCategoriesTest.php` — read it first for how this suite fakes a `Connection` without a database. Assert:

- the migration reports its creation timestamp as `1788307200`;
- `update()` issues no `ALTER` when `SHOW COLUMNS` already reports `scope_type`;
- `update()` issues an `ALTER` adding all three columns when it does not;
- the backfill sets `guest` where `customer_id IS NULL` and `customer` otherwise, and runs **after** the `ALTER`;
- `updateDestructive()` does nothing.

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit tests/Migration/AddConversationScopeTest.php
```

Expected: FAIL — the migration class does not exist.

- [ ] **Step 3: Write the migration**

Create `src/Migration/Migration1788307200AddConversationScope.php` extending `MigrationStep`, with a docblock covering the backfill reasoning above. The statements:

```php
        $connection->executeStatement('ALTER TABLE `swag_assistant_conversation`
                ADD `scope_type` VARCHAR(16) NOT NULL DEFAULT \'guest\' AFTER `customer_id`,
                ADD `commercial_employee_id` BINARY(16) NULL AFTER `scope_type`,
                ADD `commercial_organisation_id` BINARY(16) NULL AFTER `commercial_employee_id`');

        // Rows written before this migration carry no scope. One with a customer was a customer's;
        // one without is indistinguishable from a guest's, including a row whose customer has since
        // been deleted — the foreign key is ON DELETE SET NULL. Preserving existing guest
        // rehydration accepts that bounded, historical ambiguity. Every row written from now on
        // keeps its scope type when its customer is deleted, which is what stops that transcript
        // becoming readable as a guest's.
        $connection->executeStatement(
            'UPDATE `swag_assistant_conversation` SET `scope_type` = \'customer\' WHERE `customer_id` IS NOT NULL',
        );
```

**No foreign keys** on the two commercial columns: they reference tables that belong to an optional extension which may not be installed.

- [ ] **Step 4: Add the entity fields**

In `ConversationDefinition::defineFields()`, after the `customer_id` field:

```php
            (new StringField('scope_type', 'scopeType'))->addFlags(new Required()),
            // No FkField and no association: these reference Shopware Commercial's tables, which
            // exist only when that optional extension is installed. Stored as plain ids.
            new IdField('commercial_employee_id', 'commercialEmployeeId'),
            new IdField('commercial_organisation_id', 'commercialOrganisationId'),
```

In `ConversationEntity`, add the three properties beside `$customerId` with getters and setters matching the file's existing style. Default `$scopeType` to `ShoppingMode::Guest->value` so an entity built in a test is valid.

- [ ] **Step 5: Run the tests**

```bash
composer test && composer quality:filesize
```

Expected: PASS. `tests/PluginManifestTest.php` and `tests/Migration/MigrationDirectoryTest.php` both inspect this directory — if either complains about the timestamp or the class name, fix the migration rather than the test.

- [ ] **Step 6: Commit**

```bash
git add src/Migration src/Entity/Conversation tests/Migration
git commit -m "feat(conversation): record which shopper a conversation belongs to"
```

---

### Task 5: The store refuses to read or append across scopes

**Files:**
- Modify: `src/Core/Trace/ConversationStore.php` — three signatures
- Modify: `src/Core/Trace/DalConversationStore.php` — persist and check the scope
- Create: `src/Core/Trace/ForeignConversationException.php`
- Test: `tests/Core/Trace/ConversationScopeTest.php`

**Interfaces:**
- Consumes: `ShoppingContext`, `ShoppingMode` (Task 1); the entity fields (Task 4).
- Produces:
  - `start(ShoppingContext $context, string $locale): string`
  - `history(#[\SensitiveParameter] string $token, ShoppingContext $context, int $limit = 20): array`
  - `append(#[\SensitiveParameter] string $token, ShoppingContext $context, ConversationTurn $turn, TraceRecorder $trace): void`
  - `traceEvents()` is **unchanged** — merchant trace access is ACL-controlled and must not be scope-checked.

  Task 6 calls all three. `tests/Core/Trace/InMemoryConversationStore.php` implements this interface and must be updated in the same task.

**Ruling recorded here so the implementer does not have to make it:** `history()` on a scope mismatch returns `[]` — the same answer as an unknown token, revealing no reason. `append()` on a mismatch **throws** `ForeignConversationException`. The asymmetry is deliberate: history is a shopper-facing read where silence is the correct answer, while an append that reaches a foreign row can only mean the controller skipped its own validation, and a silent no-op there would drop a shopper's turn invisibly. The controller validates first, so the throw is a backstop that fails loudly for a developer rather than quietly for a shopper.

- [ ] **Step 1: Write the failing test**

**Two things exist that you must use rather than reinvent:**

- `tests/Core/Trace/ConversationTotalMsTest.php` already exercises the real `DalConversationStore` against repository doubles. Copy its setup; do not build a new one.
- `tests/Core/Trace/InMemoryConversationStore.php` **implements `ConversationStore`** and is used by `AssistantEndpointTestCase`. The interface change breaks it. Update it in this task to the new signatures, storing the scope alongside each transcript and applying the same match rule, or every controller test fails for the wrong reason.

Create `tests/Core/Trace/ConversationScopeTest.php`:

```php
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';
    private const ALICE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const BOB = 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

    private function guest(): ShoppingContext
    {
        return new ShoppingContext(ShoppingMode::Guest, self::CHANNEL);
    }

    private function customer(string $id): ShoppingContext
    {
        return new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, $id);
    }

    public function testAConversationStartedByOneCustomerIsInvisibleToAnother(): void
    {
        // The defect this whole plan exists to close.
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');
        $store->append($token, $this->customer(self::ALICE), $this->turn('the blue one'), new TraceRecorder());

        self::assertSame([], $store->history($token, $this->customer(self::BOB)));
    }

    public function testTheOwnerReadsTheirOwnTranscript(): void
    {
        // The other half: scoping that also hides a conversation from its owner is not a fix.
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');
        $store->append($token, $this->customer(self::ALICE), $this->turn('the blue one'), new TraceRecorder());

        self::assertCount(1, $store->history($token, $this->customer(self::ALICE)));
    }

    public function testAGuestTokenIsInvisibleAfterLoggingIn(): void
    {
        $store = $this->store();
        $token = $store->start($this->guest(), 'en-GB');
        $store->append($token, $this->guest(), $this->turn('anything'), new TraceRecorder());

        self::assertSame([], $store->history($token, $this->customer(self::ALICE)));
    }

    public function testACustomerTokenIsInvisibleAfterLoggingOut(): void
    {
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');
        $store->append($token, $this->customer(self::ALICE), $this->turn('anything'), new TraceRecorder());

        self::assertSame([], $store->history($token, $this->guest()));
    }

    public function testADeletedCustomersConversationDoesNotBecomeAGuestConversation(): void
    {
        // Spec 9.2: `customer_id` is ON DELETE SET NULL, so after the customer goes the row has a
        // null customer — but `scope_type` still says `customer`, and a guest must not inherit it.
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');
        $store->append($token, $this->customer(self::ALICE), $this->turn('anything'), new TraceRecorder());
        $this->simulateCustomerDeletion($token);

        self::assertSame([], $store->history($token, $this->guest()));
    }

    public function testAppendingToAForeignConversationThrows(): void
    {
        // A backstop, not a shopper-facing path: reaching it means the controller skipped its own
        // validation, and a silent no-op would drop a shopper's turn invisibly.
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');

        $this->expectException(ForeignConversationException::class);

        $store->append($token, $this->customer(self::BOB), $this->turn('anything'), new TraceRecorder());
    }

    public function testTraceEventsIgnoreScopeSoTheMerchantExportKeepsWorking(): void
    {
        // Administrative access is ACL-controlled and deliberately not scope-checked.
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');
        $store->append($token, $this->customer(self::ALICE), $this->turn('anything'), new TraceRecorder());

        self::assertNotSame([], $store->traceEvents($token));
    }
```

`store()`, `turn()` and `simulateCustomerDeletion()` are private helpers you write: the first mirrors `ConversationTotalMsTest`'s repository-double setup, the second builds a `ConversationTurn`, and the third rewrites the stored row's `customerId` to null while leaving `scopeType` as `customer` — which is exactly what Shopware's foreign key does.

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Trace/ConversationScopeTest.php
```

Expected: FAIL — the signatures take no context.

- [ ] **Step 3: Implement**

`start()` writes `scopeType`, `customerId`, `commercialEmployeeId` and `commercialOrganisationId` from the context.

`history()` loads the row and returns `[]` unless the stored scope matches the presented one. Build a `ShoppingContext` from the row and use `matches()` — do not compare fields inline, or the comparison drifts from Task 1's.

`append()` performs the same comparison and throws `ForeignConversationException` on mismatch, before any write.

Create `src/Core/Trace/ForeignConversationException.php` extending `\RuntimeException`, with a docblock saying it means the caller skipped validation and is not a shopper-facing condition. Do not put the token or any id in the message.

If `DalConversationStore` trips mago's complexity budget, extract the row-to-context mapping and the comparison into a small `ConversationScope` collaborator in the same namespace, following `CartCorrectionNote`'s precedent, and say so in the report.

- [ ] **Step 4: Run the tests**

```bash
composer test && composer quality:filesize
```

Expected: PASS. `AssistantController` will not compile until Task 6 — if the suite fails only there, that is expected; note it and continue. If you prefer a green tree at every commit, fold Task 6 into this commit and say so.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Trace tests/Core/Trace
git commit -m "feat(conversation): refuse to read or append across shopper scopes"
```

---

### Task 6: The controller resolves once and validates every turn

**Files:**
- Modify: `src/Controller/AssistantController.php:113-152` (chat) and `:229-247` (history)
- Modify: `src/Storefront/AssistantWidgetExtension.php` — a Twig function for the storage key
- Modify: `src/Resources/views/storefront/component/assistant/panel.html.twig` — render the key
- Modify: `src/Resources/config/services.xml` — new constructor arguments
- Test: `tests/Controller/AssistantScopeEndpointTest.php`

**Interfaces:**
- Consumes: `ShoppingContextResolver::current()` (Task 2), `ContextStorageKey::for()` (Task 3), the scoped store signatures (Task 5).
- Produces: a `data-swag-assistant-context-key` attribute on the panel element carrying 32 hex characters. Task 7 reads it.

- [ ] **Step 1: Write the failing test**

Create `tests/Controller/AssistantScopeEndpointTest.php`, following `tests/Controller/AssistantHistoryEndpointTest.php`'s setup. Assert:

- `GET /assistant/history` with a token from another customer's conversation returns `{"messages": []}` and **no error field** — an unknown token and a foreign one must be indistinguishable from outside;
- `POST /assistant/chat` with a foreign token starts a **new** conversation rather than appending to the foreign one, and the response carries a different token than the one presented;
- a guest token presented after login returns no history.

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit tests/Controller/AssistantScopeEndpointTest.php
```

Expected: FAIL.

- [ ] **Step 3: Implement**

In `chat()`, resolve once at the top and pass the context to `start()`, `history()` and both `append()` calls. The token handling changes shape: a supplied token whose history comes back empty must be treated as unknown and a **new** conversation started, rather than appended to. Today's line is

```php
$token = $chat->token ?? $this->conversations->start(...);
```

which trusts the supplied token unconditionally. It becomes: resolve the context; if a token was supplied, read its history in that context; if that history is empty **and** a token was supplied, start a fresh conversation and use the new token from here on. Write the branch out explicitly rather than nesting ternaries — this is the security-relevant line of the endpoint and it should read like one.

In `history()`, pass the context to the store and leave the response shape alone.

In `AssistantWidgetExtension`, add `swag_assistant_context_key()` backed by the resolver and `ContextStorageKey`. It takes no arguments — the resolver reads the request. Render it in `panel.html.twig` as `data-swag-assistant-context-key="{{ swag_assistant_context_key() }}"` on the same element that already carries the widget's other data attributes.

- [ ] **Step 4: Run the tests**

```bash
composer test && composer quality:filesize
```

Expected: PASS, including `tests/Storefront/AssistantWidgetTemplateDataTest.php`.

- [ ] **Step 5: Commit**

```bash
git add src/Controller src/Storefront src/Resources tests/Controller
git commit -m "feat(assistant): validate every conversation against the current shopper"
```

---

### Task 7: One token per context in the browser

**Files:**
- Modify: `src/Resources/app/storefront/src/assistant/panel.plugin.js:11,316,427,463,483,596`
- Create: `src/Resources/app/storefront/src/assistant/token-store.js`
- Test: `tests/js/token-store.test.js`

**Interfaces:**
- Consumes: the `data-swag-assistant-context-key` attribute (Task 6).
- Produces: nothing later depends on it.

**Read first:** `panel.plugin.js` reads and writes `sessionStorage` at six places under one `TOKEN_KEY` constant. All six move behind the new module; the plugin should not touch `sessionStorage` directly afterwards.

- [ ] **Step 1: Write the failing test**

Create `tests/js/token-store.test.js`. The module is pure apart from the storage object, so inject it — `createTokenStore(storage, contextKey)` taking anything with `getItem`/`setItem`/`removeItem`. Use a plain object-backed fake in the test, which also covers the private-window case where the real one throws. Cases:

```js
test('a token stored for one context is not visible from another', ...)
test('reset clears only the current context slot', ...)
test('a legacy single token is adopted once into the current slot', ...)
test('a storage that throws degrades to no token rather than breaking the widget', ...)
test('a corrupt map value is discarded rather than parsed into nonsense', ...)
```

- [ ] **Step 2: Run it to verify it fails**

```bash
npm run test:js
```

Expected: FAIL — the module does not exist.

- [ ] **Step 3: Implement**

Create `token-store.js` exporting `createTokenStore(storage, contextKey)` with `get()`, `set(token)` and `clear()`. It keeps a JSON object under a single `swagAssistantTokens` key, mapping context key to token. Every read and write is wrapped in try/catch — a private window throws on access, and a widget that breaks the page it is embedded in is worse than a widget with no memory.

Migrate the legacy value once: if `swagAssistantToken` holds a string and the current slot is empty, adopt it into the slot and remove the old key. The server re-validates it against the real context, so an incorrectly scoped legacy token is harmless — it simply yields no history and a fresh conversation.

In `panel.plugin.js`, build the store once from the panel element's `data-swag-assistant-context-key` and replace all six `sessionStorage` touches with it. `_reset()` calls `clear()`, which must leave other contexts' slots intact.

- [ ] **Step 4: Run both suites and rebuild the bundle**

```bash
npm run test:js && composer test && composer build:storefront
git status --short src/Resources/app/storefront/dist
```

Expected: JS and PHP suites pass and the dist bundle shows as modified. If `shopware-cli` is unavailable, stop and report rather than committing a stale bundle.

- [ ] **Step 5: Commit**

```bash
git add src/Resources/app/storefront tests/js/token-store.test.js
git commit -m "feat(widget): keep one conversation token per shopping context"
```

---

### Task 8: Live proof, docs, and the full gate

**Files:**
- Modify: `tests/e2e/widget.spec.js` — one new case
- Modify: `ARCHITECTURE.md` — the conversation table and the store's signatures
- Modify: `docs/superpowers/specs/2026-08-27-b2b-commercial-context-design.md` — mark the delivered sections

- [ ] **Step 1: Prove it against the running shop**

The demo shop at `http://127.0.0.1:8000` mounts this repository, so a cache clear picks the change up:

```bash
docker exec shopping-assistant-test-web-1 sh -lc 'php bin/console cache:clear && php bin/console database:migrate --all SwagAssistantStarterKit'
```

Then, with no model call at all:

```bash
curl -s "http://127.0.0.1:8000/assistant/history?token=00000000000000000000000000000000"
```

Expected: `{"messages":[]}`. Then check the page carries the key:

```bash
curl -sL "http://127.0.0.1:8000/" | grep -o 'data-swag-assistant-context-key="[0-9a-f]*"' | head -1
```

Expected: a 32-hex value. Record both outputs in your report.

- [ ] **Step 2: Add the browser case**

In `tests/e2e/widget.spec.js`, add a case that opens the widget, sends one turn, reads `sessionStorage.swagAssistantTokens`, and asserts it is an object with exactly one key matching `/^[0-9a-f]{32}$/`. This fires one real model call — the file already documents that cost and its timeouts; follow its conventions.

- [ ] **Step 3: Run everything**

```bash
composer test && composer quality && npm run test:js && \
  SHOP_URL=http://127.0.0.1:8000 npx playwright test tests/e2e/pricing.spec.js
```

Expected: PASS throughout.

- [ ] **Step 4: Update the architecture record**

In `ARCHITECTURE.md`, locate the conversation-table description **by content, not line number**, and add the three columns with one clause each on why the commercial pair has no foreign key. Update the `ConversationStore` signatures if the document lists them.

- [ ] **Step 5: Mark the spec sections delivered**

In the spec, prefix sections 9.1, 9.2 and 9.3 with `**Done 2026-08-29 (core paths; Commercial columns present but never populated).**` and add the same note to 5.1 and 5.2. Do **not** mark section 8 or 10 — they are Commercial work.

- [ ] **Step 6: Commit**

```bash
git add tests/e2e ARCHITECTURE.md docs/superpowers/specs
git commit -m "docs: record conversation scoping as shipped"
```

---

## Out of scope for this plan, on purpose

- The Commercial bridge, employee and organisation **resolution**, the context label, and catalogue enforcement. Blocked on a B2B licence: the demo instance's `commercial:feature:list` shows B2B is not in its plan, so none of it can be verified. The columns and the comparison land here so the bridge is additive.
- The `commercial` and `commercial_unavailable` modes. An enum case nothing can produce is a branch nothing can test.
- Merging a guest transcript into the account on login. Spec decision B5: attaching a guest's conversation to whoever logs in next is unsafe on a shared device.
- Cross-device conversation restoration. `sessionStorage` is per browser session by design.
- Deleting conversations a shopper can no longer reach. `PruneConversationsTask` already bounds retention.
