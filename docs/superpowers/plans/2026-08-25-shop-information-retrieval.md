# Shop Information Retrieval — Implementation Plan (Part 1: the chain)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the assistant able to answer a question about the shop's own documents — revocation, privacy, shipping — from text the merchant supplied, or say plainly that it cannot find it.

**Architecture:** `symfony/ai-store` supplies the embedding and vector plumbing against the shop's existing MariaDB. Everything model-facing is this project's own: one tool, its reply shape, a similarity threshold, and trace events. Ingestion runs through a CLI in this plan; the admin UI is Part 2.

**Tech Stack:** PHP 8.2 target (running 8.5), PHPUnit 11, Mago, Shopware 6.7.13, MariaDB 11.8, `symfony/ai-store` + `symfony/ai-maria-db-store`.

**Spec:** `docs/superpowers/specs/2026-08-25-shop-information-retrieval-design.md` — read it first. Decisions R2 (our tool, their infrastructure), R3/R4 (threshold, and that its value is measured not chosen) and R12 (sales-channel isolation) are the three a later reader is most likely to "simplify" and the three that would cost the most.

**Part 2, not in this plan:** the admin module (upload, list, delete, re-index). This plan ships a CLI so the whole chain is provable before Vue components are built on top of it.

## Global Constraints

- `declare(strict_types=1)` in every PHP file. Mago analyze runs at full strictness — no `mixed`, no unsafe casts.
- **Mago's thresholds are per class and they are errors, not warnings.** Neither is in `mago.toml`: `too-many-methods` fires at **11 methods**, and `cyclomatic-complexity` (10) is **summed across every method of a class** — moving branches into another method of the same class does not help. `excessive-parameter-list` fires above **5 parameters**, and promoted constructor properties count.
- `composer run quality` fails only on `error[…]`; the repo carries ~90 lint and ~250 analyze warnings as its normal state. Judge a run by `grep 'error\['`, never by the issue total.
- ~400 lines per file (`composer run quality:filesize`, `src` only — and it fires).
- Declare array shapes with `@phpstan-type` / `@phpstan-import-type`. `array<string, mixed>` makes every downstream field access `mixed` and produces dozens of analyze errors.
- PHPUnit's `assertNotNull()` does **not** narrow a nullable for the analyzer. Use a lookup that throws, or `?? self::fail(…)`.
- Run `composer run format` **before** staging. `mago fmt` collapses multi-line calls, which silently breaks string-match patching, and the `pre-commit` hook rejects unformatted staged PHP. The hook never runs `mago analyze`, so run `composer run quality` before believing a commit is clean.
- **The gateway seam.** `CommerceGatewayInterface`'s docblock: only DTOs from `Dto\` may cross, never a Shopware entity, never `SalesChannelContext`. Everything under `Core\` obeys this; only `src/Command/`, `src/Controller/`, `src/Entity/`, `src/Migration/` and `Core\Commerce\Dal\` may name a Shopware type.
- **A new dependency must be declared,** not used transitively. `composer run quality:depcheck` catches shadow use, as it did for `psr/cache` this week.
- Working against the local shop: it is `~/Workspace/shopping-assistant-test`, `docker compose`, containers `shopping-assistant-test-{web,database}-1`. The stack is often stopped — `docker ps -a`, then `docker compose up -d`. Console commands that touch demo data or need the prod container need `APP_ENV=prod` and a `cache:clear` in that environment before a new command is visible. DB client binaries are `mariadb` and `mariadb-dump`, **not** `mysql`/`mysqldump`. Credentials come from that directory's `.env` `DATABASE_URL`.

## What was already verified — do not re-investigate

Measured 2026-08-25. Every row is a fact this plan builds on.

| Fact | Evidence |
|---|---|
| MariaDB 11.8.8, native vectors work | `VECTOR(4)` + `VECTOR INDEX` + `VEC_DISTANCE_COSINE` executed, correct ordering |
| `symfony/ai-maria-db-store` exists, needs MariaDB ≥ 11.7 | package list + bridge README |
| Store query supports what R3/R12 need | `query()` takes `limit`, `minScore`, `filter`; `VectorDocument::getScore()` |
| PDF extraction, no new package | `smalot/pdfparser` v2.12.5 present via `horstoeko/zugferd`; round-tripped a generated PDF |
| HTML extraction, no new package | `masterminds/html5` present; block elements → lines, `<script>` strippable |
| DOCX extraction, no package at all | `ZipArchive` built in; `word/document.xml`, `</w:p>` → newline, `strip_tags`; three `<w:r>` runs joined into one correct sentence |
| Legal pages are addressable | `core.basicInformation.{imprintPage,privacyPage,revocationPage,tosPage,shippingPaymentInfoPage,contactPage}` hold real page ids |
| Tool tiers | `ToolFactoryInterface::create(ToolContext): ?object` — **returning null is how a tool is disabled**, and it never reaches the schema the model sees |
| `ToolContext` carries only `trace` and `config` | read; nothing above the gateway knows the sales channel — see Task 1 |

## Two gaps found while planning, and how this plan closes them

**The sales-channel id has no route to a tool.** `ToolContext` carries `trace` and `config` only.
`AssistantConfig` is built by `SystemConfigAssistantConfig::forSalesChannel($id)`, so it is already
scoped to one channel — it simply does not record which. Task 1 adds `salesChannelId` to it. Every
tool that has the config then has the tenant, with no change to `ToolContext` or
`GroundedToolContext`, which `docs/extending.md` presents as extension points.

**The embedding dimension is not fixed but the store column is.** A vector column is `VECTOR(n)`, and
`n` depends on the model the merchant configured. Switching models silently invalidates every stored
vector. Task 2 therefore probes the dimension once by embedding a short constant string, stores it
alongside the vectors, and refuses to query when the configured model's dimension no longer matches —
telling the merchant to re-index rather than returning nonsense.

## File Structure

| File | Responsibility |
|---|---|
| `src/Resources/config/config.xml` (modify) | `embeddingModel` text field beside `llmModel` |
| `src/Core/Policy/AssistantConfig.php` (modify) | `salesChannelId` and `embeddingModel` fields |
| `src/Core/Config/SystemConfigAssistantConfig.php` (modify) | reads both |
| `src/Core/ShopInfo/ShopInfoPassage.php` (create) | One retrieved passage: document, section, text, score. A DTO, no behaviour |
| `src/Core/ShopInfo/PassageStore.php` (create) | The interface `Core` sees: `add(list<ShopInfoPassage>)`, `query(vector, salesChannelId)`, `deleteDocument(id)`. Keeps `symfony/ai` and Shopware out of `Core` |
| `src/Core/ShopInfo/Chunker.php` (create) | Text → paragraph chunks with overlap, bounded |
| `src/Core/ShopInfo/TextExtractor.php` (create) | `interface { supports(string $extension): bool; extract(string $bytes): string; }` |
| `src/Core/ShopInfo/Extractor/{Plain,Markdown,Html,Pdf,Docx}Extractor.php` (create) | One per format |
| `src/Core/ShopInfo/ExtractorChain.php` (create) | Picks the extractor, throws a named exception when none supports the file or no text comes out |
| `src/Core/ShopInfo/SearchShopInfoTool.php` (create) | The model-facing tool: threshold, reply shape, trace |
| `src/Core/Tool/Factory/SearchShopInfoToolFactory.php` (create) | Returns null when `embeddingModel` is empty (R13) |
| `src/Core/Commerce/Dal/…` — **no** | Nothing here. This feature does not touch the commerce gateway |
| `src/ShopInfo/AiStorePassageStore.php` (create) | `PassageStore` implemented over `symfony/ai-store`. Outside `Core`, because it names library types |
| `src/ShopInfo/DocumentIngestion.php` (create) | extract → chunk → embed → replace. The only writer |
| `src/Entity/AssistantDocument/{Definition,Entity,Collection}.php` (create) | Identity, status, chunk count, extracted text |
| `src/Migration/Migration…CreateAssistantDocuments.php` (create) | The entity's table |
| `src/Command/ShopInfoCommand.php` (create) | `swag:assistant:shopinfo` — index, list, delete, query |
| `src/Resources/config/services.xml` (modify) | Register the above; tag the factory |
| `docs/superpowers/reports/…-shopinfo-threshold.md` (create) | Task 7's measurement |

