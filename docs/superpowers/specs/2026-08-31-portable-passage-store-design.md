# A Portable Passage Store, and a Switch a Merchant Can Read — Design

**Date:** 2026-08-31
**Status:** built, shipped and verified on both engines. The build decision was taken on
2026-08-31 — see *Decision, 2026-08-31* — and both stores are in `main`.

*Verified on MariaDB 11.8* (the local shop): the container accepts the chooser, the portable table is
created by `Migration1788393600CreateShopInfoPassages`, and the shop still records
`retrieve.shopinfo.store` as `{"store":"mariadb"}` — the fast path was not lost.

*Verified on MySQL 8.0.46* — a throwaway shop stood up for the purpose, the same version the
staging box runs, confirmed to have neither `VECTOR` (`CREATE TABLE _vec_probe (id INT, v VECTOR(4))`
→ syntax error) nor `VEC_DISTANCE_COSINE` (function does not exist). **This run is what closes
*What this cannot test* for the portable half** — its SQL had never touched a real database before,
because every store test in this repo mocks `Connection`. What was exercised end to end:

| | |
|---|---|
| Install | Plugin installs and activates; the migration creates `swag_assistant_shop_info_passage` with a native `json` vector column (MariaDB spells the same column `longtext` + a `json_valid()` CHECK). `swag_assistant_shop_info_vector` is never created. |
| The switch | With `enableShopKnowledge` off but `embeddingModel` SET, ingestion is refused — the boolean resolving into the one off-state, on a real shop. |
| Ingestion, merchant path | `POST /api/_action/swag-assistant/shop-info/index-pages` indexed all five configured CMS legal pages: 5 indexed, 0 failed, 0 skipped. |
| Ingestion, upload path | `POST /api/_action/swag-assistant/shop-info/upload` indexed a merchant's own size chart, 1024 dimensions. |
| Ingestion, CLI path | `swag:assistant:shopinfo --index` for plain files. |
| Ranking | `--query` scored 0.7418 / 0.5296 / 0.4578 with the right document first — similarity semantics and ordering correct over MySQL JSON rows. |
| Answering, German | "Wie lange habe ich Widerrufsrecht?" → answered from the indexed text, `shop_info_retrieved`. |
| Answering, English, cross-lingual | "How many days do I have to return an order?" → answered in English **from the German document**, which `baai/bge-m3` makes possible and which the portable store's exact float64 ranking serves unchanged. |
| Answering, merchant upload | 57 cm head circumference → size M (55–59), read out of the uploaded chart. |
| Multi-turn | A follow-up on the same conversation token answered from a *different* document. |
| Refusal | Asked for a company register number the (lorem-ipsum) imprint does not contain, it declined and escalated rather than inventing one. |
| Deletion | `deleteDocument()` removed the document and its passages; re-indexing the same file left exactly one passage, so spec R8's delete-then-add holds here too. |
| The trace | Seven turns, every one `{"store":"portable","reason":"…this shop runs 8.0.46…"}`. |

*Driven through the storefront widget itself*, 2026-09-01, on the same MySQL shop: the launcher
opens the panel, and five turns typed into it produced the withdrawal period, the free-shipping
threshold as a follow-up in the same conversation, a refusal to invent a company register number, the
helmet size read out of the merchant's uploaded chart, and a product answer rendering two cards with
prices, stock and the grounding note. All five recorded `{"store":"portable"}` — twelve turns on this
shop, every one of them portable, and `swag_assistant_shop_info_vector` was never created.

**One thing stays open:** half of D6 — the line on the shop-information admin screen naming the
active store, with its re-index notice — is not built.
**Supersedes nothing.** The MariaDB store stays, and stays preferred where it works.

## Purpose

Two problems, found the same afternoon on the staging shop, with one shared cause.

**The feature cannot run on most databases.** Shop-information retrieval stores its vectors through
`Symfony\AI\Store\Bridge\MariaDb\Store`, which emits `VECTOR` columns, `VECTOR INDEX` and
`VEC_DISTANCE_COSINE` — MariaDB's spelling. Measured 2026-08-31 against
`shoppingassistan-rschulte.eu-core-1.shopdev.de`:

```
DB: 8.0.46-0ubuntu0.24.04.3
VECTOR: NOT supported — SQLSTATE[42000]: syntax error
```

The intuitive fix does not exist. MySQL has no `VECTOR` type before 9.0, and MySQL 9's Community and
Commercial distributions ship the type without a distance function — the manual is explicit:

> `DISTANCE()` is available only for users of MySQL HeatWave on OCI and MySQL AI; it is not included
> in MySQL Commercial or Community distributions.

So vector *comparison* in SQL is impossible on every MySQL a merchant is likely to run. Upgrading
MySQL does not help at any version; only changing engine does.

