# Trace Attribution and Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The conversation list says who each conversation belonged to, and a merchant can take conversations out of the admin — as a CSV to count with, or as JSON to hand to a developer.

**Architecture:** A nullable `customer_id` foreign key with `ON DELETE SET NULL`, captured once when a conversation starts. Two pure serialisers turn conversations-with-events into bytes. One `api`-scoped controller owns policy — privilege, size bound, id resolution — and hands off to a serialiser. The admin list gains selection and two export buttons; the detail page gains one.

**Tech Stack:** PHP 8.2, Shopware 6.7 (DAL, migrations, admin API), PHPUnit, Mago, Vue 2 admin components, `node:test` for pure JS.

**Spec:** `docs/superpowers/specs/2026-08-24-trace-attribution-and-export-design.md` — read it first. Its decision table (T1–T8) is the argument for everything below, and the two decisions most likely to be "corrected" by a later reader (T3's two states, T4's personal data) are recorded there with their costs.

## Global Constraints

- PHP **8.2+**; every new PHP file starts with `declare(strict_types=1);`
- `composer run quality` must exit **0**; `vendor/bin/phpunit --exclude-group eval` must stay green; `npm run test:js` must stay green
- **Never run `phpunit tests/Eval/` without `--exclude-group eval`** — a local `.env` is loaded by `tests/bootstrap.php`, so on a credentialed machine it fires every journey at a real model
- New parameters and columns are **optional / nullable with a default**, so existing call sites, test doubles and installed shops keep working
- `src/**` files stay under **400 lines** (`scripts/check_file_length.php`), and mago's per-class complexity and method-count ceilings apply — when a class is at its limit, extract rather than suppress
- The export contains customer names (T4). Every route that produces one declares `swag_assistant_conversation:read`

## File Structure

**Created**
- `src/Migration/Migration1787356800AddConversationCustomerId.php` — the column and its foreign key
- `src/Core/Trace/Export/TraceExportRow.php` — one conversation flattened to the numbers CSV needs
- `src/Core/Trace/Export/TraceCsvSerialiser.php`
- `src/Core/Trace/Export/TraceJsonSerialiser.php`
- `src/Core/Trace/Export/TraceExportSource.php` — the seam that keeps the controller testable
- `src/Core/Trace/Export/DalTraceExportSource.php`
- `src/Controller/AssistantTraceExportController.php`
- `tests/Core/Trace/Export/TraceExportRowTest.php`
- `tests/Core/Trace/Export/TraceCsvSerialiserTest.php`
- `tests/Core/Trace/Export/TraceJsonSerialiserTest.php`
- `tests/Controller/AssistantTraceExportControllerTest.php`
- `tests/Controller/FakeTraceExportSource.php`

**Modified**
- `src/Entity/Conversation/ConversationDefinition.php` — `FkField` + `ManyToOneAssociationField`
- `src/Entity/Conversation/ConversationEntity.php` — `$customerId`, `$customer`
- `src/Core/Trace/ConversationStore.php` — `start()` gains `?string $customerId = null`
- `src/Core/Trace/DalConversationStore.php` — writes it
- `tests/Core/Trace/InMemoryConversationStore.php` — records it so a controller test can assert it
- `src/Controller/AssistantController.php` — reads the customer off the context
- `src/Resources/config/services.xml` — the new controller and the two serialisers
- `src/Resources/app/administration/.../swag-assistant-trace-list/index.js` + `.html.twig` — user column, selection, two export buttons
- `src/Resources/app/administration/.../swag-assistant-trace-detail/index.js` + `.html.twig` — one export button
- `src/Resources/app/administration/.../snippet/en-GB.json`, `de-DE.json`
- `ARCHITECTURE.md`, `README.md`

---

### Task 1: The column, and the link the database severs

**Files:**
- Create: `src/Migration/Migration1787356800AddConversationCustomerId.php`
- Modify: `src/Entity/Conversation/ConversationDefinition.php`
- Modify: `src/Entity/Conversation/ConversationEntity.php`

**Interfaces:**
- Consumes: nothing
- Produces: `ConversationEntity::getCustomerId(): ?string`, `getCustomer(): ?CustomerEntity`; DAL association name `customer`

> **No unit test.** A DAL definition and a migration are declarations, not logic; this repo has no integration harness and `TraceEventApiExposureTest` is the only place that touches a definition, for API exposure rather than behaviour. Task 2 is where behaviour starts and where tests resume. Verification here is `composer run quality` (mago analyzes the definition) plus the live check in Step 4.

- [ ] **Step 1: Write the migration**

Create `src/Migration/Migration1787356800AddConversationCustomerId.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds `swag_assistant_conversation.customer_id`, and the constraint that makes it forget.
 *
 * **`ON DELETE SET NULL` is the feature, not a detail** (spec T1). When a customer deletes their
 * account the database severs the link itself: no erasure routine over this table, nothing for
 * anyone to forget to write, and no pseudonymous identifier left pointing at a transcript. The
 * conversation survives — a trace is a record of what the shop did, and losing it because a
 * customer left would destroy the merchant's own history. `ON DELETE CASCADE` would do exactly
 * that, which is why it is not used here.
 *
 * The consequence is deliberate and recorded as spec T3: a deleted customer's conversation becomes
 * indistinguishable from a guest's. That is the trade — the distinction could only be kept by
 * keeping a marker about someone who asked to be forgotten.
 */
class Migration1787356800AddConversationCustomerId extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1787356800;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, as in
     *         {@see Migration1787270400AddTraceEventElapsedMs}: a schema change that cannot apply
     *         must fail loudly rather than leave a plugin that boots and then errors on first use.
     */
    public function update(Connection $connection): void
    {
        $existing = $connection->fetchFirstColumn('SHOW COLUMNS FROM `swag_assistant_conversation` LIKE :column', [
            'column' => 'customer_id',
        ]);

        if ($existing !== []) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `swag_assistant_conversation`
                ADD `customer_id` BINARY(16) NULL AFTER `sales_channel_id`,
                ADD CONSTRAINT `fk.swag_assistant_conversation.customer_id`
                    FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE',
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive: the column is additive and every existing row reads as a guest.
    }
}
```

- [ ] **Step 2: Declare the field and the association**

In `src/Entity/Conversation/ConversationDefinition.php`, add after the `sales_channel_id` line:

```php
            // A real foreign key, unlike `sales_channel_id` above — see that column's comment in
            // the list component for why it is a plain string and cannot be joined. This one can,
            // which is what lets the list and the export read the customer's name through the
            // association instead of a client-side id map.
            new FkField('customer_id', 'customerId', CustomerDefinition::class),
```

and, beside the existing `events` association:

```php
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id', false),
```

Imports: `Shopware\Core\Checkout\Customer\CustomerDefinition`,
`Shopware\Core\Framework\DataAbstractionLayer\Field\FkField`,
`Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField`.

- [ ] **Step 3: Carry it on the entity**

In `src/Entity/Conversation/ConversationEntity.php`, add the properties beside `$salesChannelId`:

```php
    protected ?string $customerId = null;

    protected ?CustomerEntity $customer = null;
```

and the four accessors, in the shape the file already uses:

```php
    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }
```

Import `Shopware\Core\Checkout\Customer\CustomerEntity`.

- [ ] **Step 4: Verify**