`Core\ShopInfo\` holds everything that must not know about the library or Shopware; `src\ShopInfo\`
holds the two classes that do. That split is what lets the tool and the chunker be unit-tested with no
database and no API key.

---

### Task 1: The setting, and the tenant

**Files:**
- Modify: `src/Resources/config/config.xml`
- Modify: `src/Core/Policy/AssistantConfig.php`
- Modify: `src/Core/Config/SystemConfigAssistantConfig.php`
- Test: `tests/Core/Config/SystemConfigAssistantConfigTest.php` (exists — append)

**Interfaces:**
- Produces: `AssistantConfig::$salesChannelId` (string, default `''`), `AssistantConfig::$embeddingModel` (string, default `''`).

- [ ] **Step 1: Write the failing test**

Append to `tests/Core/Config/SystemConfigAssistantConfigTest.php`. Read the file first — it has an
established way of faking `SystemConfigService`; reuse it rather than inventing a second one.

```php
    public function testItRecordsTheSalesChannelItWasBuiltFor(): void
    {
        $config = $this->configFor(['embeddingModel' => 'text-embedding-3-small']);

        // The tenant every shop-info query filters on. AssistantConfig is already per sales channel;
        // until now it simply did not record which one, and nothing above the gateway seam could ask.
        self::assertSame(self::SALES_CHANNEL_ID, $config->salesChannelId);
    }

    public function testTheEmbeddingModelIsReadAndDefaultsToEmpty(): void
    {
        self::assertSame('text-embedding-3-small', $this->configFor([
            'embeddingModel' => 'text-embedding-3-small',
        ])->embeddingModel);

        // Empty is the off switch for the whole feature (R13), so it must be the default rather than
        // a fallback model nobody chose.
        self::assertSame('', $this->configFor([])->embeddingModel);
    }
```

`self::SALES_CHANNEL_ID` and `configFor()` may not exist under those names — match whatever the file
already uses, and add the constant/helper if it does not.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Config/SystemConfigAssistantConfigTest.php`
Expected: FAIL — unknown property `salesChannelId`.

- [ ] **Step 3: Add the fields**

In `src/Core/Policy/AssistantConfig.php`, add two promoted properties. The class already carries
`// @mago-expect lint:excessive-parameter-list` with a documented justification — **keep using named
arguments at every call site**, because ruling R17's carve-out for that pragma is conditional on it.

```php
        /**
         * The sales channel this config was built for.
         *
         * Not a setting — it is the identity of the scope every other value here belongs to. It lives
         * on the config because `SystemConfigAssistantConfig::forSalesChannel()` already knows it and
         * because a tool that has the config then has the tenant, without widening `ToolContext`,
         * which `docs/extending.md` presents as an extension point.
         *
         * Shop-info retrieval filters on it (spec R12): two channels can have different terms and
         * conditions, and a store query without this filter serves one channel's revocation notice in
         * another.
         */
        public string $salesChannelId = '',
        /**
         * The embedding model, empty when the merchant has not configured one.
         *
         * Empty means shop-info retrieval is entirely off: no tool in the toolbox, no ingestion (spec
         * R13). A starter kit offering a tool that always fails is worse than one offering no tool.
         */
        public string $embeddingModel = '',
```

In `src/Core/Config/SystemConfigAssistantConfig.php::forSalesChannel()`, add to the constructor call:

```php
            salesChannelId: $salesChannelId,
            embeddingModel: trim($this->systemConfig->getString(self::PREFIX . 'embeddingModel', $salesChannelId)),
```

- [ ] **Step 4: Add the config field**

In `src/Resources/config/config.xml`, directly after the `llmModel` field:

```xml
        <input-field type="text">
            <name>embeddingModel</name>
            <label>Embedding model</label>
            <label lang="de-DE">Embedding-Modell</label>
            <helpText>Used to index shop documents and to match a question against them. Uses the
                same provider URL and API key as the chat model. Leave empty to switch shop
                information off entirely. Changing it makes every indexed document unusable —
                re-index after a change.</helpText>
        </input-field>
```

- [ ] **Step 5: Verify**

Run: `vendor/bin/phpunit --exclude-group eval`
Expected: PASS. Nothing reads the new fields yet, so nothing else can break — if something does, a
call site is passing positional arguments and must be fixed to named ones.

Run: `composer run quality` → exit 0.

- [ ] **Step 6: Commit**

```bash
composer run format
git add src/Resources/config/config.xml src/Core/Policy/AssistantConfig.php \
        src/Core/Config/SystemConfigAssistantConfig.php tests/Core/Config/SystemConfigAssistantConfigTest.php
git commit -m "feat(shopinfo): an embedding model setting, and a config that knows its channel"
```

---

### Task 2: The store, behind an interface of our own

**Files:**
- Create: `src/Core/ShopInfo/ShopInfoPassage.php`
- Create: `src/Core/ShopInfo/PassageStore.php`
- Create: `src/ShopInfo/AiStorePassageStore.php`
- Modify: `composer.json`, `src/Resources/config/services.xml`
- Test: `tests/Core/ShopInfo/InMemoryPassageStore.php` (a test double, used by later tasks)

**Interfaces:**
- Produces:
  - `ShopInfoPassage::__construct(string $documentId, string $documentName, string $section, string $text, float $score = 0.0)`
  - `PassageStore::add(array $passages, array $vectors, string $salesChannelId): void`
  - `PassageStore::query(array $vector, string $salesChannelId, float $minScore, int $limit): array` → `list<ShopInfoPassage>` with scores set
  - `PassageStore::deleteDocument(string $documentId): void`
  - `PassageStore::dimension(): ?int` — the width the store was built with, null when empty

- [ ] **Step 1: Install the packages and read the bridge's own README**

```bash
composer require symfony/ai-store symfony/ai-maria-db-store
ls vendor/symfony/ai-maria-db-store/
cat vendor/symfony/ai-maria-db-store/README.md
```

**Do not guess the bridge's class name or factory signature from this plan.** The SQLite bridge uses
`VecStore::fromDbal($connection, $table, Distance::Cosine, $dimension)`; the MariaDB bridge is
expected to be the same shape under a different class, but that is an expectation, not a verified
fact. Take the real names from that README and from the classes in `vendor/symfony/ai-maria-db-store/src/`.
If the shape differs from what Step 4 assumes, adjust Step 4 and say so in the commit message.

- [ ] **Step 2: Write the DTO and the interface**

Create `src/Core/ShopInfo/ShopInfoPassage.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * One retrieved piece of a shop document.
 *
 * `text` is the payload the model receives and may paraphrase (spec R6) — note the asymmetry with
 * products, where the model gets no figures because the rendered card carries them. There is no card
 * here, so the text is what there is.
 *
 * `score` is set by a query and meaningless on a passage being written; it is on this class rather
 * than in a parallel array because the threshold decision (R3) and the trace of rejected scores (R5)
 * both need it beside the passage it belongs to.
 */
final readonly class ShopInfoPassage
{
    public function __construct(
        public string $documentId,
        public string $documentName,
        public string $section,
        public string $text,
        public float $score = 0.0,
    ) {}
}
```