**The switch is unreadable.** `embeddingModel` and `autoIndexShopPages` sit in the **Language model**
card, beside the base URL, the model name and the API key. A merchant who wants the assistant to
answer from the shop's own documents has to know that this is spelled "put an embedding model name in
a technical field". Nothing on that screen says what the feature *is*.

The shared cause: the feature was designed around its storage engine rather than around what it does
for a shop.

## What was verified while designing this

| Claim | How |
|---|---|
| MySQL 8.0 has no `VECTOR` | `CREATE TABLE _vec_probe (id INT, v VECTOR(4))` on the staging DB — syntax error |
| MySQL 8.4 has no `VECTOR` | 8.4 manual's data-type chapter lists numeric, date/time, string, spatial, JSON — nothing else |
| MySQL 9 Community has no distance function | Quoted verbatim above, MySQL 9.7 manual, *Vector Functions* |
| The store is MariaDB-specific | `vendor/symfony/ai-maria-db-store/Store.php` — `VEC_DISTANCE_COSINE`, `VEC_FromText`, `VEC_ToText`, `VECTOR INDEX` |
| MariaDB's vector index is approximate | MariaDB Vector is a modified HNSW — an ANN index, tunable via `mhnsw_max_edges_per_node` |
| Exact PHP cosine is already the tested path | `Eval\ShopInfoFixture` builds an `InMemoryPassageStore`, so `shop_info_revocation` and `shop_info_not_in_documents` already measure PHP cosine, not the MariaDB store |
| The corpus is small | The local demo shop holds **11 documents / 16 chunks** — the only shop in this project with shop information indexed at all. Staging has none. |

That last pair is what makes this design possible rather than merely desirable: a brute-force scan is
not a compromise here, it is what the eval suite has been measuring all along.

## Requirements

1. Shop-information retrieval ships working on any database Shopware supports.
2. MariaDB stays supported and stays **preferred**: where its store works, nothing changes, not even
   internally.
3. The merchant-facing switch says what the feature does, in the merchant's words, and makes clear
   that their own documents can be added — not only imprint, privacy, terms, returns and shipping.
4. Off by default, with the MariaDB recommendation and its reason visible where the switch is.
5. An unmet requirement leaves the feature **off**, never throws at a shopper. (Already true since
   `ShopInfoAvailability`; this must not regress it.)
6. **Which store is running is visible.** Falling back must never be silent — see D6.
7. The new table joins `AssistantTableRemoval::TABLES`, so uninstalling removes it when the merchant
   declines to keep data — see D7.

## Decisions

**D1. The store is chosen, not configured.** No merchant can answer "which vector storage engine".
The chooser prefers the MariaDB store when `DalVectorSupport` reports the functions present and the
package is installed, and falls back to the portable store otherwise.

**D2. `AssistantConfig::$embeddingModel` remains the single internal off-switch.** The new
merchant-facing boolean resolves *into* it: switch off, or requirements unmet, means the config reads
an empty model. Everything downstream — no tool constructed, nothing in the model's schema, ingestion
refused at the CLI (spec R13) — is inherited unchanged rather than reimplemented. This is the same
choice `ShopInfoAvailability` already made, for the same reason: a second kind of "switched off" is a
second thing to get wrong.