Run: `vendor/bin/phpunit --exclude-group eval && composer run quality`
Expected: green, exit 0.

Then against the running test shop, confirm the migration applies and the constraint is real:

```bash
cd ../shopping-assistant-test
docker compose exec -T web sh -lc 'cd /var/www/html && php bin/console database:migrate --all SwagAssistantStarterKit'
docker compose exec -T database mariadb -uroot -proot shopware -e \
  "SHOW CREATE TABLE swag_assistant_conversation\G" | grep -i "customer_id\|FOREIGN KEY"
```

Expected: the column, and a `FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE SET NULL`.

- [ ] **Step 5: Commit**

```bash
git add src/Migration src/Entity
git commit -m "feat(trace): record which customer a conversation belonged to"
```

---

### Task 2: Capture it when the conversation starts

**Files:**
- Modify: `src/Core/Trace/ConversationStore.php`
- Modify: `src/Core/Trace/DalConversationStore.php`
- Modify: `tests/Core/Trace/InMemoryConversationStore.php`
- Modify: `src/Controller/AssistantController.php`
- Test: `tests/Controller/AssistantChatValidationTest.php` (extend)

**Interfaces:**
- Consumes: `ConversationEntity::$customerId` (Task 1)
- Produces: `ConversationStore::start(string $salesChannelId, string $locale, ?string $customerId = null): string`; `InMemoryConversationStore::$lastCustomerId`

- [ ] **Step 1: Write the failing test**

The double must be able to record it first. In `tests/Core/Trace/InMemoryConversationStore.php`:

```php
    /** The customer the last `start()` was given, so a controller test can assert what it passed. */
    public ?string $lastCustomerId = null;

    public function start(string $salesChannelId, string $locale, ?string $customerId = null): string
    {
        $this->lastCustomerId = $customerId;
```

**Widen the interface in this task, before touching the double** — an implementation may not declare
fewer parameters than its interface, and `mago analyze` additionally requires the implementation to
declare them. In `src/Core/Trace/ConversationStore.php`:

```php
    /**
     * Opens a conversation and returns the token the widget keeps in `sessionStorage`.
     *
     * `$customerId` is the shopper if they are logged in, null if they are a guest. It is recorded
     * **once, here** (spec T2): a guest who logs in mid-conversation stays a guest on it, because
     * the column answers "who produced this trace" and re-attributing would make it answer "who was
     * last seen", which is a different and less useful question.
     */
    public function start(string $salesChannelId, string $locale, ?string $customerId = null): string;
```

Then append to `tests/Controller/AssistantChatValidationTest.php`:

```php
    public function testALoggedInShopperIsRecordedOnTheConversation(): void
    {
        $this->controller()->chat($this->post(['message' => 'hi']), $this->context(self::CUSTOMER));

        self::assertSame(self::CUSTOMER, $this->store->lastCustomerId);
    }

    public function testAGuestIsRecordedAsNoCustomerRatherThanAsAnEmptyString(): void
    {
        // Null, not '': the column is a foreign key, and an empty string is not a customer that
        // does not exist — it is a customer id that will never resolve.
        $this->controller()->chat($this->post(['message' => 'hi']), $this->context());

        self::assertNull($this->store->lastCustomerId);
    }
```

`AssistantEndpointTestCase::context()` currently takes no arguments. Give it an optional customer:

```php
    protected const CUSTOMER = '01a01b4f9e2270a1b2c3d4e5f6a7b8c9';

    protected function context(?string $customerId = null): SalesChannelContext
    {
        $customer = null;

        if ($customerId !== null) {
            $customer = new CustomerEntity();
            $customer->setId($customerId);
        }

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::CHANNEL);
        $context->method('getLanguageId')->willReturn('2fbb5fe2e29a4d70aa5854ce7ce3e20b');
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }
```

Import `Shopware\Core\Checkout\Customer\CustomerEntity`. The existing two `method()` lines stay
exactly as they are — only the customer is new, and every current caller passes no argument and
still gets a guest.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Controller/AssistantChatValidationTest.php`
Expected: FAIL — `lastCustomerId` stays null for the logged-in case, because nothing reads the customer yet.

- [ ] **Step 3: Write it in the DAL store**

In `src/Core/Trace/DalConversationStore.php`:

```php
    public function start(string $salesChannelId, string $locale, ?string $customerId = null): string
    {
        $id = Uuid::randomHex();

        $this->conversationRepository->create([[
            'id' => $id,
            'salesChannelId' => $salesChannelId,
            'customerId' => $customerId,
            'locale' => $locale,
            'turnCount' => 0,
            'outcome' => '',
            'totalMs' => 0,
            'transcript' => [],
        ]], Context::createDefaultContext());

        return $id;
    }
```

- [ ] **Step 4: Read the customer in the controller**

In `src/Controller/AssistantController.php`, replace the `start()` call:

```php
        $token = $chat->token ?? $this->conversations->start(
            $salesChannelId,
            $context->getLanguageId(),
            // Recorded once, on the turn that opens the conversation (spec T2). A conversation
            // resumed by token never re-reads this, so logging in mid-conversation does not
            // rewrite who it belonged to.
            $context->getCustomer()?->getId(),
        );
