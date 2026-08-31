# Shop Knowledge Switch and Portable Passage Store — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make shop-information retrieval findable by a merchant, and make it run on any database Shopware supports — while keeping the MariaDB vector store as the preferred path wherever it works.

**Architecture:** Part A adds one boolean that resolves into the existing `embeddingModel: ''` off-switch, and regroups the shop-information settings into a card whose title a merchant understands. Part B adds a second `PassageStore` implementation over an ordinary table that ranks with PHP cosine, plus a chooser in front of the two that prefers the MariaDB store when the database and package are both present.

**Tech Stack:** PHP 8.2, Shopware 6.7, Doctrine DBAL, PHPUnit 11, mago (lint + static analysis)

**Spec:** `docs/superpowers/specs/2026-08-31-portable-passage-store-design.md`

## Global Constraints

- **`embeddingModel: ''` is the one internal off-state.** Never add a second kind of "switched off"; every new condition resolves into this one (spec D2).
- **An unmet requirement is off, never an exception.** Nothing added here may throw where a shopper can see it (spec requirement 5).
- **MariaDB is preferred, not required.** Where `DalVectorSupport::isAvailable()` and the package are both present, the existing `AiStorePassageStore` runs and nothing about that path changes (spec requirement 2, D1).
- **The fallback is never silent** (spec D6). Falling back to the portable store must leave evidence a merchant can find.
- **Every new table joins `AssistantTableRemoval::TABLES`** so uninstall removes it (spec D7).
- Quality gate for every commit: `composer test`, `composer format`, `composer lint`, `composer typecheck`, `composer quality:filesize`. Only `error`-level lint/analysis findings gate.
- **`composer test`, never bare `phpunit`** — bare phpunit also runs the `eval` group, which makes paid LLM calls and hangs.
- The two stores never share data. Nothing in this plan copies passages between them.
- **Most classes here are `final readonly`, so PHPUnit cannot stub them.** Build the real object over
  a fake collaborator instead — `FakeSystemConfigService` (in `tests/Core/Config/`) is the pattern the
  suite already uses. `Doctrine\DBAL\Connection` is not final and is mocked throughout.
- **There are no database integration tests in this repo.** Every store and migration test mocks
  `Connection` and asserts the SQL it receives (`tests/Migration/InvertKillSwitchTest.php` is the
  reference). SQL correctness against a real server is verified once, by hand, in Task 7.

---

### Task 1: The merchant-readable switch

**Files:**
- Modify: `src/Resources/config/config.xml` — new `<card>`, and move two fields out of the `languageModel` card
- Modify: `src/Core/Config/SystemConfigAssistantConfig.php:embeddingModel()`
- Test: `tests/Core/Config/ShopKnowledgeSwitchTest.php`

**Interfaces:**
- Consumes: `ShopInfoAvailability::isAvailable()` (already exists)
- Produces: config key `SwagAssistantStarterKit.config.enableShopKnowledge` (bool, default `false`)

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Config/ShopKnowledgeSwitchTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;
use Swag\AssistantStarterKit\Core\ShopInfo\VectorSupport;

/**
 * The switch a merchant actually reads.
 *
 * `embeddingModel` sat in the "Language model" card beside the base URL and the API key, so nothing
 * on that screen said the field turns on document-based answering. A merchant could not find the
 * feature, and the one who did configure it did so blind — which is half of why an embedding model
 * ended up set on a shop that could not run it.
 *
 * The boolean resolves INTO `embeddingModel`, which is already this plugin's one off-state: no tool
 * constructed, nothing in the model's schema, ingestion refused at the CLI. A second kind of
 * "switched off" would be a second thing to get wrong.
 */
final class ShopKnowledgeSwitchTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    private function config(bool $enabled): SystemConfigAssistantConfig
    {
        $vectors = new class implements VectorSupport {
            public function isAvailable(): bool
            {
                return true;
            }

            public function describe(): string
            {
                return 'MariaDB 11.8';
            }
        };

        return new SystemConfigAssistantConfig(
            new FakeSystemConfigService([
                self::PREFIX . 'enableShopKnowledge' => $enabled,
                self::PREFIX . 'embeddingModel' => 'baai/bge-m3',
                self::PREFIX . 'autoIndexShopPages' => true,
            ]),
            new ShopInfoAvailability($vectors, packagesInstalled: true),
        );
    }

    public function testTheModelIsReadWhenTheMerchantSwitchedShopKnowledgeOn(): void
    {
        self::assertSame('baai/bge-m3', $this->config(true)->forSalesChannel(self::CHANNEL)->embeddingModel);
    }

    /**
     * The stored model name is left alone — switching the feature back on must not require retyping
     * it, and the settings screen still shows what was chosen.
     */
    public function testTheModelReadsAsEmptyWhenTheSwitchIsOff(): void
    {
        self::assertSame('', $this->config(false)->forSalesChannel(self::CHANNEL)->embeddingModel);
    }

    public function testAutomaticReindexingIsOffWithTheSwitch(): void
    {
        self::assertFalse($this->config(false)->forSalesChannel(self::CHANNEL)->autoIndexShopPages);
    }

    public function testTheSwitchIsOffOnAShopThatNeverSetIt(): void
    {
        $config = new SystemConfigAssistantConfig(
            new FakeSystemConfigService([self::PREFIX . 'embeddingModel' => 'baai/bge-m3']),
        );

        self::assertSame('', $config->forSalesChannel(self::CHANNEL)->embeddingModel);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Config/ShopKnowledgeSwitchTest.php --exclude-group eval`
Expected: FAIL — `testTheModelReadsAsEmptyWhenTheSwitchIsOff` and `testTheSwitchIsOffOnAShopThatNeverSetIt` return `'baai/bge-m3'`, because nothing reads the new key yet.

- [ ] **Step 3: Add the condition**

In `src/Core/Config/SystemConfigAssistantConfig.php`, change `embeddingModel()`:

```php
    private function embeddingModel(string $salesChannelId): string
    {
        if (!$this->stored->bool('enableShopKnowledge', false, $salesChannelId) || !$this->shopInfoUsable()) {
            return '';
        }

        return trim($this->stored->string('embeddingModel', $salesChannelId));
    }
```

and `autoIndexShopPages` in `forSalesChannel()`:

```php
            autoIndexShopPages: $this->embeddingModel($salesChannelId) !== ''
                && $this->stored->bool('autoIndexShopPages', false, $salesChannelId),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Config/ShopKnowledgeSwitchTest.php tests/Core/Config/ShopInfoDisabledWhenUnsupportedTest.php --exclude-group eval`
Expected: PASS. `ShopInfoDisabledWhenUnsupportedTest` will now fail unless its fixture sets `enableShopKnowledge => true` — add that key to its `FakeSystemConfigService` array; the test's subject is the availability gate, not the switch.

- [ ] **Step 5: Add the card to config.xml**

In `src/Resources/config/config.xml`, delete the `embeddingModel` and `autoIndexShopPages` `<input-field>` blocks from the `languageModel` card, and insert this card immediately after it:

```xml
    <card>
        <name>shopKnowledge</name>
        <title>Shop knowledge</title>

        <input-field type="bool">
            <name>enableShopKnowledge</name>
            <label>Answer from your shop's own documents</label>
            <defaultValue>false</defaultValue>
            <helpText>With this on, the assistant can answer questions about your imprint, privacy
                policy, terms, right of withdrawal and shipping — and about any document you upload
                yourself on the Assistant shop information screen, such as a size guide or a care
                instruction. With it off, the assistant answers about products only and is never
                offered these documents at all.

                Needs an embedding model below, and a database that can store vectors. MariaDB 11.7
                or newer is recommended: it indexes the vectors, which stays fast on a large set of
                documents. On any other database the assistant compares them itself, which is fine
                for the handful of pages most shops have and slows down on thousands.</helpText>
        </input-field>

        <input-field>
            <name>embeddingModel</name>
            <label>Embedding model</label>
            <helpText>Used to index shop documents and to match a question against them. Uses the
                same provider URL and API key as the chat model, so it must be a model your provider
                serves at /v1/embeddings. Measured working on OpenRouter: baai/bge-m3 (1024
                dimensions, multilingual — the one this plugin was calibrated against),
                openai/text-embedding-3-small (1536) and openai/text-embedding-3-large (3072).
                Changing it makes every indexed document unusable — delete and index them again after
                a change, which the shop information screen will tell you.</helpText>
        </input-field>

        <input-field type="bool">
            <name>autoIndexShopPages</name>
            <label>Re-index shop pages automatically on change</label>
            <defaultValue>false</defaultValue>
            <helpText>When on, editing the imprint, privacy, withdrawal, terms or shipping page
                re-indexes that page in the background and replaces its previous passages. This costs
                one embedding call per changed page, so it is off by default. With it off, use "Index
                shop pages" on the Assistant shop information screen after editing a page — otherwise
                the assistant keeps answering from the wording it last indexed.</helpText>
        </input-field>
    </card>
```

- [ ] **Step 6: Run the whole gate**

Run: `composer test && composer format && composer lint && composer typecheck && composer quality:filesize`
Expected: all pass. `PluginManifestTest::testEveryConfigKeyTheConfigBridgeReadsIsDeclaredInConfigXml` covers the new key automatically — if it fails, the `<name>` in config.xml does not match the string in `StoredValueReader`.

- [ ] **Step 7: Commit**

```bash
git add src/Resources/config/config.xml src/Core/Config/SystemConfigAssistantConfig.php tests/Core/Config/ShopKnowledgeSwitchTest.php tests/Core/Config/ShopInfoDisabledWhenUnsupportedTest.php
git commit -m "feat(shop-info): give shop knowledge a switch a merchant can read"
```

---

### Task 2: Extract the ranking both stores need

**Files:**
- Create: `src/ShopInfo/PassageRanking.php`
- Modify: `src/ShopInfo/InMemoryPassageStore.php:47-76` (the body of `query()`)
- Test: `tests/ShopInfo/PassageRankingTest.php`

**Interfaces:**
- Produces: `PassageRanking::of(array $queryVector, array $candidates, float $minScore, int $limit): array` where `$candidates` is `list<array{passage: ShopInfoPassage, vector: list<float>}>` and the return is `list<ShopInfoPassage>` with `score` set, best first.

- [ ] **Step 1: Write the failing test**

Create `tests/ShopInfo/PassageRankingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;
use Swag\AssistantStarterKit\ShopInfo\PassageRanking;

/**
 * The threshold-and-sort both passage stores need, in one place.
 *
 * `PassageStore`'s own docblock names the hazard this class exists to contain: the interface speaks
 * SIMILARITY, where higher is more similar, while the MariaDB store underneath speaks DISTANCE, where
 * lower is. It calls that inversion "the one thing here most likely to be simplified into a sign
 * error". Two stores each doing their own threshold-and-sort is two chances to make it.
 */
final class PassageRankingTest extends TestCase
{
    /**
     * @param list<float> $vector
     *
     * @return array{passage: ShopInfoPassage, vector: list<float>}
     */
    private function candidate(string $name, array $vector): array
    {
        return [
            'passage' => new ShopInfoPassage('doc-1', $name, 'Section', 'text of ' . $name),
            'vector' => $vector,
        ];
    }

    public function testItReturnsTheMostSimilarPassageFirst(): void
    {
        $ranked = PassageRanking::of(
            [1.0, 0.0],
            [$this->candidate('far', [0.0, 1.0]), $this->candidate('near', [1.0, 0.0])],
            minScore: 0.0,
            limit: 10,
        );

        self::assertSame('near', $ranked[0]->documentName);
    }

    public function testItSetsTheScoreOnEveryPassageItReturns(): void
    {
        $ranked = PassageRanking::of([1.0, 0.0], [$this->candidate('near', [1.0, 0.0])], 0.0, 10);

        self::assertEqualsWithDelta(1.0, $ranked[0]->score, 0.000001);
    }

    /**
     * Higher is more similar, so the threshold is a floor. Getting this backwards would return
     * exactly the passages that do not answer the question.
     */
    public function testItDropsAnythingBelowTheThreshold(): void
    {
        $ranked = PassageRanking::of(
            [1.0, 0.0],
            [$this->candidate('orthogonal', [0.0, 1.0])],
            minScore: 0.4,
            limit: 10,
        );

        self::assertSame([], $ranked);
    }

    public function testItReturnsAtMostTheLimit(): void
    {
        $ranked = PassageRanking::of(
            [1.0, 0.0],
            [
                $this->candidate('a', [1.0, 0.0]),
                $this->candidate('b', [0.9, 0.1]),
                $this->candidate('c', [0.8, 0.2]),
            ],
            minScore: 0.0,
            limit: 2,
        );

        self::assertCount(2, $ranked);
    }

    public function testAnEmptyCandidateSetRanksToNothing(): void
    {
        self::assertSame([], PassageRanking::of([1.0, 0.0], [], 0.0, 10));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ShopInfo/PassageRankingTest.php --exclude-group eval`
Expected: FAIL — `Class "Swag\AssistantStarterKit\ShopInfo\PassageRanking" not found`.

- [ ] **Step 3: Write the class**

Create `src/ShopInfo/PassageRanking.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;

/**
 * Scores candidate passages against a query vector, drops what falls below the threshold, and returns
 * the best few.
 *
 * Extracted from {@see InMemoryPassageStore} when {@see DalPortablePassageStore} needed the same
 * logic. `PassageStore`'s own docblock explains why one copy matters: the interface speaks
 * SIMILARITY, higher being more similar, while a vector store underneath speaks DISTANCE, lower being
 * more similar — "the one thing here most likely to be simplified into a sign error". Two stores each
 * writing their own threshold-and-sort is two chances to make it, and the copy that drifts is always
 * the one nobody reads.
 */
final class PassageRanking
{
    private function __construct() {}

    /**
     * @param list<float>                                                  $queryVector
     * @param list<array{passage: ShopInfoPassage, vector: list<float>}>   $candidates
     *
     * @return list<ShopInfoPassage> best first, each carrying its similarity
     */
    public static function of(array $queryVector, array $candidates, float $minScore, int $limit): array
    {
        $scored = [];

        foreach ($candidates as $candidate) {
            $score = CosineSimilarity::between($queryVector, $candidate['vector']);

            if ($score < $minScore) {
                continue;
            }

            $passage = $candidate['passage'];
            $scored[] = new ShopInfoPassage(
                $passage->documentId,
                $passage->documentName,
                $passage->section,
                $passage->text,
                $score,
            );
        }

        usort($scored, static fn(ShopInfoPassage $a, ShopInfoPassage $b): int => $b->score <=> $a->score);

        return \array_slice($scored, offset: 0, length: $limit);
    }
}
```

- [ ] **Step 4: Point InMemoryPassageStore at it**

Replace the body of `query()` in `src/ShopInfo/InMemoryPassageStore.php` with:

```php
    public function query(array $vector, string $salesChannelId, float $minScore, int $limit): array
    {
        $candidates = [];

        foreach ($this->rows as $row) {
            if ($row['salesChannelId'] !== $salesChannelId) {
                continue;
            }

            $candidates[] = ['passage' => $row['passage'], 'vector' => $row['vector']];
        }

        return PassageRanking::of($vector, $candidates, $minScore, $limit);
    }
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/ShopInfo/ tests/Core/ShopInfo/ --exclude-group eval`
Expected: PASS, including the existing `InMemoryPassageStore` tests — this is a refactor, so any change in their result is a defect in the extraction.

- [ ] **Step 6: Commit**

```bash
git add src/ShopInfo/PassageRanking.php src/ShopInfo/InMemoryPassageStore.php tests/ShopInfo/PassageRankingTest.php
git commit -m "refactor(shop-info): extract the passage ranking both stores need"
```

---

### Task 3: The table, and its removal on uninstall

**Files:**
- Create: `src/Migration/Migration1788393600CreateShopInfoPassages.php`
- Modify: `src/PluginLifecycle/AssistantTableRemoval.php` — add the table to `TABLES`
- Test: `tests/Migration/CreateShopInfoPassagesTest.php`

**Interfaces:**
- Produces: table `swag_assistant_shop_info_passage`, columns as below.

- [ ] **Step 1: Write the failing test**

Create `tests/Migration/CreateShopInfoPassagesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Migration\Migration1788393600CreateShopInfoPassages;
use Swag\AssistantStarterKit\PluginLifecycle\AssistantTableRemoval;

/**
 * The portable store's table.
 *
 * Unlike `swag_assistant_shop_info_vector`, which is created lazily because a `VECTOR` column's width
 * is fixed at creation and depends on the embedding model, this table's vector column is `JSON` and
 * has no width — so it can be an ordinary migration, which also means Doctrine can describe it and
 * uninstall can find it from the migration list rather than by remembering.
 */
final class CreateShopInfoPassagesTest extends TestCase
{
    /** @param list<string> $statements */
    private function connectionRecording(array &$statements): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        return $connection;
    }

    public function testItCreatesTheTableWithTheColumnsTheStoreWrites(): void
    {
        $statements = [];
        (new Migration1788393600CreateShopInfoPassages())->update($this->connectionRecording($statements));

        $sql = implode("\n", $statements);

        foreach (['swag_assistant_shop_info_passage', 'document_id', 'document_name', 'sales_channel_id', 'section', 'text', 'vector', 'dimension'] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
    }

    /**
     * Every query filters by channel (spec R12): two channels genuinely have different terms, and a
     * query without that filter serves one shop's revocation notice in another.
     */
    public function testTheChannelIsIndexedBecauseEveryQueryFiltersOnIt(): void
    {
        $statements = [];
        (new Migration1788393600CreateShopInfoPassages())->update($this->connectionRecording($statements));

        self::assertStringContainsString('sales_channel_id', implode("\n", $statements));
        self::assertStringContainsString('KEY', implode("\n", $statements));
    }

    public function testDestructiveDoesNothing(): void
    {
        $statements = [];
        (new Migration1788393600CreateShopInfoPassages())->updateDestructive($this->connectionRecording($statements));

        self::assertSame([], $statements);
    }

    /**
     * The constraint from spec D7. A table added without this line survives an uninstall a merchant
     * performed to remove their data.
     */
    public function testUninstallRemovesTheNewTable(): void
    {
        self::assertContains('swag_assistant_shop_info_passage', AssistantTableRemoval::TABLES);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Migration/CreateShopInfoPassagesTest.php --exclude-group eval`
Expected: FAIL — migration class not found.

- [ ] **Step 3: Write the migration**

Create `src/Migration/Migration1788393600CreateShopInfoPassages.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Creates `swag_assistant_shop_info_passage` — where the portable store keeps passages on a database
 * without MariaDB's vector support.
 *
 * **An ordinary migration, unlike the vector table.** `ShopInfoVectorTable` creates its own table
 * lazily because a `VECTOR(n)` column's width is fixed at creation and comes from whichever embedding
 * model the merchant configured, which at install time is usually none. `vector` here is `JSON` and
 * has no width, so there is nothing to guess — which also means Doctrine can describe this table and
 * uninstall can find it without anybody remembering it exists.
 *
 * `dimension` is stored alongside so `dimension()` can answer without decoding a vector, and so a
 * width mismatch after an embedding-model change is caught by the same `StoreWidth` message the
 * MariaDB store produces.
 *
 * `document_name` is denormalised onto the passage rather than joined from `swag_assistant_document`.
 * `ShopInfoPassage` carries it, the MariaDB store keeps it in the vector row's metadata for the same
 * reason, and a join would make a passage unreadable the moment its document row is gone — which is
 * exactly when somebody is trying to work out what the assistant just answered from.
 */
class Migration1788393600CreateShopInfoPassages extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1788393600;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, as in every migration here: a schema
     *         change that cannot apply must fail loudly rather than leave a plugin that boots and
     *         then errors on the first indexing run
     */
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_assistant_shop_info_passage` (
                `id` BINARY(16) NOT NULL,
                `document_id` BINARY(16) NOT NULL,
                `document_name` VARCHAR(255) NOT NULL,
                `sales_channel_id` VARCHAR(32) NOT NULL,
                `section` VARCHAR(255) NOT NULL DEFAULT '',
                `text` LONGTEXT NOT NULL,
                `vector` JSON NOT NULL,
                `dimension` INT(11) NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.swag_assistant_shop_info_passage.channel` (`sales_channel_id`),
                KEY `idx.swag_assistant_shop_info_passage.document` (`document_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. Dropping this would destroy the merchant's indexed documents on a
        // plugin UPDATE; removal belongs to uninstall, where AssistantTableRemoval does it and only
        // when the merchant declined to keep their data.
    }
}
```

**No foreign key to `swag_assistant_document`.** The vector store has never had one either — the
passages carry the document id as metadata — and adding one only here would make the two stores
behave differently on a deleted document. `deleteDocument()` removes rows explicitly in both.

- [ ] **Step 4: Add the table to the removal list**

In `src/PluginLifecycle/AssistantTableRemoval.php`, add `'swag_assistant_shop_info_passage',` to `TABLES`, after `ShopInfoVectorTable::TABLE`.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Migration/ tests/PluginLifecycle/ --exclude-group eval`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Migration/Migration1788393600CreateShopInfoPassages.php src/PluginLifecycle/AssistantTableRemoval.php tests/Migration/CreateShopInfoPassagesTest.php
git commit -m "feat(shop-info): add the portable passage table"
```

---

### Task 4: The portable store

**Files:**
- Create: `src/ShopInfo/DalPortablePassageStore.php`
- Test: `tests/ShopInfo/DalPortablePassageStoreTest.php`

**Interfaces:**
- Consumes: `PassageRanking::of()` (Task 2), the table from Task 3
- Produces: `DalPortablePassageStore implements PassageStore`, constructor `__construct(Connection $connection)`

**Note on test depth:** this repo has no database integration tests — `InvertKillSwitchTest` mocks
`Connection` and asserts the SQL it receives, and that is the pattern here. The SQL's *correctness
against a real MySQL* is therefore NOT covered; Task 7 verifies it once by hand.

- [ ] **Step 1: Write the failing test**

Create `tests/ShopInfo/DalPortablePassageStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;
use Swag\AssistantStarterKit\ShopInfo\DalPortablePassageStore;

/**
 * The store for shops without MariaDB's vector support.
 *
 * It ranks in PHP because it has to: MySQL Community has no distance function at any version —
 * `DISTANCE()` is HeatWave-only — so similarity cannot be computed in SQL. The corpus this serves is
 * a shop's legal pages and uploads, so a scan is not a compromise; it is also the arithmetic the eval
 * suite has always measured, since `ShopInfoFixture` uses `InMemoryPassageStore`.
 */
final class DalPortablePassageStoreTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testItStoresOneRowPerPassage(): void
    {
        $inserted = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function () use (&$inserted): int {
                ++$inserted;

                return 1;
            },
        );

        (new DalPortablePassageStore($connection))->add(
            [
                new ShopInfoPassage('doc-1', 'terms.txt', 'Scope', 'first'),
                new ShopInfoPassage('doc-1', 'terms.txt', 'Returns', 'second'),
            ],
            [[1.0, 0.0], [0.0, 1.0]],
            self::CHANNEL,
        );

        self::assertSame(2, $inserted);
    }

    public function testMismatchedCountsAreRefusedBeforeAnythingIsWritten(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\RuntimeException::class);

        (new DalPortablePassageStore($connection))->add(
            [new ShopInfoPassage('doc-1', 'terms.txt', 'Scope', 'first')],
            [[1.0, 0.0], [0.0, 1.0]],
            self::CHANNEL,
        );
    }

    public function testItRanksTheRowsItReadAndHonoursTheThreshold(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['document_id' => 'doc-1', 'document_name' => 'terms.txt', 'section' => 'Far', 'text' => 'far', 'vector' => '[0,1]'],
            ['document_id' => 'doc-1', 'document_name' => 'terms.txt', 'section' => 'Near', 'text' => 'near', 'vector' => '[1,0]'],
        ]);

        $found = (new DalPortablePassageStore($connection))->query([1.0, 0.0], self::CHANNEL, 0.5, 10);

        self::assertCount(1, $found);
        self::assertSame('Near', $found[0]->section);
    }

    public function testTheQueryFiltersBySalesChannel(): void
    {
        $params = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $given) use (&$params): array {
                $params = $given;

                return [];
            },
        );

        (new DalPortablePassageStore($connection))->query([1.0, 0.0], self::CHANNEL, 0.4, 3);

        self::assertContains(self::CHANNEL, $params);
    }

    public function testDeletingADocumentRemovesItsRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(2);

        (new DalPortablePassageStore($connection))->deleteDocument('doc-1');
    }

    public function testDimensionIsNullOnAnEmptyStore(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        self::assertNull((new DalPortablePassageStore($connection))->dimension());
    }

    public function testDimensionIsTheStoredWidth(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1024);

        self::assertSame(1024, (new DalPortablePassageStore($connection))->dimension());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ShopInfo/DalPortablePassageStoreTest.php --exclude-group eval`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the store**

Create `src/ShopInfo/DalPortablePassageStore.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;

/**
 * Keeps passages in an ordinary table and compares them in PHP.
 *
 * **Not a lesser version of the MariaDB store — a different trade.** MySQL Community has no distance
 * function at any version (`DISTANCE()` is HeatWave-only), so similarity cannot be computed in SQL
 * there at all. What this gives up is the index; what it gains is running everywhere Shopware does,
 * exact rather than approximate results, and full float64 precision instead of the vector type's
 * float32.
 *
 * The corpus is a shop's legal pages and its own uploads — tens to low hundreds of passages — so the
 * scan is not a compromise at this size. It is also the arithmetic this project has always measured:
 * `Eval\ShopInfoFixture` runs the journeys over `InMemoryPassageStore`, which ranks the same way.
 */
final class DalPortablePassageStore implements PassageStore
{
    private const TABLE = 'swag_assistant_shop_info_passage';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @param list<ShopInfoPassage> $passages
     * @param list<list<float>>     $vectors
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function add(array $passages, array $vectors, string $salesChannelId): void
    {
        // Checked before the first insert, not per row: a half-written document is worse than a
        // refused one, because nothing downstream can tell it apart from a complete one.
        if (\count($passages) !== \count($vectors)) {
            throw new \RuntimeException(\sprintf(
                'Got %d passages and %d vectors; they must correspond one to one.',
                \count($passages),
                \count($vectors),
            ));
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        foreach ($passages as $index => $passage) {
            $vector = $vectors[$index];

            $this->connection->executeStatement(
                'INSERT INTO `' . self::TABLE . '` '
                . '(`id`, `document_id`, `document_name`, `sales_channel_id`, `section`, `text`, `vector`, `dimension`, `created_at`) '
                . 'VALUES (:id, :documentId, :documentName, :channel, :section, :text, :vector, :dimension, :createdAt)',
                [
                    'id' => Uuid::randomBytes(),
                    'documentId' => Uuid::fromHexToBytes($passage->documentId),
                    'documentName' => $passage->documentName,
                    'channel' => $salesChannelId,
                    'section' => $passage->section,
                    'text' => $passage->text,
                    'vector' => json_encode($vector, \JSON_THROW_ON_ERROR),
                    'dimension' => \count($vector),
                    'createdAt' => $now,
                ],
            );
        }
    }

    /**
     * @param list<float> $vector
     *
     * @return list<ShopInfoPassage>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function query(array $vector, string $salesChannelId, float $minScore, int $limit): array
    {
        // Every row for this channel, and no LIMIT: the ranking happens in PHP, so narrowing here
        // would discard candidates before anything had scored them. The channel filter is not an
        // optimisation — spec R12 — two channels have genuinely different terms.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`document_id`)) document_id, `document_name`, `section`, `text`, `vector` '
            . 'FROM `' . self::TABLE . '` WHERE `sales_channel_id` = :channel',
            ['channel' => $salesChannelId],
        );

        $candidates = [];

        foreach ($rows as $row) {
            $stored = json_decode((string) $row['vector'], true);

            // A row whose vector will not decode is skipped rather than fatal: one corrupt row must
            // not cost the shopper every other passage in the shop.
            if (!\is_array($stored)) {
                continue;
            }

            $candidates[] = [
                'passage' => new ShopInfoPassage(
                    (string) $row['document_id'],
                    (string) $row['document_name'],
                    (string) $row['section'],
                    (string) $row['text'],
                ),
                'vector' => array_map(static fn(mixed $value): float => (float) $value, array_values($stored)),
            ];
        }

        return PassageRanking::of($vector, $candidates, $minScore, $limit);
    }

    /** @throws \Doctrine\DBAL\Exception */
    public function deleteDocument(string $documentId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM `' . self::TABLE . '` WHERE `document_id` = :documentId',
            ['documentId' => Uuid::fromHexToBytes($documentId)],
        );
    }

    /**
     * The width the store currently holds, or null when it holds nothing.
     *
     * Read from the column rather than by decoding a vector, and from any row: `StoreWidth` already
     * guarantees one width per store, so the first row answers for all of them.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function dimension(): ?int
    {
        $dimension = $this->connection->fetchOne('SELECT `dimension` FROM `' . self::TABLE . '` LIMIT 1');

        return \is_numeric($dimension) ? (int) $dimension : null;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/ShopInfo/ --exclude-group eval`
Expected: PASS.

- [ ] **Step 5: Run the gate and commit**

```bash
composer test && composer format && composer lint && composer typecheck && composer quality:filesize
git add src/ShopInfo/DalPortablePassageStore.php tests/ShopInfo/DalPortablePassageStoreTest.php
git commit -m "feat(shop-info): add a passage store that runs on any database"
```

---

### Task 5: The chooser, and the dependency it makes optional

**Files:**
- Create: `src/ShopInfo/PassageStoreChooser.php`
- Modify: `src/Resources/config/services.xml` — the `PassageStore` alias becomes the chooser's factory
- Modify: `composer.json` — `symfony/ai-maria-db-store` moves from `require` to `suggest`
- Test: `tests/ShopInfo/PassageStoreChooserTest.php`

**Interfaces:**
- Consumes: `ShopInfoAvailability::isAvailable()`, `AiStorePassageStore`, `DalPortablePassageStore`
- Produces: `PassageStoreChooser::choose(ShopInfoAvailability $availability, AiStorePassageStore $mariaDb, DalPortablePassageStore $portable): PassageStore`

- [ ] **Step 1: Write the failing test**

Create `tests/ShopInfo/PassageStoreChooserTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;
use Swag\AssistantStarterKit\Core\ShopInfo\VectorSupport;
use Swag\AssistantStarterKit\ShopInfo\AiStorePassageStore;
use Swag\AssistantStarterKit\ShopInfo\DalPortablePassageStore;
use Swag\AssistantStarterKit\ShopInfo\PassageStoreChooser;
use Swag\AssistantStarterKit\ShopInfo\ShopInfoVectorTable;

/**
 * Which store answers, decided once per request from a live probe.
 *
 * The direction that matters most is the first one: a shop that CAN run the indexed store must get
 * it. Falling back where the fast path exists would be a silent performance regression on exactly
 * the shops with the largest document sets, and no test above this one would notice.
 */
final class PassageStoreChooserTest extends TestCase
{
    private function availability(bool $vectorsWork): ShopInfoAvailability
    {
        $vectors = new class($vectorsWork) implements VectorSupport {
            public function __construct(private readonly bool $works) {}

            public function isAvailable(): bool
            {
                return $this->works;
            }

            public function describe(): string
            {
                return $this->works ? 'MariaDB 11.8' : 'MySQL 8.0.46';
            }
        };

        return new ShopInfoAvailability($vectors, packagesInstalled: true);
    }

    private function mariaDb(): AiStorePassageStore
    {
        return new AiStorePassageStore(new ShopInfoVectorTable($this->createStub(Connection::class)));
    }

    private function portable(): DalPortablePassageStore
    {
        return new DalPortablePassageStore($this->createStub(Connection::class));
    }

    public function testItPrefersTheMariaDbStoreWhenTheDatabaseCanRunIt(): void
    {
        $store = PassageStoreChooser::choose($this->availability(true), $this->mariaDb(), $this->portable());

        self::assertInstanceOf(AiStorePassageStore::class, $store);
    }

    public function testItFallsBackToThePortableStoreOtherwise(): void
    {
        $store = PassageStoreChooser::choose($this->availability(false), $this->mariaDb(), $this->portable());

        self::assertInstanceOf(DalPortablePassageStore::class, $store);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ShopInfo/PassageStoreChooserTest.php --exclude-group eval`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the chooser and wire it**

`PassageStoreChooser::choose()` returns the MariaDB store when
`$availability->isAvailable() && ShopInfoAvailability::packagesInstalled()`, the portable one
otherwise. In `services.xml`, replace the `PassageStore` alias with a factory service:

```xml
        <service id="Swag\AssistantStarterKit\ShopInfo\DalPortablePassageStore">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
        </service>

        <service id="Swag\AssistantStarterKit\Core\ShopInfo\PassageStore">
            <factory class="Swag\AssistantStarterKit\ShopInfo\PassageStoreChooser" method="choose"/>
            <argument type="service" id="Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability"/>
            <argument type="service" id="Swag\AssistantStarterKit\ShopInfo\AiStorePassageStore"/>
            <argument type="service" id="Swag\AssistantStarterKit\ShopInfo\DalPortablePassageStore"/>
        </service>
```

**`AiStorePassageStore` must become lazy**, or the container instantiates it — and its class file
references `Symfony\AI\Store\Bridge\MariaDb\Store` — on a shop that does not have the package. Add
`lazy="true"` to its service definition and verify by running `composer test` with the package
removed from `vendor/`, or accept the constraint that it stays in `require`. **If lazy loading does
not hold, stop and report: keeping `ai-maria-db-store` in `require` is better than a container that
fatals.**

- [ ] **Step 4: Move the dependency in composer.json**

Remove `"symfony/ai-maria-db-store": "0.12.*"` from `require`, add to `suggest`:

```json
    "suggest": {
        "symfony/ai-maria-db-store": "0.12.* — indexes shop-information vectors natively on MariaDB 11.7+. Without it, the assistant compares them in PHP, which is fine for a few hundred passages."
    }
```

- [ ] **Step 5: Run the gate**

Run: `composer test && composer format && composer lint && composer typecheck && composer quality:depcheck`
Expected: all pass. `quality:depcheck` may report the moved package as unused-in-require — that is the
intended state; if it errors, add it to `composer-dependency-analyser.php`'s ignore list with a
comment naming this task.

- [ ] **Step 6: Commit**

```bash
git add src/ShopInfo/PassageStoreChooser.php src/Resources/config/services.xml composer.json tests/ShopInfo/PassageStoreChooserTest.php
git commit -m "feat(shop-info): prefer the MariaDB store, fall back to the portable one"
```

---

### Task 6: The fallback leaves evidence

**Files:**
- Modify: `src/Core/Tool/Factory/SearchShopInfoToolFactory.php` — record which store answered
- Test: `tests/Core/Tool/Factory/SearchShopInfoToolFactoryStoreTraceTest.php`

**Interfaces:**
- Consumes: `PassageStoreChooser` (Task 5), `ToolContext::$trace`
- Produces: trace stage `retrieve.shopinfo.store` with payload `{store: 'mariadb'|'portable', reason?: string}`

**Why here:** the factory already holds `$context->trace` and constructs the tool once per turn, so it
can name the store without widening the `PassageStore` interface — which `docs/extending.md`
publishes as an extension point, and where a new method would break every store somebody else wrote.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Tool/Factory/SearchShopInfoToolFactoryStoreTraceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool\Factory;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Tests\Core\Config\FakeSystemConfigService;
use Swag\AssistantStarterKit\Core\ShopInfo\EmbedderFactory;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;
use Swag\AssistantStarterKit\Core\ShopInfo\VectorSupport;
use Swag\AssistantStarterKit\Core\Tool\Factory\SearchShopInfoToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\ShopInfo\DalPortablePassageStore;

/**
 * The evidence spec D6 requires.
 *
 * Moving `symfony/ai-maria-db-store` to `suggest` changes behaviour for MariaDB shops in a way that is
 * easy to miss, because retrieval still works: package absent means an O(n) scan instead of an index,
 * experienced only as "it got slower". Replacing an honest error with an invisible degradation is the
 * opposite of what the rest of this project does, so the choice is recorded on every turn.
 *
 * Recorded by the FACTORY, not the store: `PassageStore` is published as an extension point in
 * `docs/extending.md`, and a new method on it would break every store somebody else wrote.
 */
final class SearchShopInfoToolFactoryStoreTraceTest extends TestCase
{
    private function availability(bool $vectorsWork): ShopInfoAvailability
    {
        $vectors = new class($vectorsWork) implements VectorSupport {
            public function __construct(private readonly bool $works) {}

            public function isAvailable(): bool
            {
                return $this->works;
            }

            public function describe(): string
            {
                return $this->works ? 'MariaDB 11.8' : 'MySQL 8.0.46';
            }
        };

        return new ShopInfoAvailability($vectors, packagesInstalled: true);
    }

    private function factory(bool $vectorsWork): SearchShopInfoToolFactory
    {
        // `SystemConfigLlmSettings` is `final readonly`, so PHPUnit cannot stub it — build the real
        // one over `FakeSystemConfigService`, the way `SystemConfigLlmSettingsTest` already does.
        // Nothing in this test reaches the provider: the factory never embeds anything.
        return new SearchShopInfoToolFactory(
            new EmbedderFactory(new SystemConfigLlmSettings(new FakeSystemConfigService([]))),
            new DalPortablePassageStore($this->createStub(Connection::class)),
            $this->availability($vectorsWork),
        );
    }

    private function context(TraceRecorder $trace): ToolContext
    {
        return new ToolContext($trace, new AssistantConfig(embeddingModel: 'baai/bge-m3'));
    }

    public function testItNamesTheMariaDbStoreOnAShopThatCanRunIt(): void
    {
        $trace = new TraceRecorder();
        $this->factory(true)->create($this->context($trace));

        self::assertSame('mariadb', $trace->payload('retrieve.shopinfo.store')['store'] ?? null);
    }

    public function testItNamesThePortableStoreAndWhyOnAShopThatCannot(): void
    {
        $trace = new TraceRecorder();
        $this->factory(false)->create($this->context($trace));

        $payload = $trace->payload('retrieve.shopinfo.store');

        self::assertSame('portable', $payload['store'] ?? null);
        self::assertStringContainsString('MariaDB', (string) ($payload['reason'] ?? ''));
    }

    /**
     * Nothing is recorded when the feature is off. A trace line about a store that never answered
     * would be noise on every product turn in the shop.
     */
    public function testItRecordsNothingWhenShopKnowledgeIsSwitchedOff(): void
    {
        $trace = new TraceRecorder();
        $this->factory(true)->create(new ToolContext($trace, new AssistantConfig()));

        self::assertNull($trace->payload('retrieve.shopinfo.store'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Tool/Factory/SearchShopInfoToolFactoryStoreTraceTest.php --exclude-group eval`
Expected: FAIL — no such trace event.

- [ ] **Step 3: Record the event**

In `SearchShopInfoToolFactory::create()`, after the `embeddingModel === ''` early return, record:

```php
        $context->trace->record('retrieve.shopinfo.store', array_filter([
            'store' => $this->availability->isAvailable() ? 'mariadb' : 'portable',
            'reason' => $this->availability->unmetRequirement(),
        ], static fn(?string $value): bool => $value !== null));
```

taking `ShopInfoAvailability` as a third constructor argument. Wire it in `services.xml`:

```xml
        <service id="Swag\AssistantStarterKit\Core\Tool\Factory\SearchShopInfoToolFactory">
            <argument type="service" id="Swag\AssistantStarterKit\Core\ShopInfo\EmbedderFactory"/>
            <argument type="service" id="Swag\AssistantStarterKit\Core\ShopInfo\PassageStore"/>
            <argument type="service" id="Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability"/>
            <tag name="swag_assistant.grounded_tool_factory"/>
        </service>
```

Keep whatever `<tag>` the existing definition carries — copy it rather than retyping it, or the tool
silently leaves the toolbox.

- [ ] **Step 4: Run the tests and commit**

```bash
composer test && composer format && composer lint && composer typecheck
git add src/Core/Tool/Factory/SearchShopInfoToolFactory.php src/Resources/config/services.xml tests/Core/Tool/Factory/SearchShopInfoToolFactoryStoreTraceTest.php
git commit -m "feat(shop-info): record which passage store answered"
```

---

### Task 7: Verify it against a real database, and document what changed

**Files:**
- Modify: `README.md` — the two shop-information prerequisites written on 2026-08-31
- Modify: `docs/superpowers/specs/2026-08-31-portable-passage-store-design.md` — status line

**No new test.** This task is the live verification the unit tests structurally cannot do: every store
test in this repo mocks `Connection`, so no test in Task 3–5 has executed a single statement against a
database.

- [ ] **Step 1: Deploy to the local shop**

```bash
rsync -a src/ ~/Workspace/shopping-assistant-test/  # or rely on the bind mount
docker exec shopping-assistant-test-web-1 sh -c 'cd /var/www/html && APP_ENV=prod bin/console database:migrate SwagAssistantStarterKit --all && APP_ENV=prod bin/console cache:clear'
```

- [ ] **Step 2: Confirm the MariaDB path is still chosen there**

The local shop runs MariaDB 11.8. Index a document and ask a question through
`POST /assistant/chat`, then read the trace:

```
SELECT payload FROM swag_assistant_trace_event WHERE stage='retrieve.shopinfo.store' ORDER BY created_at DESC LIMIT 1;
```

Expected: `{"store":"mariadb"}`. **If this says `portable`, stop** — the chooser is preferring the
wrong store on a shop that can run the fast one, which is the one regression this whole plan must not
introduce.

- [ ] **Step 3: Verify the portable path on staging**

Staging runs MySQL 8.0.46. Deploy per `staging-shop-access-and-deploy`, including
`assets:install` and `cache:clear`, run the migrations, switch Shop knowledge on, index the shop
pages, and ask the revocation question in German and in English. Expected: an answer, and
`{"store":"portable","reason":"…"}` in the trace.

- [ ] **Step 4: Update the README**

Rewrite the two shop-information bullets: MariaDB 11.7+ and `symfony/ai-maria-db-store` are now
**recommended, not required** — they index the vectors and stay fast on a large document set. Keep the
sentence explaining that no MySQL version can serve the MariaDB store, because it is the fact that
stops someone upgrading MySQL in the belief it will help.

- [ ] **Step 5: Commit**

```bash
git add README.md docs/superpowers/specs/2026-08-31-portable-passage-store-design.md
git commit -m "docs: shop knowledge runs on any database, MariaDB stays the fast path"
```

---

## Self-review notes

**Spec coverage.** D1 → Task 5. D2 → Task 1. D3 → Task 3. D4 → Task 2. D5 → Task 5. D6 → Task 6.
D7 → Task 3 step 4. Requirement 3 (merchant-readable) → Task 1 step 5. Requirement 5 (never throws) →
inherited, and Task 5's lazy-loading note is the one place it could regress.

**Known gap, carried deliberately.** The spec's D6 also asks for a line on the shop-information admin
screen naming the active store, and the re-index notice for a shop whose other store holds passages.
That is a Vue change in `src/Resources/app/administration/src/module/swag-assistant-shop-info/`,
needs `shopware-cli extension build .` plus `assets:install` and `theme:compile` to deploy, and its
own snippets in `snippet/de-DE.json` and `snippet/en-GB.json`. It is **not** in this plan: the trace
event in Task 6 makes the fallback discoverable to whoever debugs it, and the admin line makes it
discoverable to the merchant. Only the second is missing, and it is a separate piece of work with a
different toolchain. **Do not close this plan as "D6 done" without saying that half of it is open.**