**D3. The portable store persists vectors as JSON, not as a native vector type.** A `VECTOR(n)` column
must be created lazily because its width is fixed at creation and depends on the embedding model
(`ShopInfoVectorTable`'s own reasoning). JSON has no width, so this table can be a normal migration —
which also means Doctrine can describe it and `dal:validate` stops being a special case.

**D4. Ranking is extracted, not duplicated.** `InMemoryPassageStore` already ranks by
`CosineSimilarity` and filters by `minScore`. The portable store needs exactly that logic. It moves
into a shared `PassageRanking` both call, because the same threshold-and-sort written twice is a
sign error waiting to happen — and the `PassageStore` docblock already warns that the
distance/similarity inversion is "the one thing here most likely to be simplified into a sign error".

**D5. `symfony/ai-store` stays required; `symfony/ai-maria-db-store` becomes suggested.** The former
carries `Vectorizer`, which `PlatformEmbedder` uses to embed. Removing that dependency too would mean
rewriting the embedding layer against `symfony/ai-platform` directly — a separate change with its own
risks, deliberately out of scope here.

**D6. The fallback is announced, not silent — and this is the part of the design most likely to be
dropped as "polish".** Moving `ai-maria-db-store` to `suggest` changes behaviour *for MariaDB shops*,
which is easy to miss because the retrieval path does not change for them. Today: package missing →
the feature is off and `ShopInfoAvailability` says why. After D5: package missing → the shop quietly
runs an O(n) scan instead. A MariaDB shop with a large corpus whose operator overlooked the suggested
package would experience that as "it got slow", with nothing anywhere saying why.

Replacing an honest error with an invisible degradation is the opposite of what the rest of this
project does, so the fallback carries its own evidence:

- a `retrieve.shopinfo.store` trace event on every query, naming which store answered and, when it is
  the portable one, why the other was unavailable;
- a line on the shop-information admin screen saying the same thing in the merchant's words, with the
  `composer require` that would restore the fast path.

Without both, D5 should not ship: keeping `ai-maria-db-store` in `require` is better than a silent
downgrade.

**D7. The new table is dropped on uninstall when the merchant asks for it — and the existing tables
should be too.** Verified in `vendor/shopware/core/Framework/Plugin/PluginLifecycleService.php`:
with `keepUserData() === false` Shopware removes the migration records, the plugin's `system_config`
entries and its assets, then calls `Plugin::uninstall()` — whose own source comment reads *"plugin->
uninstall() will remove the tables etc of the plugin"*. `SwagAssistantStarterKit` is
`class SwagAssistantStarterKit extends Plugin {}`: it has no `uninstall()`, so **no table is ever
dropped**, including `swag_assistant_conversation` and its `transcript` column, which holds what
shoppers typed.

That is a pre-existing gap rather than one this design creates, and every migration's
`updateDestructive()` says why it declined to do it there — *"removal is a deliberate uninstall
decision and not a migration side effect"*. The decision that sentence defers to was never written.

**Closed separately, 2026-08-31, before this design was built.** `SwagAssistantStarterKit::uninstall()`
now honours `keepUserData()` and delegates to `PluginLifecycle\AssistantTableRemoval`, which drops the
four tables children-first — including `swag_assistant_shop_info_vector`, which no migration knows
about because it is created lazily and would therefore be forgotten by anyone working from the
migration list alone.

What remains for *this* design is one line: the portable table joins
`AssistantTableRemoval::TABLES`. Its test walks that constant rather than a copy, so adding the table
without adding it to the list fails.

## Architecture

```
config.xml  "Shop knowledge" card
  enableShopKnowledge (bool, default false)   ← the merchant reads this
  embeddingModel                              ← moves here from "Language model"
  autoIndexShopPages                          ← moves here

SystemConfigAssistantConfig
  embeddingModel := enableShopKnowledge && ShopInfoAvailability->isAvailable()
                    ? stored value : ''

PassageStore (alias)  →  PassageStoreChooser
                           ├── AiStorePassageStore    (MariaDB present)
                           └── DalPortablePassageStore (otherwise)

both →  PassageRanking (extracted from InMemoryPassageStore)
```

### Components

| Unit | Does | Depends on |
|---|---|---|
| `PassageStoreChooser` | Returns the store this shop can use | `ShopInfoAvailability`, both stores |
| `DalPortablePassageStore` | `PassageStore` over an ordinary table; ranks in PHP | `Connection`, `PassageRanking` |
| `PassageRanking` | similarity → threshold → sort → limit | `CosineSimilarity` |
| `Migration…CreateShopInfoPassageTable` | Creates the portable table | — |
| `SwagAssistantStarterKit::uninstall()` | Drops the plugin's tables when the merchant declines to keep data (D7) | `Connection` |

### Data model

`swag_assistant_shop_info_passage`

| Column | Type | Note |
|---|---|---|
| `id` | `BINARY(16)` | |
| `document_id` | `BINARY(16)` | FK to `swag_assistant_document`, `ON DELETE CASCADE` |
| `sales_channel_id` | `VARCHAR(32)` | spec R12 — every query filters on it |
| `section` | `VARCHAR(255)` | |
| `text` | `LONGTEXT` | the passage the model receives |
| `vector` | `JSON` | the embedding, full float64 precision |
| `dimension` | `INT` | so `dimension()` needs no decode |
| `created_at` | `DATETIME(3)` | |

Indexed on `(sales_channel_id)`; there is nothing to index the vector by, which is the trade this
design makes knowingly.

### Error handling

- A width mismatch between stored vectors and the configured model raises the same message
  `StoreWidth` already produces. Changing embedding model invalidates the store either way.
- A query on an empty store returns `[]`, which `SearchShopInfoTool` already treats as
  `NO_MATCH_NOTE`. No new path.
- The chooser cannot fail: if neither store is usable, `ShopInfoAvailability` has already made
  `embeddingModel` empty, so the tool is never constructed and the chooser is never called.

### Testing

| Level | What |
|---|---|
| Unit | `PassageRanking`: threshold boundary, ordering, limit, empty input |
| Unit | `PassageStoreChooser`: picks MariaDB when available, portable otherwise, with a fake availability |
| Unit | `SystemConfigAssistantConfig`: the new boolean resolves into `embeddingModel`, both ways |
| Integration | `DalPortablePassageStore` against a real connection — add, query, delete, dimension, channel isolation |
| Eval | Unchanged. `shop_info_revocation` and `shop_info_not_in_documents` already run PHP cosine. |
| Unit | `uninstall()`: drops every table when `keepUserData()` is false, drops nothing when it is true |
| Live | Index the staging shop's documents and ask the revocation question in German and English |
| Live | Install → uninstall keeping data → reinstall, and confirm the transcripts survive; then uninstall without keeping data and confirm the tables are gone |

## What this cannot test

The eval suite cannot tell the two stores apart, because it uses neither — it uses
`InMemoryPassageStore`. So "the MariaDB path still works after the chooser lands" is **not** covered
by any automated test in this repo, and cannot be without a MariaDB in CI. It has to be verified by
hand on a MariaDB shop, once, and said out loud when it is.

## The difference, listed

For a shop **with** MariaDB: none. The chooser picks the same store, the same SQL runs.

For a shop **without**:

| | MariaDB store (today) | Portable store |
|---|---|---|
| Runs on | MariaDB 11.7+ only | Anything Shopware supports |
| Retrieval | Approximate (HNSW) | Exact |
| Precision | float32 | float64 |
| Query cost | Index lookup | O(n) scan, all channel vectors into PHP |
| Realistic corpus here | 16 passages measured; tens–hundreds plausible | same |
| Measured by the eval suite | no | yes, already |
| Extra packages | `ai-maria-db-store` | none |

Shopper-visible behaviour is identical. The threshold, `MAX_PASSAGES = 3`, both notes, the
per-channel filter, the trace events and the prose audits all live in `SearchShopInfoTool` and above,
and none of them move.

## Decision, 2026-08-31

**Both paths ship.** MariaDB where it is available, the portable store otherwise — D1 unchanged.
Decided by the maintainer against the recommendation this section originally carried; the reasoning
below is kept because a design that hides the argument it lost is worth less later, not more.

The deciding evidence was operational rather than documentary: the MariaDB store has been exercised
by hand on the local shop and does what it claims. That outweighs this repo's test coverage of it,
which is nil — see *What this cannot test*, which is now a standing gap rather than an argument.

Scope follows: A (the merchant-readable switch) first, then B (the portable store, chooser, D6 and
D7's one line). A first because B's help text — which store is active, and the re-index notice below —
belongs in the card A creates.

**One consequence to carry into implementation.** The two stores do not share data: they write to
different tables. The realistic case is not a database move — that leaves nothing behind to migrate —
but the same database gaining or losing `symfony/ai-maria-db-store`, where both tables can coexist and
the chooser switches between them. Nothing is copied. The shop-information screen states which store
answered and, when the other holds passages, says they were indexed with a different store and need
re-indexing. Building a copy between the two would be work for a case we would have to construct.

## Why this was argued the other way

### The case that was made against building B

**Yes for requirement 3, and the argument is stronger than the storage one.**

The storage half is real but narrower than it looks. It buys shop-information retrieval for shops on
MySQL — which is most Shopware shops — at the cost of a new table, a new store, a chooser, a
migration and a dependency reclassification. That is a genuine feature for a starter kit whose whole
purpose is to be forked. But it is also a feature nobody has asked for except by hitting the wall:
one staging shop, once, today.

The switch half is worth building on its own. `embeddingModel` sitting in the *Language model* card is
not a cosmetic defect: it is why a merchant cannot find a feature this plugin already has, and it is
half of why today's outage happened — the value was configured on a shop that could not run it,
because nothing on that screen suggested there was anything to check. Renaming and regrouping that
card is a fraction of the work and fixes the discoverability problem regardless of which store ends
up underneath.

**The honest case against the storage half**, stated so it can be weighed rather than discovered
later:

- It adds a second persistence path to a feature that is off by default and demonstrably little
  used: **16 indexed passages across every shop this project has**, all of them on one developer
  machine. Staging has none. A scan-versus-index argument at that size is not an argument.
- The MariaDB path becomes untestable in CI — see *What this cannot test*. Today there is one store
  and it is equally untested; after this there are two, and the untested one is the one that ships to
  the shops most likely to have real corpora.
- `suggest`ing `ai-maria-db-store` weakens a guarantee: today, a correctly installed plugin can
  always use the fast path. After, "correctly installed" no longer implies it — and D6 exists only to
  keep that from being invisible, which is work that buys back ground this change gave away.

**Recommendation: split it.** Do the switch — the card, the naming, the help text, moving the two
fields — as its own small change, because it is unambiguously right and independent. Then decide the
portable store separately, against one question this design cannot answer from a desk: *does anyone
run this plugin's shop-information feature on a shop that is not MariaDB, other than this one staging
box?* If the answer is yes, build it. If the honest answer is "we do not know", the cheaper move is
to keep `ShopInfoAvailability`'s message — which now names the requirement precisely and tells the
merchant that no MySQL upgrade will help — and revisit when a second shop hits the same wall.
