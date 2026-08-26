# Shop Information Retrieval — Design

**Date:** 2026-08-25
**Status:** approved in conversation; implementation plan to follow
**Scope:** v1 — uploaded documents. CMS extraction is a separate plan (decision R1).

## Purpose

The assistant can answer questions about products and nothing else.

Shoppers ask about returns, revocation, shipping and privacy at least as often as they ask about a
variant's stock. Today every one of those questions is unanswerable — not answered badly, not
answered from stale data: **not answerable at all.** That is the largest gap in what this kit can do,
and unlike the scale work of the last days it is a missing capability rather than a defect.

The risk it introduces is different in kind from anything already here. A wrong stock figure
disappoints a shopper. An invented revocation period is a legal statement the merchant did not make.
So the discipline this project already applies to prices — the server owns the facts, the model
chooses the words, an audit catches the gap — has to be extended rather than relaxed.

## What was verified while designing this

Every row measured on 2026-08-25 against the local shop and the installed tree, so the plan can wire
rather than investigate.

| Claim | Finding |
|---|---|
| MariaDB supports native vectors | **11.8.8** running; `VECTOR(4)` + `VECTOR INDEX` + `VEC_DISTANCE_COSINE` tested, correct ordering |
| `symfony/ai` has a store abstraction | `symfony/ai-store` plus ~30 adapters, including `symfony/ai-maria-db-store` (requires MariaDB ≥ 11.7) |
| It can reuse the existing connection | `VecStore::fromDbal($connection)`; the plugin already requires `doctrine/dbal: ^4.0` |
| Threshold and filtering are expressible | Store `query()` takes `limit`, `maxScore`, `where` + `params`; `VectorDocument::getScore()` exposes the score. **Corrected 2026-08-25 while implementing:** the bridge is *distance*-based, not similarity-based — `maxScore` is an upper bound on cosine distance (identical `0`, orthogonal `1`, measured on 11.8.8) and there is no structured filter, only a raw SQL `where` with bound `params`. `PassageStore` keeps `Core` in similarity space and the adapter converts |
| PDF text extraction is available | `smalot/pdfparser` v2.12.5 already in the tree, pulled in by `horstoeko/zugferd`; extraction tested end to end |
| HTML text extraction is available | `masterminds/html5` already in the tree; tested, scripts strippable, block elements become paragraph lines |
| DOCX needs no dependency | `ZipArchive` is built in; `word/document.xml` with `</w:p>` → newline then `strip_tags` tested — three `<w:r>` runs joined into one correct sentence |
| The legal pages are addressable, not searchable | `core.basicInformation.{imprintPage, privacyPage, revocationPage, tosPage, shippingPaymentInfoPage, contactPage}` all hold real CMS page ids |
| The plugin can host the UI | An admin module already exists (the trace view); `config.xml` already uses `text`/`password` fields |

## Decisions