Create `src/Core/ShopInfo/PassageStore.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Where passages live, as `Core` is allowed to see it.
 *
 * **Its own interface rather than `symfony/ai`'s `StoreInterface`**, for the same reason `Core` may
 * not name a Shopware type: an implementation may use a Symfony bridge, a different vector database,
 * or an array, and nothing above this line should be able to tell. It is also what lets
 * {@see SearchShopInfoTool} be unit-tested with a ten-line fake instead of a configured store, an API
 * key and a database.
 *
 * **Every method takes or filters by a sales-channel id.** The legal pages behind these passages are
 * configured per channel, so two channels genuinely have different terms; a query without that filter
 * serves one shop's revocation notice in another (spec R12). It is a parameter rather than
 * constructor state so a single store instance can serve a multi-channel shop.
 */
interface PassageStore
{
    /**
     * @param list<ShopInfoPassage> $passages
     * @param list<list<float>>     $vectors  one per passage, same order, same length
     *
     * @throws \RuntimeException when the counts disagree or a vector's width differs from the store's
     */
    public function add(array $passages, array $vectors, string $salesChannelId): void;

    /**
     * Passages above `$minScore`, most similar first, with their scores set.
     *
     * @param list<float> $vector
     *
     * @return list<ShopInfoPassage>
     */
    public function query(array $vector, string $salesChannelId, float $minScore, int $limit): array;

    /** Every passage of one document, so a new version can replace the old one (spec R8). */
    public function deleteDocument(string $documentId): void;

    /**
     * The vector width this store holds, or null when it holds nothing.
     *
     * The store's column is `VECTOR(n)` while the model is a merchant setting, so switching models
     * invalidates everything already stored. Callers compare this against the width the configured
     * model produces and refuse rather than return nonsense — see the plan's *Two gaps* section.
     */
    public function dimension(): ?int;
}
```

- [ ] **Step 3: Write the in-memory double the rest of the plan uses**

Create `tests/Core/ShopInfo/InMemoryPassageStore.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;

/**
 * @internal a store that lives in this process, so the tool and the chunker are testable with no
 *           database, no API key and no embedding call
 */
final class InMemoryPassageStore implements PassageStore
{
    /** @var list<array{passage: ShopInfoPassage, vector: list<float>, salesChannelId: string}> */
    private array $rows = [];

    public function add(array $passages, array $vectors, string $salesChannelId): void
    {
        foreach ($passages as $index => $passage) {
            $this->rows[] = [
                'passage' => $passage,
                'vector' => $vectors[$index] ?? [],
                'salesChannelId' => $salesChannelId,
            ];
        }
    }

    public function query(array $vector, string $salesChannelId, float $minScore, int $limit): array
    {
        $scored = [];

        foreach ($this->rows as $row) {
            if ($row['salesChannelId'] !== $salesChannelId) {
                continue;
            }

            $score = self::cosine($vector, $row['vector']);

            if ($score < $minScore) {
                continue;
            }

            $passage = $row['passage'];
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

    public function deleteDocument(string $documentId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => $row['passage']->documentId !== $documentId,
        ));
    }

    public function dimension(): ?int
    {
        $first = $this->rows[0] ?? null;

        return $first === null ? null : \count($first['vector']);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $other = $b[$i] ?? 0.0;
            $dot += $value * $other;
            $normA += $value ** 2;
            $normB += $other ** 2;
        }

        $magnitude = sqrt($normA) * sqrt($normB);

        return $magnitude === 0.0 ? 0.0 : $dot / $magnitude;
    }
}
```

- [ ] **Step 4: Implement the real store**

Create `src/ShopInfo/AiStorePassageStore.php`. **The library class names below come from Step 1's
README** — if they differ, use the real ones.

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;

/**
 * {@see PassageStore} over `symfony/ai-store`'s MariaDB bridge, on the shop's own database.
 *
 * Lives outside `Core\` because it names library types, which `Core` may not — the same rule that
 * keeps Shopware out of `Core\Commerce`.
 *
 * **The sales channel is metadata, and the query filters on it.** Verified while designing: the
 * store's `query()` accepts `limit`, `minScore` and `filter`, and `VectorDocument::getScore()`
 * exposes the score, so both the threshold and the tenant filter are expressible without wrapping
 * anything in a second abstraction. Should the MariaDB bridge not honour `minScore`, filter on
 * `getScore()` in PHP — the interface's contract is what matters, not which layer enforces it.
 */
final class AiStorePassageStore implements PassageStore
{
    // Implementation: map ShopInfoPassage + vector to the bridge's document type with metadata
    // ['documentId' => …, 'documentName' => …, 'section' => …, 'salesChannelId' => …], and map back
    // on query. `deleteDocument()` removes by the documentId metadata. `dimension()` reads the
    // store's configured width.
}
```

The body is left to the implementer **on purpose**: it is a mechanical mapping whose exact calls come
from Step 1's README, and inventing them here would produce a plan that looks precise and is wrong.
Everything that has a decision in it — the interface, the metadata keys, the tenant filter, the
dimension guard — is specified above.

- [ ] **Step 5: Register and declare**

`services.xml`: register `AiStorePassageStore` with the bridge store built from Shopware's
`Doctrine\DBAL\Connection` service, and alias `PassageStore` to it.

**`schema_filter`.** Doctrine will try to manage the vector column and fail. The library's guidance is
`schema_filter: '~^(?!ai_)~'`, which is a `doctrine.yaml` change — a plugin cannot ship that as
config. So **name the table with a prefix Shopware's schema tooling ignores, or add the filter through
a compiler pass**, and write down which was chosen and why. Do not discover this at the first
`doctrine:schema:update`.

- [ ] **Step 6: Verify**

Run: `composer run quality:depcheck` — the two new packages must be declared in `composer.json`, not
used transitively.

Run: `vendor/bin/phpunit --exclude-group eval` → PASS.
Run: `composer run quality` → exit 0.

- [ ] **Step 7: Commit**

```bash
composer run format
git add composer.json composer.lock src/Core/ShopInfo/ src/ShopInfo/ tests/Core/ShopInfo/ src/Resources/config/services.xml
git commit -m "feat(shopinfo): a passage store Core can see without naming a vector database"
```

---

### Task 3: Text extraction, five formats

**Files:**
- Create: `src/Core/ShopInfo/TextExtractor.php`, `src/Core/ShopInfo/ExtractorChain.php`, `src/Core/ShopInfo/ExtractionFailed.php`
- Create: `src/Core/ShopInfo/Extractor/{Plain,Markdown,Html,Pdf,Docx}Extractor.php`
- Test: `tests/Core/ShopInfo/ExtractorChainTest.php`

**Interfaces:**
- Produces:
  - `interface TextExtractor { public function supports(string $extension): bool; public function extract(string $bytes): string; }`
  - `ExtractorChain::__construct(iterable $extractors)`, `ExtractorChain::extract(string $filename, string $bytes): string`
  - `ExtractionFailed extends \RuntimeException` with `::unsupported(string $extension)` and `::noText(string $filename)`

- [ ] **Step 1: Write the failing test**

Create `tests/Core/ShopInfo/ExtractorChainTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\DocxExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\HtmlExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\MarkdownExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\PdfExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\PlainExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\ExtractionFailed;
use Swag\AssistantStarterKit\Core\ShopInfo\ExtractorChain;

/**
 * Turning a merchant's file into text, for the five formats spec decision R10 admits.
 *
 * The assertion that matters most is the last one: a PDF with no text layer — a scan — must FAIL, not
 * yield an empty document. An empty document indexes cleanly, matches nothing forever, and gives the
 * merchant no reason to suspect anything.
 */
final class ExtractorChainTest extends TestCase
{
    private static function chain(): ExtractorChain
    {
        return new ExtractorChain([
            new PlainExtractor(),
            new MarkdownExtractor(),
            new HtmlExtractor(),
            new PdfExtractor(),
            new DocxExtractor(),
        ]);
    }

    public function testPlainTextPassesThrough(): void
    {
        self::assertSame('Widerrufsfrist: vierzehn Tage.', self::chain()->extract(
            'widerruf.txt',
            "Widerrufsfrist: vierzehn Tage.\n",
        ));
    }

    public function testMarkdownLosesItsSyntaxButKeepsItsParagraphs(): void
    {
        $text = self::chain()->extract('faq.md', "# Widerruf\n\nBinnen **vierzehn** Tagen.\n");

        self::assertStringContainsString('Widerruf', $text);
        self::assertStringContainsString('Binnen vierzehn Tagen.', $text);
        self::assertStringNotContainsString('**', $text);
        self::assertStringNotContainsString('#', $text);
    }