```

- [ ] **Step 5: Run it to verify it passes**

Run: `vendor/bin/phpunit --exclude-group eval && composer run quality`
Expected: PASS, exit 0.

- [ ] **Step 6: Commit**

```bash
git add src/Core/Trace src/Controller tests/Controller tests/Core/Trace
git commit -m "feat(trace): attribute a conversation to the shopper who started it"
```

---

### Task 3: One conversation, flattened to the numbers a merchant counts

**Files:**
- Create: `src/Core/Trace/Export/TraceExportRow.php`
- Test: `tests/Core/Trace/Export/TraceExportRowTest.php`

**Interfaces:**
- Consumes: `ConversationEntity`, `TraceEventEntity`
- Produces: `TraceExportRow::of(ConversationEntity $conversation, string $salesChannelName): array{id: string, createdAt: string, salesChannel: string, user: string, turns: int, outcome: string, totalMs: int, shopMs: int, modelMs: int, toolCalls: int}`

**Why its own class:** both serialisers and the controller would otherwise each derive the shop/model
split, and three derivations of one number is how the file and the screen come to disagree. It is
also the only piece of the export with real logic, so it is the piece that gets its own test.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Trace/Export/TraceExportRowTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Export;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceExportRow;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventCollection;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

/**
 * The numbers in an exported file have to be the numbers on the screen. This is the one place they
 * are derived, so it is the one place that can make them disagree.
 */
final class TraceExportRowTest extends TestCase
{
    public function testTheShopKeepsWhatItSpentAndTheModelTheRest(): void
    {
        // A real turn's offsets: the shop works up to 22ms, the model thinks until 3634, the shop
        // finishes at 3656. Shop time is the sum of what happened between events; model time is
        // the gap nothing was recorded in.
        $row = TraceExportRow::of(self::conversation(events: [
            self::event(0, 'facet.probe'),
            self::event(22, 'prompt'),
            self::event(3634, 'validate'),
            self::event(3656, 'turn.end'),
        ], totalMs: 3656), 'Storefront');

        self::assertSame(3656, $row['totalMs']);
        self::assertSame(3612, $row['modelMs'], 'the gap between prompt and validate is the model');
        self::assertSame(44, $row['shopMs'], 'everything else is the shop');
    }

    public function testToolCallsAreCounted(): void
    {
        $row = TraceExportRow::of(self::conversation(events: [
            self::event(10, 'tool.call'),
            self::event(20, 'retrieve'),
            self::event(30, 'tool.call'),
        ]), 'Storefront');

        self::assertSame(2, $row['toolCalls']);
    }

    public function testALoggedInShopperIsNamed(): void
    {
        $customer = new CustomerEntity();
        $customer->setId('01a01b4f9e2270a1b2c3d4e5f6a7b8c9');
        $customer->setFirstName('Anna');
        $customer->setLastName('Schmidt');

        $row = TraceExportRow::of(self::conversation(customer: $customer), 'Storefront');

        self::assertSame('Anna Schmidt', $row['user']);
    }

    /**
     * Spec T3: with `ON DELETE SET NULL` there is no third state. A conversation whose customer was
     * deleted has no id left, so "Guest user" is not a fallback — it is the only true thing to say.
     */
    public function testNoCustomerReadsAsGuest(): void
    {
        self::assertSame('Guest user', TraceExportRow::of(self::conversation(), 'Storefront')['user']);
    }

    public function testAnEmptyTraceStillProducesARow(): void
    {
        // A conversation whose turn failed before any event was recorded is exactly the row a
        // merchant is looking for. It must not be the row that throws.
        $row = TraceExportRow::of(self::conversation(events: []), 'Storefront');

        self::assertSame(0, $row['shopMs']);
        self::assertSame(0, $row['modelMs']);
        self::assertSame(0, $row['toolCalls']);
    }

    /** @param list<TraceEventEntity> $events */
    private static function conversation(
        array $events = [],
        int $totalMs = 0,
        ?CustomerEntity $customer = null,
    ): ConversationEntity {
        $conversation = new ConversationEntity();
        $conversation->setId('01a0337f413070afa3b29711739324a2');
        $conversation->setSalesChannelId('01a01b4af6567284ac9eeb3616598ac3');
        $conversation->setTurnCount(1);
        $conversation->setOutcome('product_shown');
        $conversation->setTotalMs($totalMs);
        $conversation->setCreatedAt(new \DateTimeImmutable('2026-08-24T10:12:04+00:00'));
        $conversation->setEvents(new TraceEventCollection($events));

        if ($customer !== null) {
            $conversation->setCustomerId($customer->getId());
            $conversation->setCustomer($customer);
        }

        return $conversation;
    }

    private static function event(int $elapsedMs, string $stage): TraceEventEntity
    {
        $event = new TraceEventEntity();
        $event->setId(bin2hex(random_bytes(16)));
        $event->setStage($stage);
        $event->setElapsedMs($elapsedMs);
        $event->setPayload([]);

        return $event;
    }
}
```

**Check `TraceEventEntity`'s setters before running** — the property names are `stage`, `payload`,
`elapsedMs`, `seq`; if `seq` is required by anything downstream, set it in `event()` too.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Trace/Export/TraceExportRowTest.php`
Expected: FAIL — `TraceExportRow` does not exist.

- [ ] **Step 3: Write the implementation**

Create `src/Core/Trace/Export/TraceExportRow.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Export;

use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

/**
 * One conversation as the flat row a merchant counts with.
 *
 * **The shop/model split is derived here and only here.** The Administration derives the same two
 * numbers in `phases.js`, and the rule is identical: nothing is recorded while the model is
 * thinking, so the model's time is the *gaps between* events and the shop's is everything else. A
 * second derivation somewhere else is how a number in a file comes to disagree with the number on
 * the screen, which is the failure this class exists to prevent.
 */
final class TraceExportRow
{
    /**
     * Below this a gap is scheduling noise rather than a round trip — the same threshold
     * `phases.js` uses, and for the same measured reason: the largest within-phase gap on a real
     * turn was 17ms.
     */
    private const WAIT_THRESHOLD_MS = 250;

    private function __construct() {}

    /**
     * @return array{id: string, createdAt: string, salesChannel: string, user: string, turns: int,
     *               outcome: string, totalMs: int, shopMs: int, modelMs: int, toolCalls: int}
     */
    public static function of(ConversationEntity $conversation, string $salesChannelName): array
    {
        $events = array_values($conversation->getEvents()?->getElements() ?? []);
        usort($events, static fn(TraceEventEntity $a, TraceEventEntity $b): int => $a->getElapsedMs() <=> $b->getElapsedMs());

        $modelMs = 0;
        $toolCalls = 0;
        $previous = 0;

        foreach ($events as $event) {
            $gap = $event->getElapsedMs() - $previous;

            if ($gap >= self::WAIT_THRESHOLD_MS) {
                $modelMs += $gap;
            }

            $previous = $event->getElapsedMs();

            if ($event->getStage() === 'tool.call') {
                ++$toolCalls;
            }
        }

        $totalMs = $conversation->getTotalMs();

        return [
            'id' => $conversation->getId(),
            'createdAt' => $conversation->getCreatedAt()?->format(\DateTimeInterface::ATOM) ?? '',
            'salesChannel' => $salesChannelName,
            'user' => self::user($conversation),
            'turns' => $conversation->getTurnCount(),
            'outcome' => (string) $conversation->getOutcome(),
            'totalMs' => $totalMs,
            'modelMs' => $modelMs,
            // What is left, floored at zero: `total_ms` and the event offsets are written by
            // different code paths, and a negative "shop time" would be a arithmetic artefact
            // reported as a fact.
            'shopMs' => max(0, $totalMs - $modelMs),
            'toolCalls' => $toolCalls,
        ];
    }