| # | Decision | Why |
|---|---|---|
| R1 | **v1 ingests uploaded documents only.** Automatic ingestion of the five legal CMS pages is a separate plan | A CMS page is a tree of sections → blocks → slots with text distributed across slot configurations. It is the largest single piece of the feature and has its own failure modes (empty slots, text in unexpected block types, HTML remnants). Proving the whole chain — extract, chunk, embed, query, replace — against a file format we control comes first |
| R2 | **`symfony/ai` supplies the infrastructure; the tool is ours.** `Vectorizer`, `MariaDbStore`, `VectorQuery`, `DocumentIndexer` from the library; the tool, its reply shape, the threshold and the trace events written here | The ready-made `SimilaritySearch` tool cannot express two things this design requires: the threshold (it wraps `Retriever`, which wraps the store query with defaults) and the sales-channel filter — both live on the store query. It would also emit none of this plugin's trace events, leaving document turns a blind spot in the admin trace view, precisely where "why did it say that" matters most |
| R3 | ~~**Below a similarity threshold there is no passage at all.**~~ **Revised 2026-08-25 by measurement — see R3a** | The original reasoning still stands as a description of the risk: vector search always returns its nearest passage, whether or not it answers the question, and a paraphrase of the nearest passage is a confident wrong answer. What turned out to be false is that a threshold can tell those apart |
| R3a | **The threshold is a recall floor, and the model decides relevance.** Passages above it reach the model with an explicit warning that some of them may be irrelevant and that it must say so when none answers the question | Measured across three embedding models (`text-embedding-3-small`, `-large`, `bge-m3`): the lowest score for a question the document *answers* always fell **below** the highest score for one it does not. Two structural cases cause it — an answer carried by a negation ("ohne Angabe von Gruenden" answering "muss ich Gruende angeben?") and a topical near-miss that shares the document's whole vocabulary while being absent from it ("wann kommt meine Bestellung an?") — and they sit on opposite sides of any line a scalar could draw. Finer chunking widened the overlap; a bigger model narrowed it to 0.010 without closing it. What all three models *do* reliably is retrieve the right passage among their top few, so the scalar is used for what it can do and the relevance judgement goes to the only component that can read a negation. **This takes on the exact risk R3 was written to avoid**, so the `shop_info_not_in_documents` journey is now load-bearing rather than a nice-to-have. Full data: `docs/superpowers/reports/2026-08-25-shopinfo-threshold.md` |
| R4 | **The threshold is a constant, not a merchant setting.** Its starting value is determined empirically during implementation, not chosen here. **Settled 2026-08-25: 0.40 with `bge-m3`**, measured | No merchant calibrates a cosine distance. And a number written into a design document before anything has been indexed is exactly the unmeasured constant this project has spent the week finding: the plan indexes one real document, runs five questions the document answers and five it does not, and reads the scores. Whatever separates those two groups is the starting value, recorded with the measurement beside it. If they do not separate, that is a finding about chunking, not a threshold to split the difference on |
| R5 | **The tool traces every score, including the ones below the threshold** | We do not know the right value yet, and two of the last days' findings were unmeasured constants. `scores: [0.62, 0.58], threshold: 0.75, accepted: 0` in the trace makes "we would have had the answer at 0.62" visible after a week of real use, rather than guessed at now |
| R6 | **The model receives the passage text and may paraphrase it** | Explicit product decision. Note the asymmetry with products, where the model receives no figures because the card carries them: here there is no card, so the text is the payload |
| R7 | **No rendered source in v1** | Explicit product decision, taken with the counter-argument on the table. Recorded as a gap in *Known gaps* rather than silently dropped, because it is what makes `no_invented_source` impossible |
| R8 | **A new version of a document replaces the old one: its chunks are deleted before the new ones are written** | Otherwise a merchant who uploads the corrected revocation notice without deleting the old one has both in the store, and retrieval can serve either. Nobody would see it. This requires a document identity, which is why R9 exists |
| R9 | **Documents are first-class entities**, with a name, type, upload date, chunk count, the extracted text, and a status of `pending`, `indexed` or `failed` (with a reason) | The identity R8 needs, the list the admin module shows, and the reason re-indexing needs no file access. Storing the text rather than the bytes also removes the question of where uploaded files live |
| R10 | **Five formats: PDF, TXT, MD, HTML, DOCX.** One extractor per format behind one interface | All five verified above with no new dependency. HTML is in v1 deliberately even though nobody uploads HTML today: the CMS plan of R1 produces exactly HTML, so building the path now means that plan inherits it instead of inventing a second one |
| R11 | **Chunking is paragraph-based with overlap, bounded** | Legal texts have paragraphs; that is the natural unit. A character count splits "binnen vierzehn Tagen" from "ab Erhalt der Ware", and then the model paraphrases a deadline without its start date |
| R12 | **Every chunk carries its sales-channel id, and every query filters on it** | The legal pages are configured per sales channel, so two channels genuinely have different terms. A shared store without this filter serves one channel's revocation notice in another. The same class of mistake as a cache key that ignores its tenant, which this project hit once already this week |
| R13 | **An empty `embeddingModel` setting turns the whole feature off** — no tool in the toolbox, no ingestion | A starter kit that offers a tool which always fails is worse than one that offers no tool. Same posture as the existing kill switch |
| R14 | **No query cache in v1, but the embedding call is timed in the trace** | Retrieval must embed the question, so a document turn costs one extra API round trip. It is a tool, so product questions pay nothing. Whether repeated questions justify a cache is a measurement, and R5's trace already carries the timing |