    public function testHtmlBecomesOneLinePerBlockAndDropsScripts(): void
    {
        $text = self::chain()->extract(
            'datenschutz.html',
            '<h1>Datenschutz</h1><p>Wir verarbeiten Daten.</p><script>evil()</script><ul><li>Auskunft</li></ul>',
        );

        self::assertSame("Datenschutz\nWir verarbeiten Daten.\nAuskunft", $text);
        self::assertStringNotContainsString('evil', $text);
    }

    public function testDocxParagraphsSurviveAndRunsAreJoined(): void
    {
        $text = self::chain()->extract('widerruf.docx', self::docx([
            'Widerrufsbelehrung',
            // Three separate runs in one paragraph — Word splits sentences like this constantly, and
            // a naive extractor turns them into three lines. Verified while designing that this
            // approach joins them.
            ['Sie haben das Recht, binnen ', 'vierzehn Tagen', ' zu widerrufen.'],
        ]));

        self::assertSame(
            "Widerrufsbelehrung\nSie haben das Recht, binnen vierzehn Tagen zu widerrufen.",
            $text,
        );
    }

    public function testAnUnsupportedFormatIsRefusedByName(): void
    {
        $this->expectException(ExtractionFailed::class);
        $this->expectExceptionMessageMatches('/xlsx/');

        self::chain()->extract('groessen.xlsx', 'anything');
    }

    /**
     * A scan is the failure mode that would otherwise be silent: a valid PDF with no text layer.
     * It must throw, so the document lands in `failed` status with a reason the merchant can act on.
     */
    public function testAPdfWithoutATextLayerFailsRatherThanYieldingNothing(): void
    {
        $this->expectException(ExtractionFailed::class);

        // A structurally valid PDF whose only page has no text operators.
        self::chain()->extract('scan.pdf', self::pdfWithoutText());
    }