    /**
     * Spec T3: two states, and the second is not a fallback. `ON DELETE SET NULL` removes the id
     * when a customer deletes their account, so a conversation with no customer genuinely has no
     * customer — whether it never had one or no longer does.
     */
    private static function user(ConversationEntity $conversation): string
    {
        $customer = $conversation->getCustomer();

        if ($customer === null) {
            return 'Guest user';
        }

        return trim($customer->getFirstName() . ' ' . $customer->getLastName());
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Trace/Export/TraceExportRowTest.php`
Expected: PASS.

If `testTheShopKeepsWhatItSpentAndTheModelTheRest` fails on the arithmetic, **fix the test's expected
numbers rather than the threshold** — recompute by hand from the offsets in the fixture and say what
you computed. The 250ms threshold is measured and shared with `phases.js`; changing it to make a
test pass would silently change what the Administration means by "waiting on the model".

- [ ] **Step 5: Commit**

```bash
git add src/Core/Trace/Export tests/Core/Trace/Export
git commit -m "feat(trace): flatten a conversation to the numbers a merchant counts"
```

---

### Task 4: The two serialisers

**Files:**
- Create: `src/Core/Trace/Export/TraceCsvSerialiser.php`
- Create: `src/Core/Trace/Export/TraceJsonSerialiser.php`
- Test: `tests/Core/Trace/Export/TraceCsvSerialiserTest.php`
- Test: `tests/Core/Trace/Export/TraceJsonSerialiserTest.php`

**Interfaces:**
- Consumes: `TraceExportRow::of()` (Task 3)
- Produces: `TraceCsvSerialiser::serialise(array $conversations, array $salesChannelNames): string`,
  `TraceJsonSerialiser::serialise(array $conversations, array $salesChannelNames): string`, where
  `$conversations` is `list<ConversationEntity>` and `$salesChannelNames` is `array<string, string>`

- [ ] **Step 1: Write the failing CSV test**

Create `tests/Core/Trace/Export/TraceCsvSerialiserTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Export;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceCsvSerialiser;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventCollection;

final class TraceCsvSerialiserTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testItStartsWithAHeaderSoTheFileOpensAsATable(): void
    {
        $csv = TraceCsvSerialiser::serialise([self::conversation()], [self::CHANNEL => 'Storefront']);

        self::assertStringStartsWith(
            "id,createdAt,salesChannel,user,turns,outcome,totalMs,shopMs,modelMs,toolCalls\n",
            $csv,
        );
    }

    /**
     * The one thing a hand-rolled CSV writer gets wrong. A name with a comma splits the row; a name
     * with a quote breaks the quoting. `fputcsv` handles both, which is why it is used rather than
     * `implode`.
     */
    public function testANameWithACommaAndAQuoteSurvives(): void
    {
        $customer = new CustomerEntity();
        $customer->setId('01a01b4f9e2270a1b2c3d4e5f6a7b8c9');
        $customer->setFirstName('Anna "Ann"');
        $customer->setLastName('Schmidt, Dr.');

        $csv = TraceCsvSerialiser::serialise(
            [self::conversation($customer)],
            [self::CHANNEL => 'Storefront'],
        );

        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        self::assertCount(2, $rows, 'the name must not have split the row');
        self::assertSame('Anna "Ann" Schmidt, Dr.', $rows[1][3]);
    }

    public function testAGuestAndACustomerSitInTheSameFile(): void
    {
        $customer = new CustomerEntity();
        $customer->setId('01a01b4f9e2270a1b2c3d4e5f6a7b8c9');
        $customer->setFirstName('Anna');
        $customer->setLastName('Schmidt');

        $csv = TraceCsvSerialiser::serialise(
            [self::conversation(), self::conversation($customer)],
            [self::CHANNEL => 'Storefront'],
        );

        self::assertStringContainsString('Guest user', $csv);
        self::assertStringContainsString('Anna Schmidt', $csv);
    }

    public function testAnUnknownSalesChannelFallsBackToItsIdRatherThanBlank(): void
    {
        // A blank cell reads as "no sales channel". The id reads as "one this export could not
        // name", which is true and traceable.
        $csv = TraceCsvSerialiser::serialise([self::conversation()], []);

        self::assertStringContainsString(self::CHANNEL, $csv);
    }

    private static function conversation(?CustomerEntity $customer = null): ConversationEntity
    {
        $conversation = new ConversationEntity();
        $conversation->setId('01a0337f413070afa3b29711739324a2');
        $conversation->setSalesChannelId(self::CHANNEL);
        $conversation->setTurnCount(1);
        $conversation->setOutcome('product_shown');
        $conversation->setTotalMs(3656);
        $conversation->setCreatedAt(new \DateTimeImmutable('2026-08-24T10:12:04+00:00'));
        $conversation->setEvents(new TraceEventCollection([]));

        if ($customer !== null) {
            $conversation->setCustomerId($customer->getId());
            $conversation->setCustomer($customer);
        }

        return $conversation;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Trace/Export/TraceCsvSerialiserTest.php`
Expected: FAIL — `TraceCsvSerialiser` does not exist.

- [ ] **Step 3: Write the CSV serialiser**

Create `src/Core/Trace/Export/TraceCsvSerialiser.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Export;

use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;

/**
 * Conversations as one row each, for counting.
 *
 * `fputcsv` over a memory stream rather than `implode(',')`: a customer's name is arbitrary text
 * that will one day contain a comma or a quote, and a hand-rolled writer turns that into a row that
 * silently has the wrong number of columns. Spec T4 put real names in this file, which is exactly
 * what makes the quoting load-bearing rather than theoretical.
 */
final class TraceCsvSerialiser
{
    private const HEADER = [
        'id',
        'createdAt',
        'salesChannel',
        'user',
        'turns',
        'outcome',
        'totalMs',
        'shopMs',
        'modelMs',
        'toolCalls',
    ];

    private function __construct() {}

    /**
     * @param list<ConversationEntity> $conversations
     * @param array<string, string>    $salesChannelNames id => name; an id not present falls back
     *                                                    to itself, because a blank cell would read
     *                                                    as "no sales channel"
     */
    public static function serialise(array $conversations, array $salesChannelNames): string
    {
        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a memory stream to write the export.');
        }

        fputcsv($handle, self::HEADER);

        foreach ($conversations as $conversation) {
            $channelId = $conversation->getSalesChannelId();
            $row = TraceExportRow::of($conversation, $salesChannelNames[$channelId] ?? $channelId);

            fputcsv($handle, array_values($row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
```

**`array_values($row)` relies on `TraceExportRow::of()` returning its keys in `self::HEADER`'s
order.** They are written in that order in Task 3; if you reorder either, reorder both, or the
column headings stop describing the columns.

- [ ] **Step 4: Write the failing JSON test**

Create `tests/Core/Trace/Export/TraceJsonSerialiserTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Export;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceJsonSerialiser;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventCollection;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

final class TraceJsonSerialiserTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testAPayloadSurvivesVerbatim(): void
    {
        // The whole point of the JSON format: whoever receives it must see what the pipeline
        // recorded, not a summary of it.
        $json = self::decode(TraceJsonSerialiser::serialise([self::conversation()], []));

        self::assertSame(
            ['reported' => true, 'resolved' => 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2'],
            $json[0]['events'][0]['payload'],
        );
    }

    public function testItCarriesTheSameSummaryTheCsvDoes(): void
    {
        // One derivation, two formats: a developer reading the JSON and a merchant reading the CSV
        // must not have to reconcile two different durations for the same conversation.
        $json = self::decode(TraceJsonSerialiser::serialise([self::conversation()], [self::CHANNEL => 'Storefront']));

        self::assertSame('Storefront', $json[0]['summary']['salesChannel']);
        self::assertSame('Guest user', $json[0]['summary']['user']);
    }

    public function testTheTranscriptIsIncluded(): void
    {
        $json = self::decode(TraceJsonSerialiser::serialise([self::conversation()], []));

        self::assertSame([['role' => 'user', 'prose' => 'is this in stock?']], $json[0]['transcript']);
    }

    public function testItIsPrettyPrintedBecausePeopleReadIt(): void
    {
        self::assertStringContainsString("\n    ", TraceJsonSerialiser::serialise([self::conversation()], []));
    }

    /** @return array<int, array<string, mixed>> */
    private static function decode(string $json): array
    {
        return json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
    }

    private static function conversation(): ConversationEntity
    {
        $event = new TraceEventEntity();
        $event->setId('11111111111111111111111111111111');
        $event->setStage('page.context');
        $event->setElapsedMs(16);
        $event->setPayload(['reported' => true, 'resolved' => 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2']);

        $conversation = new ConversationEntity();
        $conversation->setId('01a0337f413070afa3b29711739324a2');
        $conversation->setSalesChannelId(self::CHANNEL);
        $conversation->setTurnCount(1);
        $conversation->setOutcome('product_shown');
        $conversation->setTotalMs(3656);
        $conversation->setCreatedAt(new \DateTimeImmutable('2026-08-24T10:12:04+00:00'));
        $conversation->setTranscript([['role' => 'user', 'prose' => 'is this in stock?']]);
        $conversation->setEvents(new TraceEventCollection([$event]));

        return $conversation;
    }
}
```

- [ ] **Step 5: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Trace/Export/TraceJsonSerialiserTest.php`
Expected: FAIL — `TraceJsonSerialiser` does not exist.

- [ ] **Step 6: Write the JSON serialiser**

Create `src/Core/Trace/Export/TraceJsonSerialiser.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Export;

use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

/**
 * Conversations in full, for handing to whoever has to work out what happened.
 *
 * Every event payload is carried **unchanged**. A summarised export is a export that answers the
 * questions its author thought of, and the reason anyone asks for a trace is a question nobody
 * thought of.
 *
 * The same `summary` block the CSV rows are built from rides along, so the two formats cannot
 * disagree about a duration — see {@see TraceExportRow}.
 */
final class TraceJsonSerialiser
{
    private function __construct() {}

    /**
     * @param list<ConversationEntity> $conversations
     * @param array<string, string>    $salesChannelNames id => name
     */
    public static function serialise(array $conversations, array $salesChannelNames): string
    {
        $out = [];

        foreach ($conversations as $conversation) {
            $channelId = $conversation->getSalesChannelId();

            $out[] = [
                'summary' => TraceExportRow::of($conversation, $salesChannelNames[$channelId] ?? $channelId),
                'transcript' => $conversation->getTranscript() ?? [],
                'events' => self::events($conversation),
            ];
        }

        return json_encode($out, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array{seq: int, stage: string, elapsedMs: int, payload: array<string, mixed>}>
     */
    private static function events(ConversationEntity $conversation): array
    {
        $events = array_values($conversation->getEvents()?->getElements() ?? []);
        usort($events, static fn(TraceEventEntity $a, TraceEventEntity $b): int => $a->getSeq() <=> $b->getSeq());

        return array_map(static fn(TraceEventEntity $event): array => [
            'seq' => $event->getSeq(),
            'stage' => $event->getStage(),
            'elapsedMs' => $event->getElapsedMs(),
            'payload' => $event->getPayload() ?? [],
        ], $events);
    }
}
```

**Check `TraceEventEntity::getSeq()` exists** before running; if the accessor is named differently,
use the real one and sort by it, because the seq is what orders events within a turn.

- [ ] **Step 7: Run both and commit**

Run: `vendor/bin/phpunit --exclude-group eval && composer run quality`

```bash
git add src/Core/Trace/Export tests/Core/Trace/Export
git commit -m "feat(trace): serialise conversations as a table and as a full record"
```

---

### Task 5: The endpoint

**Files:**
- Create: `src/Core/Trace/Export/TraceExportSource.php`
- Create: `src/Core/Trace/Export/DalTraceExportSource.php`
- Create: `src/Controller/AssistantTraceExportController.php`
- Create: `tests/Controller/FakeTraceExportSource.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Controller/AssistantTraceExportControllerTest.php`

**Interfaces:**
- Consumes: both serialisers (Task 4)
- Produces: `TraceExportSource::load(array $ids, Context $context): array{conversations: list<ConversationEntity>, salesChannelNames: array<string, string>}`;
  `POST /api/_action/swag-assistant/trace/export` taking `{"ids": [...], "format": "csv"|"json"}`, answering a file download or a 400

> **Why a source interface rather than the repository.** PHPUnit's `createMock()` is available and
> already used (`AssistantEndpointTestCase` mocks `SalesChannelContext`), so mocking
> `EntityRepository` would be *possible* — but its `search()` returns an `EntitySearchResult`, whose
> own constructor takes six arguments including an `EntityCollection` and a `Criteria`. Every test
> would spend more lines building that return value than asserting the policy it is there to check.
> `ChatTurnRunnerInterface`'s docblock states the rule this follows — *"an interface so the
> controller can be tested without an LLM, a network, or a Shopware kernel"* — and the controller's
> job here is **policy**: the bound, the refusals, the file name. It should depend on "load
> conversations for export", which is what it actually needs, rather than on a repository.

- [ ] **Step 1: Write the seam and its fake**

Create `src/Core/Trace/Export/TraceExportSource.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Export;

use Shopware\Core\Framework\Context;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;

/**
 * Everything an export needs to read, behind one call.
 *
 * An interface for the reason {@see \Swag\AssistantStarterKit\Core\Agent\ChatTurnRunnerInterface}
 * is one: the controller's job is **policy** — the size bound, the refusals, the file name — and
 * every one of those has a failure mode that must be observable without a database. `EntityRepository`
 * is a concrete class with a heavy constructor, so a controller that depended on it directly could
 * only be tested against a running Shopware.
 *
 * Both halves are returned together because they are read together: a conversation without its sales
 * channel's name exports an id where a merchant expects a shop.
 */
interface TraceExportSource
{
    /**
     * @param list<string> $ids
     *
     * @return array{conversations: list<ConversationEntity>, salesChannelNames: array<string, string>}
     *         `conversations` holds only ids that resolved — the caller compares counts to learn
     *         how many were dropped. `salesChannelNames` maps id => name and may be missing an id,
     *         which the serialisers render as the id itself.
     */
    public function load(array $ids, Context $context): array;
}
```

Create `tests/Controller/FakeTraceExportSource.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Shopware\Core\Framework\Context;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceExportSource;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventCollection;

/**
 * Returns one conversation per id it is asked for, unless told to drop some.
 *
 * A hand-written double rather than a mock, like {@see RecordingTurnRunner} and
 * {@see \Swag\AssistantStarterKit\Tests\Core\Trace\InMemoryConversationStore}: this repo has no
 * mocking library, and the assertions here are about *what the controller did with what it got*,
 * which a recording double states more legibly than expectation syntax.
 */
final class FakeTraceExportSource implements TraceExportSource
{
    /** @var list<string> */
    public array $lastIds = [];

    /** How many of the requested ids to leave unresolved, so the skipped-count path can be driven. */
    public int $dropCount = 0;

    public function load(array $ids, Context $context): array
    {
        $this->lastIds = $ids;

        $resolved = \array_slice($ids, 0, max(0, \count($ids) - $this->dropCount));

        return [
            'conversations' => array_map(static function (string $id): ConversationEntity {
                $conversation = new ConversationEntity();
                $conversation->setId($id);
                $conversation->setSalesChannelId('01a01b4af6567284ac9eeb3616598ac3');
                $conversation->setTurnCount(1);
                $conversation->setOutcome('product_shown');
                $conversation->setTotalMs(0);
                $conversation->setCreatedAt(new \DateTimeImmutable('2026-08-24T10:12:04+00:00'));
                $conversation->setEvents(new TraceEventCollection([]));

                return $conversation;
            }, $resolved),
            'salesChannelNames' => ['01a01b4af6567284ac9eeb3616598ac3' => 'Storefront'],
        ];
    }
}
```

- [ ] **Step 2: Write the failing controller test**

Create `tests/Controller/AssistantTraceExportControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Swag\AssistantStarterKit\Controller\AssistantTraceExportController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The endpoint owns policy — the bound, the refusals, the file name. The formats are pinned by the
 * serialiser tests; nothing here re-asserts them.
 */
final class AssistantTraceExportControllerTest extends TestCase
{
    private FakeTraceExportSource $source;

    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->source = new FakeTraceExportSource();
    }

    public function testTooManyIdsAreRefusedWithTheCount(): void
    {
        $response = $this->export(self::ids(1001), 'csv');

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('1001', (string) $response->getContent());
        self::assertStringContainsString('1000', (string) $response->getContent(), 'the bound must be named');
        self::assertSame([], $this->source->lastIds, 'nothing may be loaded once the bound is exceeded');
    }

    public function testExactlyTheBoundIsAllowed(): void
    {
        // Off-by-one on a refusal is the difference between a bound and a bug.
        self::assertSame(Response::HTTP_OK, $this->export(self::ids(1000), 'csv')->getStatusCode());
    }

    public function testAnEmptyListIsRefused(): void
    {
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->export([], 'csv')->getStatusCode());
    }

    public function testIdsThatAreNotIdsAreDroppedBeforeAnythingIsLoaded(): void
    {
        // The endpoint is authenticated but its body is still client input, and a malformed id has
        // no business reaching a repository lookup — the same rule ChatRequest applies.
        $response = $this->export(['../../etc/passwd', 'NOTHEX', str_repeat('a', 32)], 'json');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([str_repeat('a', 32)], $this->source->lastIds);
    }

    public function testAnUnknownFormatIsRefusedRatherThanDefaulted(): void
    {
        // Defaulting would hand a merchant who asked for JSON a CSV without saying so.
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->export(self::ids(1), 'pdf')->getStatusCode());
    }

    public function testTheFileIsNamedSoTwoExportsDoNotOverwriteEachOther(): void
    {
        $response = $this->export(self::ids(1), 'csv');

        self::assertMatchesRegularExpression(
            '/attachment; filename=assistant-traces-\d{4}-\d{2}-\d{2}-\d{6}\.csv/',
            (string) $response->headers->get('Content-Disposition'),
        );
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
    }

    public function testAnIdThatNoLongerResolvesIsReportedRatherThanSwallowed(): void
    {
        // One stale id must not cost the merchant the rest of the export — but "some rows are
        // missing" is not something to discover by counting lines afterwards.
        $this->source->dropCount = 2;

        $response = $this->export(self::ids(5), 'csv');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('2', $response->headers->get('X-Swag-Assistant-Skipped'));
    }

    public function testNothingIsReportedSkippedWhenEverythingResolved(): void
    {
        self::assertNull($this->export(self::ids(3), 'csv')->headers->get('X-Swag-Assistant-Skipped'));
    }

    /** @return list<string> */
    private static function ids(int $count): array
    {
        return array_map(static fn(int $i): string => \sprintf('%032x', $i), range(1, $count));
    }

    /** @param list<string> $ids */
    private function export(array $ids, string $format): Response
    {
        $request = new Request(content: json_encode(['ids' => $ids, 'format' => $format], \JSON_THROW_ON_ERROR));

        return (new AssistantTraceExportController($this->source))->export($request, Context::createDefaultContext());
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Controller/AssistantTraceExportControllerTest.php`
Expected: FAIL — `AssistantTraceExportController` does not exist.

- [ ] **Step 4: Write the controller**

Create `src/Controller/AssistantTraceExportController.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\RouteScope\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceCsvSerialiser;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceExportSource;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceJsonSerialiser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Takes conversations out of the Administration.
 *
 * **This endpoint emits customer names** (spec T4), which is why the privilege is declared on the
 * route: the file is personal data the moment it is written, and what may produce it must not
 * depend on the frontend behaving.
 *
 * It owns policy — the bound, the refusals, the file name — and nothing else. The formats live in
 * {@see TraceCsvSerialiser} and {@see TraceJsonSerialiser}; the reading lives behind
 * {@see TraceExportSource}, so everything here is testable without a database.
 *
 * Not an `AbstractController`: it needs no container, no twig and no `setContainer()` call, and
 * extending one would add a dependency purely to inherit helpers this never uses.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class AssistantTraceExportController
{
    /**
     * How many conversations one export may carry.
     *
     * Retention keeps 30 days and a busy shop is well past a thousand in that time, so this is
     * reached in practice rather than in theory. A synchronous request that assembles tens of
     * thousands of event rows is not a feature — and refusing with the count tells a merchant what
     * to narrow, which a timeout does not.
     */
    private const MAX_CONVERSATIONS = 1000;

    public function __construct(private readonly TraceExportSource $source) {}

    #[Route(
        path: '/api/_action/swag-assistant/trace/export',
        name: 'api.action.swag_assistant.trace.export',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['swag_assistant_conversation:read']],
        methods: ['POST'],
    )]
    public function export(Request $request, Context $context): Response
    {
        $decoded = json_decode((string) $request->getContent(), associative: true);
        $payload = \is_array($decoded) ? $decoded : [];

        $format = $payload['format'] ?? null;

        if ($format !== 'csv' && $format !== 'json') {
            // Not defaulted: handing a merchant who asked for JSON a CSV without saying so is worse
            // than refusing.
            return new JsonResponse(['error' => 'Set "format" to "csv" or "json".'], Response::HTTP_BAD_REQUEST);
        }

        // Authenticated, but still client input — a malformed id has no business reaching a
        // repository lookup, exactly as {@see ChatRequest} treats the conversation token.
        $ids = array_values(array_filter(
            \is_array($payload['ids'] ?? null) ? $payload['ids'] : [],
            static fn(mixed $id): bool => \is_string($id) && preg_match(CardIdList::ID_PATTERN, $id) === 1,
        ));

        if ($ids === []) {
            return new JsonResponse(
                ['error' => 'Select at least one conversation to export.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (\count($ids) > self::MAX_CONVERSATIONS) {
            return new JsonResponse([
                'error' => \sprintf(
                    'This export covers %d conversations; at most %d can be exported at once. '
                        . 'Narrow the filter and try again.',
                    \count($ids),
                    self::MAX_CONVERSATIONS,
                ),
            ], Response::HTTP_BAD_REQUEST);
        }

        ['conversations' => $conversations, 'salesChannelNames' => $names] = $this->source->load($ids, $context);

        $body = $format === 'csv'
            ? TraceCsvSerialiser::serialise($conversations, $names)
            : TraceJsonSerialiser::serialise($conversations, $names);

        return $this->file($body, $format, \count($ids) - \count($conversations));
    }

    private function file(string $body, string $format, int $skipped): Response
    {
        $response = new Response($body, Response::HTTP_OK);

        $response->headers->set('Content-Type', $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json');
        $response->headers->set(
            'Content-Disposition',
            \sprintf('attachment; filename=assistant-traces-%s.%s', date('Y-m-d-His'), $format),
        );

        // Named rather than silent: an id that no longer resolves must not cost the merchant the
        // rest of the export, but "some rows are missing" is not something to discover by counting.
        if ($skipped > 0) {
            $response->headers->set('X-Swag-Assistant-Skipped', (string) $skipped);
        }

        return $response;
    }
}
```

- [ ] **Step 5: Write the DAL implementation**

Create `src/Core/Trace/Export/DalTraceExportSource.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Export;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;

/**
 * Reads what an export needs out of the DAL, in two queries regardless of how many conversations
 * were asked for.
 */
final readonly class DalTraceExportSource implements TraceExportSource
{
    public function __construct(
        private EntityRepository $conversationRepository,
        private EntityRepository $salesChannelRepository,
    ) {}

    /**
     * @param list<string> $ids
     *
     * @return array{conversations: list<ConversationEntity>, salesChannelNames: array<string, string>}
     */
    public function load(array $ids, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('id', $ids));
        // Associations rather than follow-up queries: a thousand conversations would otherwise be a
        // thousand round trips. `events` is the export; `customer` is the name spec T4 puts in it.
        $criteria->addAssociation('events');
        $criteria->addAssociation('customer');
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var list<ConversationEntity> $conversations */
        $conversations = array_values($this->conversationRepository->search($criteria, $context)->getElements());

        return [
            'conversations' => $conversations,
            'salesChannelNames' => $this->salesChannelNames($conversations, $context),
        ];
    }

    /**
     * @param list<ConversationEntity> $conversations
     *
     * @return array<string, string>
     */
    private function salesChannelNames(array $conversations, Context $context): array
    {
        // `sales_channel_id` is a plain string column rather than a foreign key — the same
        // limitation the list component documents — so it cannot be an association and is looked up
        // once for the whole export.
        $ids = array_values(array_unique(array_map(
            static fn(ConversationEntity $conversation): string => $conversation->getSalesChannelId(),
            $conversations,
        )));

        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach ($this->salesChannelRepository->search(new Criteria($ids), $context) as $channel) {
            $names[$channel->getId()] = (string) $channel->getName();
        }

        return $names;
    }
}
```

- [ ] **Step 6: Register both**

In `src/Resources/config/services.xml`, beside the other controllers:

```xml
        <service id="Swag\AssistantStarterKit\Core\Trace\Export\DalTraceExportSource">
            <argument type="service" id="swag_assistant_conversation.repository"/>
            <argument type="service" id="sales_channel.repository"/>
        </service>

        <!-- Emits customer names (spec T4), so its privilege is declared on the route rather than
             inherited from whatever read the frontend happens to perform. -->
        <service id="Swag\AssistantStarterKit\Controller\AssistantTraceExportController" public="true">
            <argument type="service" id="Swag\AssistantStarterKit\Core\Trace\Export\DalTraceExportSource"/>
        </service>
```

- [ ] **Step 7: Run it to verify it passes**

Run: `vendor/bin/phpunit --exclude-group eval && composer run quality`
Expected: PASS, exit 0.

If the controller trips mago's per-class complexity budget, extract the payload parsing into a
`TraceExportRequest` value object next to `ChatRequest` — that is the move this repo has already
made twice, and `CardIdList`'s docblock records why.

- [ ] **Step 8: Commit**

```bash
git add src/Controller src/Core/Trace/Export src/Resources/config/services.xml tests/Controller
git commit -m "feat(admin): let a merchant take conversations out of the Administration"
```

---

### Task 6: The list — who, and the two buttons

**Files:**
- Modify: `src/Resources/app/administration/.../swag-assistant-trace-list/index.js`
- Modify: `src/Resources/app/administration/.../swag-assistant-trace-list/swag-assistant-trace-list.html.twig`
- Modify: `src/Resources/app/administration/.../snippet/en-GB.json`, `de-DE.json`

**Interfaces:**
- Consumes: the `customer` association (Task 1), the endpoint (Task 5)
- Produces: a `user` column; `exportSelected(format)` acting on selection or filter per spec T8

> **No unit test.** This is wiring: a column, a checkbox flag and two buttons that post ids. The
> derivations behind them are tested in PHP. Verification is Step 5's live check.

- [ ] **Step 1: Load the association and show the column**

In `index.js`, extend `load()`:

```js
            const criteria = new Criteria(1, 25);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));
            // The name comes off the association rather than a second lookup — `customer_id` is a
            // real foreign key, unlike `sales_channel_id` above.
            criteria.addAssociation('customer');
```

and add to `columns()`, after the sales channel:

```js
                { property: 'customer.lastName', label: 'swag-assistant-trace.list.columnUser' },
```

with the renderer, beside `channelName`:

```js
        /**
         * Spec T3: two states. A conversation with no customer either never had one or had one who
         * deleted their account — `ON DELETE SET NULL` makes those indistinguishable on purpose,
         * so "Guest user" is the only thing that is true in both cases.
         */
        userName(conversation) {
            const customer = conversation.customer;

            if (!customer) {
                return this.$tc('swag-assistant-trace.list.guestUser');
            }

            return `${customer.firstName ?? ''} ${customer.lastName ?? ''}`.trim();
        },
```

and in the twig, beside the other column templates:

```twig
                <template #column-customer.lastName="{ item }">
                    {{ userName(item) }}
                </template>
```

- [ ] **Step 2: Turn on selection**

In the twig, change `:showSelection="false"` to `:showSelection="true"` and **amend the comment
above the grid** rather than deleting it — it currently justifies read-only, and that justification
is still true:

```twig
            {# `allowEdit` must stay true: sw-entity-listing only links the primary column to
               detailRoute when it is set, so turning it off made the trace unreachable. Read-only
               is enforced by the page having no write path and by inline/column edit being off,
               not by hiding the link to it.

               Selection is on for export only — `allowDelete` stays false, so a checkbox can send a
               conversation to a file and never to a delete confirmation. #}
```

- [ ] **Step 3: Add the two buttons**

In the twig, inside the listing's bulk slot:

```twig
                <template #bulk>
                    <sw-button size="small" @click="exportSelected('csv')">
                        {{ $tc('swag-assistant-trace.list.exportCsv') }}
                    </sw-button>
                    <sw-button size="small" @click="exportSelected('json')">
                        {{ $tc('swag-assistant-trace.list.exportJson') }}
                    </sw-button>
                </template>
```

and above the grid, for the no-selection case (spec T8):

```twig
            {# With nothing ticked the buttons act on every row matching the current filter, and the
               count says so. Shopware's own select-all covers the current page only, so a literal
               "select all" would quietly export 25 rows. #}
            <div class="swag-assistant-trace-list__export" v-if="!selectionCount">
                <sw-button size="small" :disabled="!total" @click="exportFiltered('csv')">
                    {{ $tc('swag-assistant-trace.list.exportAllCsv', 0, { count: total }) }}
                </sw-button>
                <sw-button size="small" :disabled="!total" @click="exportFiltered('json')">
                    {{ $tc('swag-assistant-trace.list.exportAllJson', 0, { count: total }) }}
                </sw-button>
            </div>
```

- [ ] **Step 4: Post the ids and save the file**

Add to `index.js`:

```js
        /**
         * The download goes through an authenticated request rather than `window.open`: the route
         * emits customer names and is ACL-protected, so it cannot be opened as a plain URL.
         */
        async download(ids, format) {
            const response = await fetch(`${Shopware.Context.api.apiPath}/_action/swag-assistant/trace/export`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                },
                body: JSON.stringify({ ids, format }),
            });

            if (!response.ok) {
                const problem = await response.json().catch(() => ({}));
                // Shown in the page rather than through a notification mixin: nothing in this
                // module uses one today, and the endpoint's refusals are instructions — "narrow the
                // filter and try again" — which belong next to the filter they are about.
                this.exportError = problem.error ?? this.$tc('swag-assistant-trace.list.exportFailed');

                return;
            }

            this.exportError = null;

            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const anchor = document.createElement('a');

            anchor.href = url;
            anchor.download = `assistant-traces.${format}`;
            anchor.click();
            window.URL.revokeObjectURL(url);
        },

        exportSelected(format) {
            this.download(Object.keys(this.$refs.grid?.selection ?? {}), format);
        },

        /** Every row the current filter matches, not just the page — see the twig comment. */
        async exportFiltered(format) {
            const criteria = new Criteria(1, 1000);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            if (this.outcomeFilter) {
                criteria.addFilter(Criteria.equals('outcome', this.outcomeFilter));
            }

            const result = await this.repository.searchIds(criteria, Shopware.Context.api);

            this.download(result.data, format);
        },
```

Add `exportError: null` to `data()`, and render it above the grid:

```twig
            <sw-alert v-if="exportError" variant="error" class="swag-assistant-trace-list__export-error">
                {{ exportError }}
            </sw-alert>
```

Add `total` and `selectionCount` to `computed` — `total` from the search result's `total`,
`selectionCount` from the grid ref's selection — and give the grid `ref="grid"` in the twig so
`exportSelected` can read its selection.

**No notification mixin.** This module uses none today, and adding one for a single error message
would introduce a pattern the rest of the page does not follow. The refusals this endpoint returns
are instructions about the filter, so they belong beside it.

- [ ] **Step 5: Snippets, build, and see it**

Add to both snippet files (`en-GB.json` shown; translate for `de-DE.json`):

```json
        "columnUser": "User",
        "guestUser": "Guest user",
        "exportCsv": "Export as CSV",
        "exportJson": "Export as JSON",
        "exportAllCsv": "Export all {count} as CSV",
        "exportAllJson": "Export all {count} as JSON",
        "exportFailed": "The export could not be created."
```

Run `composer run build`, deploy to the test shop (`cache:clear`, `assets:install`), then in the
Administration: confirm the user column reads `Guest user` for the existing rows, tick two, export
both formats, and open the CSV in a spreadsheet.

- [ ] **Step 6: Commit**

```bash
git add src/Resources/app/administration src/Resources/app/storefront/dist
git commit -m "feat(admin): show who a conversation belonged to, and export from the list"
```

---

### Task 7: The detail page's button

**Files:**
- Modify: `src/Resources/app/administration/.../swag-assistant-trace-detail/index.js`
- Modify: `src/Resources/app/administration/.../swag-assistant-trace-detail/swag-assistant-trace-detail.html.twig`
- Modify: the two snippet files

**Interfaces:**
- Consumes: the endpoint (Task 5)
- Produces: a single-trace JSON download

- [ ] **Step 1: Add the button**

In the detail twig's smart bar:

```twig
        <template #smart-bar-actions>
            <sw-button variant="primary" size="small" :disabled="!conversation" @click="exportTrace">
                {{ $tc('swag-assistant-trace.detail.export') }}
            </sw-button>
        </template>
```

- [ ] **Step 2: Reuse the same request**

In the detail `index.js`, add a `exportTrace()` that posts `{ids: [this.$route.params.id], format: 'json'}`
through the same fetch shape Task 6 Step 4 introduced.

**Extract rather than copy.** Two identical fetch-and-save blocks in two components is the
duplication `composer run quality`'s jscpd step exists to catch. Put it in
`src/Resources/app/administration/src/module/swag-assistant-trace/export.js` as
`downloadTraces(ids, format)` taking the notification callback as an argument, import it in both,
and add a `tests/js/export.test.js` covering the two things that are pure: the request body it
builds and the filename it derives.

JSON only, with no format choice: a single trace is opened to work out what happened, and that is
the format that answers it (spec T5).

- [ ] **Step 3: Snippet, build, verify**

Add `"export": "Export trace"` to both snippet files, run `composer run build`, deploy, and export
one trace from its detail page. Open the file and confirm the event payloads are the ones the page
shows under "Raw trace".

- [ ] **Step 4: Run everything and commit**

Run: `vendor/bin/phpunit --exclude-group eval && npm run test:js && composer run quality`

```bash
git add src/Resources/app/administration tests/js
git commit -m "feat(admin): export one trace from its own page"
```

---

### Task 8: Documentation

**Files:**
- Modify: `ARCHITECTURE.md`, `README.md`

- [ ] **Step 1: The column and its constraint**

In `ARCHITECTURE.md`, wherever the conversation entity is described, add `customer_id` with the
sentence that matters: a foreign key with `ON DELETE SET NULL`, so a customer's deletion severs the
link without an erasure routine, and a deleted customer's conversation is indistinguishable from a
guest's by design.

- [ ] **Step 2: The endpoint**

Add `POST /api/_action/swag-assistant/trace/export` to the same place the storefront routes are
listed: admin-scoped, requires `swag_assistant_conversation:read`, takes ids and a format, bounded
at 1 000 conversations.

- [ ] **Step 3: The README's capability list**

One sentence in "What it does": the merchant sees which customer a conversation belonged to, and can
export conversations as CSV to count or JSON to hand over.

**Say what the export is** in the same breath: a file containing customer names, and therefore
personal data once it leaves the shop. That is spec T4's accepted cost, and a README that mentions
the feature without it is where the cost gets forgotten.

- [ ] **Step 4: Commit**

```bash
git add ARCHITECTURE.md README.md
git commit -m "docs: document trace attribution and the export endpoint"
```

---

## Spec coverage

| Decision | Task |
|---|---|
| T1 customer id as a foreign key, `ON DELETE SET NULL` | 1 |
| T2 captured once, at conversation start | 2 |
| T3 two display states | 3 (export), 6 (list) |
| T4 the export carries the resolved name | 3, 5 |
| T5 two formats, chosen by the button | 4, 6, 7 |
| T6 one server endpoint produces both | 5 |
| T7 bound of 1 000, refused with the count | 5 |
| T8 selection, or the whole filter when there is none | 6 |

## Known risks

| Risk | Severity | Mitigation |
|---|---|---|
| The foreign key fails to apply in a shop whose `customer` table uses a different collation or engine | medium — the migration would abort mid-install | Task 1 Step 4 applies it against the real shop before anything depends on it. `customer.id` is `BINARY(16)` in every supported Shopware 6 schema |
| `ON DELETE SET NULL` is later "fixed" to `CASCADE` by someone tidying foreign keys | **high** — it would delete traces when a customer leaves, silently destroying the merchant's own record | The migration's docblock says so in its first paragraph, and spec T1 carries the reasoning |
| The shop/model split in `TraceExportRow` drifts from `phases.js` | medium — a number in the file stops matching the screen | One derivation, one threshold constant, and `TraceExportRowTest` pins the arithmetic against a real turn's offsets |
| Someone reads the CSV's customer names as anonymous analytics data | medium | Spec T4 and the README sentence in Task 8 Step 3 |
| The admin fetch is written twice and drifts | low | Task 7 Step 2 extracts it before the second use, and jscpd is in the gate |
| A merchant exports 1 000 conversations and the request times out anyway | low | The bound is a first estimate. If it is hit in practice, the honest next step is measuring one export of 1 000 rather than raising the number |