## Architecture

```
   Admin module (Vue)                         Storefront turn
   upload · list · delete · re-index                │
            │                                       ▼
            ▼                            SearchShopInfoTool
   DocumentIngestion                      ├─ Vectorizer  (embeds the question)
    ├─ TextExtractor  (PDF|TXT|MD|HTML|DOCX)  ├─ store->query(limit, minScore, filter)
    ├─ Chunker        (paragraphs + overlap)  ├─ threshold → passages OR note
    └─ Vectorizer ─────────┐                  └─ trace: scores, accepted, ms
                           ▼                          │
                     MariaDbStore  ◄───────────────────┘
              (the shop's own database, VECTOR column)
                           ▲
   AssistantDocument ──────┘  identity, status, chunk count, extracted text
```

`Vectorizer` is shared by both paths on purpose: the vector that answers a question has to come from
the same model that produced the vectors it is compared against. A mismatch there is silent and total.

**How the sales channel reaches the tool.** R12's filter needs a sales-channel id, and the tool sits
above the gateway seam, where naming a Shopware type is forbidden — `CommerceGatewayInterface`'s
docblock is explicit that only `Dto\` types may cross. So it arrives as a plain string constructor
argument, exactly as `SearchProductsTool` already receives `$browsingCategoryId`. The seam holds; the
tenant is still in the key.

### The tool's reply

```php
[
    'passages' => [
        [
            'id'       => 'widerruf#3',
            'document' => 'Widerrufsbelehrung',
            'section'  => 'Widerrufsfrist',
            'text'     => 'Sie haben das Recht, binnen vierzehn Tagen …',
        ],
    ],
    'total' => 1,
    'note'  => '…',   // only when nothing cleared the threshold
]
```

The note follows `SearchProductsTool::NO_MATCH_NOTE`'s precedent — it does not merely report absence,
it tells the model what absence means and what not to do with it: *say you cannot find it in the shop
information and point at the page; invent no deadline, no address and no condition.*

## Testing

**Deterministic, no model and no API:**

- `TextExtractor`, one case per format, plus the one that matters most: **a PDF with no text layer — a
  scan — must fail into the document's status**, not ingest as an empty document that silently never
  matches anything.
- `Chunker`: paragraph boundaries, overlap, the bound, and a document with no paragraph breaks at all.
- Threshold and reply shape, against the in-memory store `symfony/ai-store` ships — so no embedding
  call in the suite. Below the threshold: a note and no passages.
- **Sales-channel isolation**, asserted directly: a chunk indexed for channel A is invisible to a
  query for channel B. Not assumed from the filter's presence.
- Replacement: re-indexing a document leaves exactly one generation of its chunks.

**Against a model, as journeys:**

- `shop_info_revocation` — a revocation question is answered from the document.
- `shop_info_not_in_documents` — a question the documents do not answer produces "I cannot find that
  here" and **no invented deadline**. The more important of the two.
- The fifteen existing journeys must stay green. A new *tool* is a stronger intervention than the new
  reply fields were this week: it changes what the model can choose, not just what it reads.

**End to end in the demo shop:** upload a document, ask the revocation question through the widget,
ask a question the document does not answer, and re-index after replacing the file.

## Known gaps

Stated because they were decided, not overlooked.

- **No rendered source (R7),** and therefore **no `no_invented_source` assertion.** With a
  server-rendered source line, the analogue of `no_invented_product` becomes possible: the model must
  not attribute a claim to a document it never received. Without one, a shopper reading a paraphrase
  of legal text has no way to check it, and we have nothing to audit the attribution against. The
  cheapest future fix is one line of payload and one line of widget.
- **DOCX extraction reaches body prose only.** Tables, headers, footers and footnotes are dropped by
  the `</w:p>` approach. Footnotes can carry substance in legal texts. `PhpOffice/PhpWord` would be
  complete, at the cost of a dependency for a case nobody has measured.
- **`smalot/pdfparser` is transitive**, pulled in by `horstoeko/zugferd` for ZUGFeRD invoices. It must
  be declared explicitly — the same shadow-dependency finding as `psr/cache` this week — and if
  Shopware ever drops ZUGFeRD, PDF support goes with it.
- ~~**The threshold ships uncalibrated.**~~ Calibrated 2026-08-25, and the calibration is what forced
  R3a: no threshold separates answerable from unanswerable questions. The recall floor is measured
  (0.40 with `bge-m3`), but **relevance now depends on the model's judgement rather than on a
  guarantee**, which is a weaker property than R3 promised. R5's trace remains the instrument for
  revisiting it.
- **`bge-m3` scored *worse* at separation than either OpenAI model** (overlap 0.112 against 0.053 and
  0.010) despite being the multilingual choice. It is still the model used, because a recall floor
  wants margin under the lowest answerable score and bge-m3 has the most — but "multilingual model
  fixes German retrieval" was measured and did not hold.

## Out of scope

- CMS extraction from `core.basicInformation` (R1) — its own plan, and the reason HTML is in R10.
- XLSX and CSV. Not prose: a size table does not chunk into meaningful passages, and serving one needs
  a different reply shape. If size tables matter, that is a feature, not a format.
- OCR, and therefore scanned PDFs. A different class of dependency; the requirement here is only that
  such a file fails loudly.
- ODT. The same zip-and-xml mechanism as DOCX, rare in this context, and one class to add later
  because R10 puts every format behind one interface.
- Expiry dates per document, and a query cache (R14).
- Streaming, human-in-the-loop reliability, and B2B capabilities — the three other roadmap items,
  deliberately not mixed in.

## Practical notes the plan must not rediscover

- **`schema_filter`.** Doctrine will try to manage the store's vector column and fail. The library's
  own guidance is `schema_filter: '~^(?!ai_)~'`; inside a Shopware plugin this is more delicate than
  in a standalone app, because Shopware manages its own schema. Decide it in the plan, not at the
  first `doctrine:schema:update`. **Resolved 2026-08-25:** avoided rather than configured. The vector
  table is created by the library's own raw DDL on the first write, has no DAL entity, and Shopware
  evolves schema through migrations rather than `doctrine:schema:update` — so nothing inspects it and
  there is nothing to filter. See `ShopInfoVectorTable`.
- **`ai:store:setup`** creates the store's tables. A plugin has to call it on install or ship an
  equivalent migration. **Resolved 2026-08-25:** neither. A `VECTOR` column has a fixed width and the
  width comes from whichever embedding model the merchant configured — which at install time is
  usually none, so install-time creation would have to guess (the library guesses 1536). The table is
  created on the first write instead, at the width the model actually produced.
- **The embedding model is a plugin setting** beside `llmBaseUrl`, `llmModel` and `llmApiKey`, and
  reuses the same OpenAI-compatible provider URL. **Verified 2026-08-25** against the lab shop's
  OpenRouter configuration with `openai/text-embedding-3-small`: it serves `/v1/embeddings`, and a
  document indexed at 1536 dimensions.
- **`symfony/ai-maria-db-store` ships a Symfony Flex recipe that breaks a Shopware shop** — the second
  package to do so, and the same failure the README already documents for `symfony/ai-generic-platform`.
  Installing it writes `config/packages/ai_maria_db_store.yaml` with an `ai.store` key that only
  `symfony/ai-bundle` can load, and every console command and every request then dies with *"There is
  no extension able to load the configuration for ai"* — not just the new feature, the whole shop.
  Found by hitting it. **Not a new class of problem:** the README's existing fix, writing a
  comment-only placeholder before `composer require` so Flex leaves it alone, now covers both files.
  Any further `symfony/ai-*` dependency needs the same treatment.
- **Plugin dependencies live in the shop's vendor tree, not the plugin's.** `composer require` inside
  the plugin is not enough for a path-repository install: the shop needs
  `composer update swag/assistant-starter-kit -W` before the new classes can be autoloaded.