    /**
     * @param list<string|list<string>> $paragraphs a string is one run; a list is several runs in one
     *                                              paragraph
     */
    private static function docx(array $paragraphs): string
    {
        $body = '';

        foreach ($paragraphs as $paragraph) {
            $runs = '';
            foreach (\is_array($paragraph) ? $paragraph : [$paragraph] as $run) {
                $runs .= '<w:r><w:t>' . htmlspecialchars($run, \ENT_XML1) . '</w:t></w:r>';
            }
            $body .= '<w:p>' . $runs . '</w:p>';
        }

        $xml = '<?xml version="1.0"?><w:document '
            . 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $body . '</w:body></w:document>';

        $path = tempnam(sys_get_temp_dir(), 'docx');
        self::assertIsString($path);

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private static function pdfWithoutText(): string
    {
        // dompdf is already in the tree (it ships with Shopware). An empty page produces a valid PDF
        // whose content stream holds no text-showing operator, which is what a scan looks like to a
        // text parser.
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml('<div style="width:1px;height:1px"></div>');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/ShopInfo/ExtractorChainTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the interface, the failure and the chain**

`src/Core/ShopInfo/TextExtractor.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * One file format, turned into plain text with its paragraph boundaries intact.
 *
 * Paragraphs matter more than they look: {@see Chunker} splits on them, and a chunk that begins
 * mid-sentence separates "binnen vierzehn Tagen" from "ab Erhalt der Ware" — a deadline without its
 * start date, which the model will then paraphrase as fact (spec R11).
 *
 * @api An extension point. A merchant with an in-house format implements this and tags the service
 *      `swag_assistant.text_extractor`; nothing else changes.
 */
interface TextExtractor
{
    public function supports(string $extension): bool;

    /**
     * @param string $bytes the file's raw contents
     *
     * @throws ExtractionFailed when the format is malformed or holds no extractable text
     */
    public function extract(string $bytes): string;
}
```

`src/Core/ShopInfo/ExtractionFailed.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Extraction failed, with a reason a merchant can act on.
 *
 * Named constructors rather than free-form messages because these two reasons need different
 * responses — one means "convert the file", the other means "this scan has no text in it, run OCR or
 * paste the text" — and the admin surface in Part 2 will want to tell them apart.
 */
final class ExtractionFailed extends \RuntimeException
{
    public static function unsupported(string $extension): self
    {
        return new self(\sprintf(
            'No extractor handles ".%s". Supported: pdf, txt, md, html, docx.',
            $extension,
        ));
    }

    public static function noText(string $filename): self
    {
        return new self(\sprintf(
            'No text could be extracted from "%s". A scanned PDF has no text layer — run OCR first, '
            . 'or upload the text itself.',
            $filename,
        ));
    }
}
```

`src/Core/ShopInfo/ExtractorChain.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Picks the extractor for a filename and guarantees the result is not empty.
 *
 * The empty check is here rather than in each extractor so no format can forget it. An empty document
 * is the dangerous outcome: it indexes without error, matches nothing for ever, and looks like a
 * working upload.
 */
final readonly class ExtractorChain
{
    /** @param iterable<TextExtractor> $extractors */
    public function __construct(
        private iterable $extractors,
    ) {}

    /** @throws ExtractionFailed */
    public function extract(string $filename, string $bytes): string
    {
        $extension = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));

        foreach ($this->extractors as $extractor) {
            if (!$extractor->supports($extension)) {
                continue;
            }

            $text = trim($extractor->extract($bytes));

            if ($text === '') {
                throw ExtractionFailed::noText($filename);
            }

            return $text;
        }

        throw ExtractionFailed::unsupported($extension);
    }
}
```

- [ ] **Step 4: Write the five extractors**

`PlainExtractor` — `supports('txt')`, returns `trim($bytes)`.

`MarkdownExtractor` — `supports('md'|'markdown')`. Strip heading markers, emphasis, link syntax and
code fences with `preg_replace`; keep blank lines, because they are the paragraph boundaries
{@see Chunker} needs. Do **not** add a Markdown library for this: the goal is readable prose, not
faithful rendering.

`HtmlExtractor` — `supports('html'|'htm')`. Verified approach, using `masterminds/html5` which is
already in the tree:

```php
        $dom = (new \Masterminds\HTML5())->loadHTML('<html><body>' . $bytes . '</body></html>');

        foreach (iterator_to_array($dom->getElementsByTagName('script')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach (iterator_to_array($dom->getElementsByTagName('style')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $lines = [];
        foreach ($dom->getElementsByTagName('*') as $node) {
            if (!\in_array(strtolower($node->nodeName), self::BLOCKS, true)) {
                continue;
            }

            $line = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
```

with `private const BLOCKS = ['h1', 'h2', 'h3', 'h4', 'p', 'li', 'td', 'dd'];`.

`PdfExtractor` — `supports('pdf')`, `(new \Smalot\PdfParser\Parser())->parseContent($bytes)->getText()`.
Wrap any `\Throwable` from the parser in `ExtractionFailed` preserving `$previous` — a malformed PDF
must not surface as a parser internal.

`DocxExtractor` — `supports('docx')`. Verified approach, no dependency:

```php
        $path = tempnam(sys_get_temp_dir(), 'swag-docx');

        if ($path === false) {
            throw ExtractionFailed::noText('docx');
        }

        try {
            file_put_contents($path, $bytes);

            $zip = new \ZipArchive();

            if ($zip->open($path) !== true) {
                throw ExtractionFailed::noText('docx');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            unlink($path);
        }

        if (!\is_string($xml)) {
            throw ExtractionFailed::noText('docx');
        }

        // `</w:p>` is a paragraph end; runs inside one paragraph must NOT become separate lines,
        // because Word splits a single sentence across runs constantly.
        $withBreaks = (string) preg_replace('#</w:p>#', "\n", $xml);

        return trim(html_entity_decode(strip_tags($withBreaks), \ENT_QUOTES | \ENT_XML1));
```

**Known limitation to state in the class docblock:** this reaches body prose only. Tables, headers,
footers and footnotes are dropped, and footnotes can carry substance in legal text. `PhpOffice/PhpWord`
would be complete at the cost of a dependency for a case nobody has measured (spec, *Known gaps*).

`ZipArchive` needs no `ext-zip` entry beyond what Shopware already requires — confirm with
`composer run quality:depcheck` and add `ext-zip` to `composer.json` if it complains.

- [ ] **Step 5: Verify**

Run: `vendor/bin/phpunit tests/Core/ShopInfo/ExtractorChainTest.php` → PASS (6 tests).

If `testAPdfWithoutATextLayerFailsRatherThanYieldingNothing` does **not** throw, dompdf produced a
text operator anyway — replace the fixture with a PDF built by hand that has an empty content stream,
and keep the test. Do not weaken the assertion: it is the one that protects a merchant from a silent
no-op upload.

Run: `composer run quality` → exit 0. `ExtractorChain` and each extractor are small; if
`cyclomatic-complexity` fires on `HtmlExtractor`, move the block-element filter into its own method in
a **separate** class rather than merging methods.

- [ ] **Step 6: Commit**

```bash
composer run format
git add src/Core/ShopInfo/ tests/Core/ShopInfo/
git commit -m "feat(shopinfo): extract text from pdf, txt, md, html and docx"
```

---

### Task 4: Chunking

**Files:**
- Create: `src/Core/ShopInfo/Chunker.php`
- Test: `tests/Core/ShopInfo/ChunkerTest.php`

**Interfaces:**
- Produces:
  - `Chunker::MAX_CHARS = 1200`, `Chunker::OVERLAP_CHARS = 200`
  - `Chunker::chunk(string $text): array` → `list<array{section: string, text: string}>`

The section label is the nearest preceding short line — a heading, in practice. It is what the tool
reports as `section` and what makes a passage citable in Part 2 without re-parsing anything.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/ShopInfo/ChunkerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\Chunker;

/**
 * Paragraph-based chunking with overlap (spec R11).
 *
 * The reason it is paragraphs and not characters: a chunk that starts mid-sentence separates "binnen
 * vierzehn Tagen" from "ab Erhalt der Ware". The model then paraphrases a deadline with no start
 * date, and it reads like a fact.
 */
final class ChunkerTest extends TestCase
{
    public function testShortTextIsOneChunk(): void
    {
        $chunks = (new Chunker())->chunk("Widerruf\n\nBinnen vierzehn Tagen.");

        self::assertCount(1, $chunks);
        self::assertStringContainsString('vierzehn Tagen', $chunks[0]['text']);
    }

    public function testAParagraphIsNeverSplitMidSentenceWhenItFits(): void
    {
        $paragraph = 'Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gruenden zu widerrufen.';
        $text = implode("\n\n", array_fill(0, 40, $paragraph));

        foreach ((new Chunker())->chunk($text) as $chunk) {
            // Every chunk begins at a paragraph boundary, so it begins with a capital and ends with a
            // full stop. A character-count splitter fails this immediately.
            self::assertMatchesRegularExpression('/^Sie haben/', $chunk['text']);
            self::assertStringEndsWith('.', trim($chunk['text']));
        }
    }

    public function testChunksOverlapSoAThoughtSpanningABoundaryIsStillRetrievable(): void
    {
        $paragraphs = [];
        for ($i = 1; $i <= 30; ++$i) {
            $paragraphs[] = \sprintf('Absatz %02d mit genug Text, dass mehrere Absaetze nicht in einen Chunk passen.', $i);
        }

        $chunks = (new Chunker())->chunk(implode("\n\n", $paragraphs));

        self::assertGreaterThan(1, \count($chunks));

        // The tail of one chunk reappears at the head of the next.
        $firstTail = substr(trim($chunks[0]['text']), -60);
        self::assertStringContainsString($firstTail, $chunks[1]['text']);
    }

    public function testEveryChunkStaysWithinTheBound(): void
    {
        $text = implode("\n\n", array_fill(0, 100, str_repeat('Wort ', 40)));

        foreach ((new Chunker())->chunk($text) as $chunk) {
            self::assertLessThanOrEqual(Chunker::MAX_CHARS, \strlen($chunk['text']));
        }
    }

    /** A single paragraph longer than the bound must still be split, or it can never be indexed. */
    public function testAParagraphLongerThanTheBoundIsSplitAnyway(): void
    {
        $chunks = (new Chunker())->chunk(str_repeat('Wort ', Chunker::MAX_CHARS));

        self::assertGreaterThan(1, \count($chunks));

        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(Chunker::MAX_CHARS, \strlen($chunk['text']));
        }
    }

    public function testTheNearestHeadingBecomesTheSectionLabel(): void
    {
        $chunks = (new Chunker())->chunk("Widerrufsfrist\n\nBinnen vierzehn Tagen ab Erhalt der Ware.");

        self::assertSame('Widerrufsfrist', $chunks[0]['section']);
    }

    public function testTextWithNoParagraphBreaksAtAllStillChunks(): void
    {
        $chunks = (new Chunker())->chunk(str_repeat('a', 5000));

        self::assertNotSame([], $chunks);

        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(Chunker::MAX_CHARS, \strlen($chunk['text']));
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/ShopInfo/ChunkerTest.php`
Expected: FAIL — `Chunker` not found.

- [ ] **Step 3: Implement**

Create `src/Core/ShopInfo/Chunker.php`. The algorithm, stated so the implementer does not have to
invent it:

1. Split on `/\n\s*\n/` into paragraphs; drop empties.
2. A paragraph shorter than 80 characters with no sentence-ending punctuation is a **heading**: it
   becomes the current section label and is also prepended to the chunk it opens.
3. Accumulate paragraphs into a chunk while adding the next one keeps it within `MAX_CHARS`.
4. When a single paragraph alone exceeds `MAX_CHARS`, split it on sentence ends (`/(?<=[.!?])\s+/`),
   and if a single sentence still exceeds it, hard-split at `MAX_CHARS`.
5. Each chunk after the first begins with the last `OVERLAP_CHARS` characters of the previous chunk,
   trimmed forward to the next word boundary so it never starts mid-word.
6. Every chunk carries the section label in force where it started.

Watch the complexity budget: this is the class most likely to exceed it. Split the
paragraph-splitting from the accumulation into **two classes** if lint fires — `Chunker` plus a
`ParagraphSplitter` — rather than reaching for a pragma.

- [ ] **Step 4: Verify**

Run: `vendor/bin/phpunit tests/Core/ShopInfo/ChunkerTest.php` → PASS (7 tests).
Run: `composer run quality` → exit 0.

- [ ] **Step 5: Commit**

```bash
composer run format
git add src/Core/ShopInfo/ tests/Core/ShopInfo/
git commit -m "feat(shopinfo): chunk on paragraphs, with overlap, so a deadline keeps its start date"
```

---

### Task 5: The document, its table, and ingestion

**Files:**
- Create: `src/Entity/AssistantDocument/{AssistantDocumentDefinition,AssistantDocumentEntity,AssistantDocumentCollection}.php`
- Create: `src/Migration/Migration1787616000CreateAssistantDocuments.php`
- Create: `src/ShopInfo/DocumentIngestion.php`
- Create: `src/Command/ShopInfoCommand.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/ShopInfo/DocumentIngestionTest.php`

**Interfaces:**
- Produces:
  - `DocumentIngestion::ingest(string $filename, string $bytes, string $salesChannelId): string` → the document id
  - `DocumentIngestion::remove(string $documentId): void`
  - Entity `swag_assistant_document`: `id`, `name`, `extension`, `sales_channel_id`, `status`, `status_reason`, `chunk_count`, `text`, `dimension`, `created_at`, `updated_at`

Follow `src/Entity/TraceEvent/` for the three-class entity shape and
`src/Migration/Migration1787270400AddTraceEventElapsedMs.php` for the migration shape — including its
`SHOW COLUMNS` idempotence guard and its docblock explaining why the exception propagates.

- [ ] **Step 1: Write the failing test**

Create `tests/ShopInfo/DocumentIngestionTest.php`. It uses the `InMemoryPassageStore` from Task 2, a
fake vectorizer, and a fake document repository — so no database and no API key.

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\ExtractionFailed;
use Swag\AssistantStarterKit\Tests\Core\ShopInfo\InMemoryPassageStore;

/**
 * The write path: file in, passages in the store, one generation at a time.
 *
 * Spec R8 is the assertion that earns this file — re-ingesting a document must leave exactly one
 * generation of its chunks. A merchant who uploads a corrected revocation notice without the old one
 * being removed has both in the store, retrieval can serve either, and nobody would ever see it.
 */
final class DocumentIngestionTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testItStoresOnePassagePerChunk(): void
    {
        $store = new InMemoryPassageStore();
        $ingestion = self::ingestion($store);

        $ingestion->ingest('widerruf.txt', "Widerrufsfrist\n\nBinnen vierzehn Tagen.", self::CHANNEL);

        self::assertNotSame([], $store->query(self::vector(), self::CHANNEL, minScore: 0.0, limit: 10));
    }

    public function testReIngestingReplacesTheOldGeneration(): void
    {
        $store = new InMemoryPassageStore();
        $ingestion = self::ingestion($store);

        $ingestion->ingest('widerruf.txt', "Widerrufsfrist\n\nBinnen vierzehn Tagen.", self::CHANNEL);
        $before = \count($store->query(self::vector(), self::CHANNEL, minScore: 0.0, limit: 100));

        $ingestion->ingest('widerruf.txt', "Widerrufsfrist\n\nBinnen dreissig Tagen.", self::CHANNEL);
        $after = $store->query(self::vector(), self::CHANNEL, minScore: 0.0, limit: 100);

        self::assertCount($before, $after, 'the old generation must be gone, not accumulated');

        $texts = implode(' ', array_map(static fn(object $p): string => $p->text, $after));
        self::assertStringContainsString('dreissig', $texts);
        self::assertStringNotContainsString('vierzehn', $texts);
    }

    public function testAnUnextractableFileLandsInFailedStatusAndStoresNothing(): void
    {
        $store = new InMemoryPassageStore();

        try {
            self::ingestion($store)->ingest('groessen.xlsx', 'anything', self::CHANNEL);
            self::fail('expected ExtractionFailed');
        } catch (ExtractionFailed) {
            // The point: nothing was written. A half-ingested document is worse than none, because
            // it answers questions from a fragment.
            self::assertSame([], $store->query(self::vector(), self::CHANNEL, minScore: 0.0, limit: 100));
        }
    }

    public function testOneChannelCannotSeeAnotherChannelsDocument(): void
    {
        $store = new InMemoryPassageStore();

        self::ingestion($store)->ingest('agb.txt', "AGB\n\nGilt fuer Kanal A.", self::CHANNEL);

        // Spec R12, asserted rather than assumed.
        self::assertSame([], $store->query(self::vector(), 'ffffffffffffffffffffffffffffffff', 0.0, 100));
    }
}
```

`self::ingestion()`, `self::vector()` and the fakes are for the implementer to write; keep them in
this file. The fake vectorizer must return a **deterministic** vector derived from the text (e.g. a
character-frequency histogram normalised to fixed length) so `query()` ordering is reproducible — do
not use random numbers.

- [ ] **Step 2: Run it to verify it fails**

Expected: FAIL — `DocumentIngestion` not found.

- [ ] **Step 3: Write the entity, the migration, and the ingestion**

Ingestion order matters and is the whole of R8:

1. Extract. On `ExtractionFailed`, record the document as `failed` with the reason, write **no**
   passages, and rethrow.
2. Chunk.
3. Embed all chunks.
4. **Verify the width** against `PassageStore::dimension()`. On mismatch, fail with a message naming
   both widths and telling the merchant to re-index everything — see the plan's *Two gaps*.
5. `deleteDocument()` the previous generation.
6. `add()` the new passages.
7. Record the document as `indexed` with its chunk count and the dimension used.

Steps 5 and 6 in that order and adjacent: any failure between them leaves the document with no
passages, which the `failed` status then explains. The reverse order can leave two generations.

The document id is derived from `salesChannelId` + `name`, so "the same document" is well defined and
re-uploading replaces rather than accumulates.

- [ ] **Step 4: Write the CLI**

`src/Command/ShopInfoCommand.php`, `swag:assistant:shopinfo`, following `ProbeCommand`'s shape —
including its `SalesChannelContextProvider::use()` pattern, which is the only reason a DAL-backed
command works at all outside an HTTP request:

```
--index=PATH        extract, chunk, embed, replace
--list              documents with status, chunk count, dimension
--delete=NAME       remove a document and its passages
--query="..."       run a retrieval and print scores, including rejected ones
--sales-channel=ID  defaults to ProbeCommand's DEFAULT_SALES_CHANNEL
```

`--query` is not a convenience: it is how Task 7 calibrates the threshold without spending model
turns.

- [ ] **Step 5: Verify**

Run: `vendor/bin/phpunit --exclude-group eval` → PASS.
Run: `composer run quality` → exit 0.

Then against the real shop:

```bash
cd ../shopping-assistant-test
docker compose up -d
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && APP_ENV=prod php bin/console cache:clear'
printf 'Widerrufsfrist\n\nSie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gruenden diesen Vertrag zu widerrufen.\n' \
  > /tmp/widerruf.txt
docker cp /tmp/widerruf.txt shopping-assistant-test-web-1:/tmp/widerruf.txt
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && APP_ENV=prod php bin/console swag:assistant:shopinfo --index=/tmp/widerruf.txt'
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && APP_ENV=prod php bin/console swag:assistant:shopinfo --list'
```

Expected: status `indexed`, a chunk count of 1, and a dimension matching the configured model. **This
is the first time an embedding API call happens** — if it fails, the cause is the provider URL, the
key or the model name, all three of which are plugin settings, not code.

- [ ] **Step 6: Commit**

```bash
composer run format
git add src/Entity/ src/Migration/ src/ShopInfo/ src/Command/ tests/ShopInfo/ src/Resources/config/services.xml
git commit -m "feat(shopinfo): ingest a document, replacing the generation before it"
```

---

### Task 6: The tool

**Files:**
- Create: `src/Core/ShopInfo/SearchShopInfoTool.php`
- Create: `src/Core/Tool/Factory/SearchShopInfoToolFactory.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Core/ShopInfo/SearchShopInfoToolTest.php`

**Interfaces:**
- Consumes: `PassageStore` (Task 2), `AssistantConfig::$salesChannelId` and `$embeddingModel` (Task 1).
- Produces:
  - `SearchShopInfoTool::MIN_SCORE` — **the value comes from Task 7; ship the constant with a placeholder of `0.75` and a docblock saying it is provisional until that report exists**
  - `SearchShopInfoTool::MAX_PASSAGES = 3`
  - `SearchShopInfoTool::__invoke(string $question): array` → `array{passages: list<array{id: string, document: string, section: string, text: string}>, total: int, note?: string}`
  - `SearchShopInfoToolFactory implements ToolFactoryInterface`

- [ ] **Step 1: Write the failing test**

Create `tests/Core/ShopInfo/SearchShopInfoToolTest.php`, using `InMemoryPassageStore` and the same
deterministic fake vectorizer as Task 5 — no API call in the suite.

```php
    public function testAQuestionTheDocumentsAnswerReturnsPassages(): void
    {
        $result = ($this->toolWith('Widerrufsfrist', 'Binnen vierzehn Tagen ab Erhalt der Ware.'))(
            'Wie lange kann ich zurueckschicken?',
        );

        self::assertSame(1, $result['total']);
        self::assertStringContainsString('vierzehn Tagen', $result['passages'][0]['text']);
        self::assertArrayNotHasKey('note', $result);
    }

    /**
     * Spec R3, and the reason a threshold exists at all. Vector search always returns its nearest
     * neighbour, so without this the model receives a passage about payment data processing and
     * paraphrases it into an answer about Bitcoin.
     */
    public function testNothingAboveTheThresholdYieldsANoteAndNoPassages(): void
    {
        $result = ($this->toolWith('Datenschutz', 'Wir verarbeiten Zahlungsdaten.'))(
            'Kann ich mit Bitcoin bezahlen?',
        );

        self::assertSame([], $result['passages']);
        self::assertSame(0, $result['total']);
        self::assertArrayHasKey('note', $result);
        // The note must forbid the specific failure, not merely report absence — the precedent is
        // SearchProductsTool::NO_MATCH_NOTE.
        self::assertStringContainsString('erfinde', strtolower($result['note']));
    }

    public function testThePassageCountIsBounded(): void
    {
        self::assertLessThanOrEqual(
            SearchShopInfoTool::MAX_PASSAGES,
            \count(($this->toolWithManyPassages())('Widerruf')['passages']),
        );
    }

    /** Spec R12: the tool queries with its own config's channel and no other. */
    public function testItOnlySeesItsOwnSalesChannel(): void
    {
        $result = ($this->toolForChannel('ffffffffffffffffffffffffffffffff'))('Wie lange kann ich zurueckschicken?');

        self::assertSame([], $result['passages']);
    }

    /** Spec R5: every score is traced, including the rejected ones, so the threshold is calibratable. */
    public function testEveryScoreIsTracedIncludingRejectedOnes(): void
    {
        $trace = new TraceRecorder();
        ($this->toolWith('Datenschutz', 'Wir verarbeiten Zahlungsdaten.', $trace))('Bitcoin?');

        $payload = $trace->payload('retrieve.shopinfo');

        self::assertSame(0, $payload['accepted']);
        self::assertNotSame([], $payload['scores']);
        self::assertSame(SearchShopInfoTool::MIN_SCORE, $payload['threshold']);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Expected: FAIL — `SearchShopInfoTool` not found.

- [ ] **Step 3: Write the tool**

Requirements, all of them load-bearing:

- `#[AsTool]` with a description telling the model what it is for: *shop information — terms,
  revocation, privacy, shipping, imprint — never products.* The schema the model sees is derived from
  the signature by reflection, exactly as `SearchProductsTool` documents.
- One parameter, `string $question`, passed through `Guard::boundedString($question, 500, 'question')`.
- Embed the question, query the store with `MIN_SCORE`, `MAX_PASSAGES` and
  `$this->config->salesChannelId`.
- Record `retrieve.shopinfo` with `question`, `threshold`, `scores` (all of them, rejected included),
  `accepted`, and `ms` for the embedding call (spec R14).
- No passages above the threshold → `note`, in the shape of `SearchProductsTool::NO_MATCH_NOTE`:

```php
    public const NO_MATCH_NOTE = 'The shop information does not cover this. Say you cannot find it in '
        . 'the shop information and point the shopper at the relevant page. Invent no deadline, no '
        . 'address, no fee and no condition — erfinde nichts.';
```

(The German clause is deliberate: the note is read by the model, this shop answers in German, and the
test asserts on it.)

- [ ] **Step 4: Write the factory**

```php
final readonly class SearchShopInfoToolFactory implements ToolFactoryInterface
{
    public function __construct(
        private PassageStore $store,
        private Vectorizer $vectorizer,
    ) {}

    public function create(ToolContext $context): ?object
    {
        // Null, not a disabled tool: never constructed means never in the schema the model sees,
        // which is what keeps capability control out of the prompt (D6) — and spec R13's off switch.
        if ($context->config->embeddingModel === '') {
            return null;
        }

        return new SearchShopInfoTool($this->store, $this->vectorizer, $context->trace, $context->config);
    }
}
```

Tag it `swag_assistant.tool_factory` — the unprivileged tier, since it needs nothing from the
catalogue. `EscalateToolFactory` is the precedent and its docblock explains why that tier exists.

- [ ] **Step 5: Verify**

Run: `vendor/bin/phpunit --exclude-group eval` → PASS.
Run: `composer run quality` → exit 0.

Then confirm the off switch really is off:

```bash
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && APP_ENV=prod php bin/console system:config:set SwagAssistantStarterKit.config.embeddingModel ""'
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && APP_ENV=prod php bin/console swag:assistant:probe --ask="Wie lange kann ich zurueckschicken?" 2>&1 | tail -25'
```

Expected: the trace shows **no** `search_shop_info` in the tool schema at all. Then set the model back.

- [ ] **Step 6: Commit**

```bash
composer run format
git add src/Core/ShopInfo/ src/Core/Tool/Factory/ tests/Core/ShopInfo/ src/Resources/config/services.xml
git commit -m "feat(shopinfo): a tool that answers from documents or admits it cannot"
```

---

### Task 7: Calibrating the threshold

**Files:**
- Create: `docs/superpowers/reports/2026-08-25-shopinfo-threshold.md`
- Modify: `src/Core/ShopInfo/SearchShopInfoTool.php` (the constant, once measured)

Spec R4: the value is measured, not chosen. This task is why `--query` exists in Task 5's CLI, and it
costs embedding calls only — no model turns.

- [ ] **Step 1: Index a real document**

Use the shop's actual revocation text, not a synthetic one — a real legal document's vocabulary is the
thing being matched against.

```bash
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && APP_ENV=prod php bin/console swag:assistant:shopinfo --index=/tmp/widerruf.txt'
```

- [ ] **Step 2: Run five questions the document answers, and five it does not**

```bash
for q in "Wie lange kann ich zurueckschicken?" "Was ist die Widerrufsfrist?" \
         "Kann ich den Vertrag widerrufen?" "Ab wann laeuft die Frist?" "Muss ich Gruende angeben?" \
         "Kann ich mit Bitcoin bezahlen?" "Habt ihr das Trikot in XL?" \
         "Wie hoch sind die Versandkosten nach Japan?" "Wer ist euer Geschaeftsfuehrer?" \
         "Wann kommt meine Bestellung an?"; do
  docker exec shopping-assistant-test-web-1 bash -lc \
    "cd /var/www/html && APP_ENV=prod php bin/console swag:assistant:shopinfo --query=\"$q\""
done
```

- [ ] **Step 3: Write the report and set the constant**

Create the report with both score lists and pick a value that separates them. Then set `MIN_SCORE` and
replace its provisional docblock with the measurement.

**If the two groups do not separate, do not split the difference.** That is a finding about chunking
or about the embedding model, and the honest outcome is a report saying so plus a follow-up plan — not
a threshold that fails both ways. Say which of the two you suspect and why.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/reports/2026-08-25-shopinfo-threshold.md src/Core/ShopInfo/SearchShopInfoTool.php
git commit -m "docs: measure the similarity threshold instead of guessing it"
```

---

### Task 8: Journeys, regression, and the demo shop

**Files:**
- Create: `tests/Journeys/shop_info_revocation.php`
- Create: `tests/Journeys/shop_info_not_in_documents.php`

- [ ] **Step 1: Write the journeys**

Both are read by `Journey::fromFile()` and cannot import a class, so ids are literals. The assertion
names must exist in `Eval\Assertion\AssertionRegistry` — a typo throws at load time by design.

`shop_info_revocation.php`: archetypes `'expert' => 'Wie lange ist die Widerrufsfrist?'` and
`'beginner' => 'hey kann ich das wieder zurückschicken wenn es nicht passt?'`. Assertions:
`no_invented_product` (nothing about products), `no_absence_claim_in_prose`,
`no_unbacked_price_in_prose`.

`shop_info_not_in_documents.php` — **the more important of the two.** Archetypes ask something the
indexed documents do not answer: `'expert' => 'Welche Garantie gebt ihr auf Rahmenbrüche?'`,
`'beginner' => 'wie lange hab ich garantie auf den rahmen?'`. Assertions: `no_invented_product`,
`no_absence_claim_in_prose`, `no_unbacked_price_in_prose`.

Both need a document indexed in the eval environment, which the fixture-driven `JourneyRunner` does
not have. **Resolve this explicitly rather than discovering it:** either the runner gains a way to
seed passages for a journey, or these two journeys are marked as requiring a prepared store the way
`scale_*` requires `ASSISTANT_EVAL_CATALOG=large`. Write down which, and why, in the journey files'
comments — a journey that silently tests nothing is worse than no journey.

- [ ] **Step 2: Verify every journey still parses**

```bash
php -r 'require "vendor/autoload.php";
foreach (glob("tests/Journeys/*.php") as $f) {
    $j = Swag\AssistantStarterKit\Eval\Journey::fromFile($f);
    printf("%-34s ok  assertions=%d\n", basename($f, ".php"), count($j->assertions));
}'
```

- [ ] **Step 3: Run the new journeys, then the regression**

```bash
timeout 900 vendor/bin/phpunit --group eval --filter 'shop_info' --testdox
timeout 3000 vendor/bin/phpunit --group eval --filter '/^(?!.*scale_).*$/' --testdox
```

The second is the one to watch. **A new tool is a stronger intervention than a new reply field:** it
changes what the model can choose, not just what it reads. `last_search_wins` and
`vocabulary_not_inventory` are the likeliest to be surprised, the first because a second retrieval
tool exists and the second because the assistant now has a legitimate non-product answer.

- [ ] **Step 4: End to end in the demo shop**

Through the widget at `http://127.0.0.1:8000`, after `APP_ENV=prod cache:clear`:

1. Index the revocation text via the CLI.
2. Ask the revocation question — expect an answer drawn from the document.
3. Ask something the document does not cover — expect "I cannot find that", **no invented deadline**.
4. Ask a product question — expect it still works, and the trace shows `search_products`, not
   `search_shop_info`.
5. Re-index a changed version of the document and ask again — expect the new text.

- [ ] **Step 5: Commit**

```bash
composer run format
git add tests/Journeys/
git commit -m "test: two journeys for shop information, one of them for not knowing"
```

---

## Gate

**Part 2 (the admin module) is not planned until Task 7's report exists.** The threshold decides
whether the chain is usable at all, and an upload UI on top of retrieval that cannot separate a match
from a miss would be a UI for a broken feature.

**If Task 7 cannot find a separating threshold**, stop and report. The next step is then a chunking or
embedding-model question, not a UI.

## Spec coverage

| Decision | Where |
|---|---|
| R1 upload only, CMS later | This plan's scope line; no task touches `core.basicInformation` |
| R2 their infrastructure, our tool | Task 2 (`PassageStore` interface), Task 6 (the tool) |
| R3 threshold or nothing | Task 6, `testNothingAboveTheThresholdYieldsANoteAndNoPassages` |
| R4 constant, measured not chosen | Task 6 ships it provisional; Task 7 measures it |
| R5 trace every score | Task 6, `testEveryScoreIsTracedIncludingRejectedOnes` |
| R6 model gets the text, may paraphrase | Task 2's DTO docblock; the tool returns `text` |
| R7 no rendered source | Nothing renders one; recorded in the spec's *Known gaps* |
| R8 version replaces version | Task 5, `testReIngestingReplacesTheOldGeneration` |
| R9 documents are entities, with status | Task 5's entity and migration |
| R10 five formats, one interface | Task 3 |
| R11 paragraph chunking with overlap | Task 4 |
| R12 sales-channel isolation | Task 2 (interface takes it), Task 5 and Task 6 (both assert it) |
| R13 empty setting turns it off | Task 6's factory returning null, verified against the shop in Step 5 |
| R14 no query cache, but time it | Task 6's `ms` in the trace |
| The two planning gaps | Task 1 (`salesChannelId`), Task 2 + Task 5 (dimension guard) |

## Known risks

1. **The MariaDB bridge's API is not verified.** Task 2 Step 1 exists to read it rather than assume it, and Step 4's body is deliberately unwritten. If the bridge turns out not to accept a DBAL connection, or not to support metadata filtering, that is a stop-and-report — the alternative is spec approach C (our own `VECTOR` queries), which the spec already sized.
2. **`schema_filter` inside a plugin.** Task 2 Step 5 names the problem and requires a decision. Getting it wrong surfaces as a broken `doctrine:schema:update` in a merchant's shop, which is the worst place to find it.
3. **The embedding dimension is a foot-gun by construction.** A merchant changing the model silently invalidates the store. The guard turns that into a refusal with an explanation; it cannot turn it into a non-event.
4. **The eval environment has no documents.** Task 8 Step 1 requires deciding how the two journeys get a store, and a journey that passes because it tested nothing is the failure mode to avoid.
5. **Journeys are model behaviour.** `shop_info_not_in_documents` asks the model to admit ignorance, and this project measured this week that a model overrides an explicit instruction not to claim absence in roughly one run of three. If that journey is flaky, the finding is about prompt adherence, not about retrieval — and it is a finding, not a reason to weaken the assertion.

---

## Outcome (2026-08-26)

**All eight tasks executed.** Branch `feat/shop-info-retrieval`. The chain works end to end against
the lab shop: a question about the shop's revocation terms is answered from a document the merchant
supplied, and a question the document does not answer is declined without invention.

### The one decision that changed

**Task 7's Gate did not pass on its own terms, and R3 was revised rather than parameterised.** No
similarity threshold separates questions a document answers from questions it does not — measured
across `text-embedding-3-small`, `text-embedding-3-large` and `bge-m3`, plus a chunking experiment
that made the overlap wider rather than narrower. Two structural cases cause it and they sit on
opposite sides of any line: an answer carried by a negation, and a topical near-miss that shares the
document's whole vocabulary while being absent from it.

So the threshold became a **recall floor** (0.40 with `bge-m3`) and the relevance decision moved to
the model, which arrives with `SearchShopInfoTool::RELEVANCE_NOTE`. That replaces a guarantee with an
instruction — spec R3a records the trade, and `shop_info_not_in_documents` is what holds the line.
Measured: 3/3 on both archetypes, with passages above the floor in every run.

Full data: `docs/superpowers/reports/2026-08-25-shopinfo-threshold.md`.

### What the plan assumed that did not hold

| Assumption | Reality |
|---|---|
| Store `query()` takes `minScore` and a structured `filter` | It is **distance**-based: `maxScore` bounds cosine distance, and filtering is a raw SQL `where` plus bound `params`. The sign conversion is isolated to `AiStorePassageStore` |
| A `Vectorizer` service exists to inject | The plugin builds its platform with `supportsEmbeddings: false`, so embedding was impossible. A second, embeddings-only platform now sits beside the completions one — flipping the flag would have risked routing completions to the embeddings endpoint |
| Any configured embedding model works | The generic bridge infers a model's kind from whether its name contains `embed`, so `bge-m3` failed before any HTTP request. `EmbeddingsOnlyModelCatalog` fixes it |
| `remove()` can delete a document's passages | It takes ids only, so `deleteDocument()` is a direct `DELETE` on the `documentId` metadata — trusting a stored chunk count would orphan passages that retrieval can still serve |
| The eval harness needs documents seeded | It needed the **tool** first: `JourneyAttempt` uses `withCoreToolsOnly()`, so `search_shop_info` did not exist there at all |
| Character-granular chunk overlap | The plan's own test forbids it. Overlap is whole paragraphs |
| `extension` can be an entity property | `Entity` inherits `ExtendableTrait::getExtension(string)`; renamed to `fileExtension` with the column unchanged |
| `schema_filter` must be configured | Avoided instead: the vector table is created by the library's raw DDL at first write, has no DAL entity, and Shopware evolves schema through migrations — nothing inspects it |

### Added beyond the plan, and why

- **`retrieved_shop_info` assertion.** Without it both new journeys passed vacuously: the first expert
  archetype scored just under the recall floor, so the model received nothing, declined for want of
  information, and every safety assertion went green having tested nothing. It caught that on its
  first run.
- **`DocumentRecords` port and `Embedder` interface.** The plan's DB-free ingestion test needs a fake
  document repository, and faking Shopware's `EntityRepository` means constructing DAL internals. The
  library's `VectorizerInterface` is `final` and returns a union of four shapes.
- **A multibyte test for the forced chunk split.** It counts bytes; half a character is invalid UTF-8
  and German legal text is full of umlauts.

### Not done

- **Part 2, the admin module** — as planned, it was gated on Task 7. The Gate's condition is now
  satisfied differently than expected: the chain works, but its relevance guarantee is an instruction
  rather than a threshold. Worth deciding whether a reranker comes before the UI.
