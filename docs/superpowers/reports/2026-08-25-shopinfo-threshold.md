# Shop Information Threshold — Measurement

**Date:** 2026-08-25
**Task:** Part 1, Task 7 (spec R4 — the threshold is measured, not chosen)
**Outcome:** **No separating threshold exists, with any of three embedding models.** R3 is revised
rather than parameterised: the threshold becomes a recall floor (0.40 with `bge-m3`) and the model
decides relevance. See *Run 4* and *The decision* below.

## What was measured

`openai/text-embedding-3-small` and `openai/text-embedding-3-large` via the shop's configured
OpenRouter endpoint, against the statutory German *Muster-Widerrufsbelehrung* (2477 bytes, 3 chunks).

**Why not the shop's own revocation page:** it is Lorem ipsum. The demo data's
`core.basicInformation.revocationPage` contains `<h2>Widerrufsbelehrungen</h2>` followed by
placeholder Latin, which has no German legal vocabulary to match against — calibrating on it would
have produced a number about lorem ipsum. The statutory template is what German shops actually carry,
so it is the more real document of the two.

Every score below is the **best** score of the three chunks, read from
`swag:assistant:shopinfo --query`, which prints rejected scores precisely so this table could exist.

## Run 1 — `text-embedding-3-small`, 1536 dimensions

| Question | Answered by the document? | Best score |
|---|---|---|
| Was ist die Widerrufsfrist? | yes | 0.6772 |
| Kann ich den Vertrag widerrufen? | yes | 0.6044 |
| Wie lange kann ich zurueckschicken? | yes | 0.5445 |
| Ab wann laeuft die Frist? | yes | 0.4881 |
| **Muss ich Gruende angeben?** | **yes** | **0.3622** |
| **Wann kommt meine Bestellung an?** | **no** | **0.4155** |
| Wie hoch sind die Versandkosten nach Japan? | no | 0.3660 |
| Kann ich mit Bitcoin bezahlen? | no | 0.3019 |
| Wer ist euer Geschaeftsfuehrer? | no | 0.2623 |
| Habt ihr das Trikot in XL? | no | 0.2448 |

Lowest answerable **0.3622** sits below highest unanswerable **0.4155**. Overlap: **0.053**.

## Run 2 — chunking ruled out

The first suspect was dilution: a 1200-character chunk covers several topics, so a short specific
question might match the chunk's average topic rather than its one relevant sentence. Tested by
re-indexing the same text as three single-sentence documents — the finest granularity possible, well
beyond anything the chunker would produce.

| Question | Big chunks | Single sentences |
|---|---|---|
| Was ist die Widerrufsfrist? (yes) | 0.6772 | 0.7049 |
| Ab wann laeuft die Frist? (yes) | 0.4881 | 0.4706 |
| **Muss ich Gruende angeben?** (yes) | 0.3622 | **0.3509** |
| **Wann kommt meine Bestellung an?** (no) | 0.4155 | **0.4395** |

**The overlap widened rather than closed** — 0.3509 against 0.4395. And it widened on the case that
matters: the passage retrieved for "Muss ich Gruende angeben?" was, in its entirety, *"Sie haben das
Recht, binnen vierzehn Tagen ohne Angabe von Gruenden diesen Vertrag zu widerrufen."* The passage is
nothing but the answer, and it scored 0.35. **Chunking is not the cause.**

## Run 3 — `text-embedding-3-large`, 3072 dimensions

| Question | Answered? | Best score |
|---|---|---|
| Was ist die Widerrufsfrist? | yes | 0.5973 |
| Kann ich den Vertrag widerrufen? | yes | 0.5742 |
| Wie lange kann ich zurueckschicken? | yes | 0.5701 |
| Ab wann laeuft die Frist? | yes | 0.3713 |
| **Muss ich Gruende angeben?** | **yes** | **0.3566** |
| **Wann kommt meine Bestellung an?** | **no** | **0.3668** |
| Wie hoch sind die Versandkosten nach Japan? | no | 0.3343 |
| Kann ich mit Bitcoin bezahlen? | no | 0.2860 |
| Habt ihr das Trikot in XL? | no | 0.2853 |
| Wer ist euer Geschaeftsfuehrer? | no | 0.2602 |

Overlap narrows from 0.053 to **0.010** — the better model helps, and still does not separate the
groups. A threshold at 0.36 would admit an unanswerable question; one at 0.37 would reject an
answerable one.

## The finding

Two questions fail in opposite directions in **every** configuration, and they are not noise — they
are the two structural failure modes of single-stage bi-encoder retrieval:

1. **"Muss ich Gruende angeben?" is always the lowest answerable score** (0.3509–0.3622). The
   document answers it with a negation inside a subordinate clause — *"ohne Angabe von Gruenden"*.
   Cosine distance between a question and a passage does not encode "X is not required" as a match
   for "must I do X?". No amount of chunking or dimensionality changes that, which Run 2 confirms.
2. **"Wann kommt meine Bestellung an?" is always the highest unanswerable score** (0.3668–0.4395). A
   returns-deadline document genuinely shares its vocabulary — Tage, Frist, Ware, Lieferkosten — with
   a delivery-timing question. It is *topically* close and *factually* silent, which is exactly the
   confident-wrong-answer case R3 exists to prevent.

A single scalar cannot put those two on the correct sides of one line. **This is not a threshold to
split the difference on** — the plan says so, and the measurement says why.

## What this means for R3, and what I did not do

`MIN_SCORE` is **left at the provisional 0.75** and not changed. Note what that value implies: no
score in any run exceeded 0.71, so the feature as it stands answers nothing and always returns the
no-match note. That is the safe direction — it invents nothing — but it is not a working feature, and
pretending otherwise by lowering the constant to 0.40 would ship precisely the unmeasured constant
this project spent the week eliminating. It would also fail in both directions at once.

**Task 8 (the journeys) was not run.** The Gate says to stop here, and `shop_info_revocation` would
currently assert against a tool that can never return a passage — a red journey that reports the
threshold, not the model's behaviour.

## The three ways forward

Ordered by what the measurement supports, not by cost.

1. **Move the relevance decision to the model** — retrieve at a low recall threshold (~0.30) and hand
   the model 2–3 passages with an explicit instruction that they may be irrelevant and that it must
   say so when none answers the question. This is the only option that can handle the negation case,
   because reading *"ohne Angabe von Gruenden"* as the answer to *"muss ich Gruende angeben?"* needs
   something that can read. **It changes R3** — the threshold stops being a gate and becomes a recall
   filter — so it is a spec decision, not an implementation choice. The risk it takes on is the one
   R3 was written to avoid, and it would need `no_absence_claim_in_prose`-style journeys to hold the
   line instead.
2. **A reranker (cross-encoder) between retrieval and the tool's reply.** The standard fix for exactly
   this problem, and it keeps R3 intact: retrieve wide, rerank, threshold on the rerank score. Costs a
   second provider round trip per document turn and a provider that exposes reranking — the generic
   OpenAI-compatible bridge does not, so this needs a new dependency or a new bridge.
3. **A different embedding model, chosen for German.** Both models measured here are English-first.
   A multilingual model (`bge-m3`, `multilingual-e5-large`) may separate these ten questions. Cheapest
   to test — change one setting, delete, re-index, re-run the same ten questions, about ten minutes
   with the CLI now in place — and the one honest thing to try before accepting a design change. But
   Run 3 is a caution: quadrupling the dimensions bought 0.04 of overlap, so a model change may not
   be enough either.

## Verified as a side effect

- **The dimension guard works in production.** Switching from `-small` to `-large` with 1536-wide
  vectors still in the store produced: *"The shop information store holds 1536-wide vectors but the
  configured embedding model produces 3072. Changing the embedding model invalidates every indexed
  document: delete them and index them again."* The plan's second planning gap is closed.
- **The lazy vector table works.** It was created at `vector(1536)` on the first write, refused the
  width change while non-empty, and was recreated at `vector(3072)` once emptied.
- **R8 replacement holds.** Re-indexing a changed document left exactly one generation, with the new
  text and no trace of the old.


## Run 4 — `bge-m3`, 1024 dimensions

Tried because a multilingual model is the right shape for German legal prose, and because prior
experience with it was good. **It separates the groups worse than either OpenAI model.**

| Question | Answered? | Best score |
|---|---|---|
| Was ist die Widerrufsfrist? | yes | 0.7360 |
| Wie lange kann ich zurueckschicken? | yes | 0.6759 |
| Kann ich den Vertrag widerrufen? | yes | 0.6528 |
| Ab wann laeuft die Frist? | yes | 0.5972 |
| **Muss ich Gruende angeben?** | **yes** | **0.4421** |
| **Wann kommt meine Bestellung an?** | **no** | **0.5539** |
| Wie hoch sind die Versandkosten nach Japan? | no | 0.4615 |
| Kann ich mit Bitcoin bezahlen? | no | 0.4549 |
| Habt ihr das Trikot in XL? | no | 0.3316 |
| Wer ist euer Geschaeftsfuehrer? | no | 0.3041 |

| Model | Lowest answerable | Highest unanswerable | Overlap |
|---|---|---|---|
| `text-embedding-3-small` | 0.3622 | 0.4155 | 0.053 |
| `text-embedding-3-large` | 0.3566 | 0.3668 | 0.010 |
| `bge-m3` | 0.4421 | 0.5539 | **0.112** |

`bge-m3` raises every score — its range is 0.30–0.74 against `-large`'s 0.26–0.60 — so the absolute
overlap grows. High baseline similarity is a known property of the model and it is not a defect; it is
simply orthogonal to the thing being asked of it here. **"A multilingual model will fix German
retrieval" was measured, and it did not hold.**

Getting this far also required a code fix: the generic bridge decides whether a model name refers to
an embedding model by testing it for the substring `embed`, so `bge-m3` was routed to a completions
client that the embeddings-only platform does not have, failing with *"No ModelClient registered"*
before any request. That heuristic ruled out every embedding model not named by OpenAI. See
`EmbeddingsOnlyModelCatalog`.

## The decision

Four runs, three models, and the same two questions fail in opposite directions every time. The
conclusion is not "keep looking for a threshold" — it is that a bi-encoder similarity score answers
*"is this passage about the same topic?"* while the tool needs *"does this passage contain the
answer?"*, and those come apart precisely on negations and topical near-misses.

So **R3 is revised** (spec R3a): the threshold becomes a **recall floor** and the model judges
relevance.

- `RECALL_MIN_SCORE = 0.40`, with `bge-m3`. Below the lowest answerable score (0.4421) with margin, so
  every question the document can answer gets its passage through; above the plainly unrelated (0.33
  size question, 0.30 imprint question), which it still discards.
- It makes **no attempt** to exclude the near-misses at 0.45–0.55. Nothing could: they outrank a
  question the document genuinely answers.
- Those reach the model with `SearchShopInfoTool::RELEVANCE_NOTE`, which states outright that some
  passages may be irrelevant and that it must decline rather than stretch one to fit.

**Why `bge-m3` and not `-large`,** given `-large` had the smallest overlap. A recall floor needs margin
underneath the lowest answerable score, not a narrow overlap. With `-large` the floor would have to sit
at or below 0.3566 while an irrelevant passage scores 0.3668 — a 0.01 window that any new question
would move. `bge-m3` allows any floor up to 0.4421 and spreads its scores more widely, so the recall
property survives questions not in this sample. It is also multilingual, which the text is, and 1024
dimensions rather than 3072.

**What this costs, stated plainly.** R3 promised that the model could not be handed an irrelevant
passage. That guarantee is gone; it was never achievable. What replaces it is an instruction, and an
instruction is a request rather than a guarantee — this project measured this week that a model
overrides an explicit instruction not to claim absence in roughly one run of three. Which is why
`shop_info_not_in_documents` stops being a nice-to-have and becomes the test that holds this line.
That journey is the next task, and if it proves unreliable the answer is a reranker (option 2 above),
not a larger number here.


## Aftermath — does the instruction hold? (2026-08-26)

R3a replaced a guarantee with an instruction, so the instruction had to be measured. Two journeys,
three runs each, two archetypes each — twelve model turns, against the real provider and the real
retrieval chain.

**Both pass 3/3 on both archetypes.** In every run of `shop_info_not_in_documents` the model was
handed passages that had cleared the recall floor and declined to answer from them anyway.

The finding worth recording is how nearly that measurement was worthless. The first version of the
expert archetype — *"Welche Garantie gebt ihr auf Rahmenbrüche?"* — scored **0.3973, 0.3936, 0.3840**
across three runs: just under the 0.40 floor. So the model received nothing, declined for want of
information, and all four safety assertions passed **having tested nothing at all**. It would have
been reported as evidence that R3a works.

`retrieved_shop_info` is the assertion that caught it, on its first run, and it is now what stops
either journey going green without retrieving. Its `expectPassages` flag is the load-bearing half:
under R3a the risky path is the model *receiving* plausible passages and declining, so a run where
nothing cleared the floor tested the easy path and must not count.

The fix was to sharpen the question rather than lower the floor. *"Welche Gewährleistungsfrist gilt für
Rahmenbrüche?"* scores **0.4822** — it shares its second half with *Widerrufsfrist*, against a document
that is entirely about Widerrufsfristen and silent about Gewährleistung. High lexical overlap, wrong
legal concept, which is the sharpest near-miss the fixture can produce: a model that conflates the two
states a warranty period derived from a revocation clause.

Lowering the floor to admit the 0.39 phrasing was rejected for the reason the rest of this report
documents — the floor cannot separate these groups, so moving it down only admits more near-misses
without admitting anything the document can actually answer.

**What is still unproven.** Twelve turns is evidence, not a guarantee, and it is one model
(`anthropic/claude-sonnet-5`). This project has measured a model overriding a comparable instruction in
roughly one run of three, so the honest reading is that the instruction holds well *here* and needs
watching. If it degrades, the answer is a reranker — not a higher floor.


## Regression: the fifteen existing journeys (2026-08-26)

`--filter '/^(?!.*scale_).*$/'`, small catalogue, live model. **17 journeys, 16 pass.** 15 minutes.

The one failure is `no_match_not_absence · expert`, `no_absence_claim_in_prose` **2/3** — run 3 said
*"we don't have"*. That is the same journey, archetype, assertion and failure shape the previous
change's report recorded as its own baseline (*"failed 2/3 — one run said 'we don't carry'"*), and the
journey file itself documents the weakness as roughly one run in five historically.

**Not a regression, and this time the structural argument is airtight rather than merely persuasive:**

1. **The tool is not in that journey's toolbox at all.** `search_shop_info` reaches a journey only when
   its `config` declares `embeddingModel`, and `no_match_not_absence` does not. `JourneyConfig` yields
   `''`, `JourneyAttempt::shopInfoFactories()` returns `[]`, and the tool is never constructed — so it
   cannot have influenced the reply by any path.
2. **The only field this work adds to every journey's config is `salesChannelId`, and nothing in the
   core tool path reads it.** Checked rather than assumed: `config->salesChannelId` has exactly two
   readers in the whole tree, both of them shop-info classes. Every other use of a sales-channel id
   takes it as a parameter from the DAL or HTTP path, and the eval harness has no DAL.

**What I am not claiming.** Re-running the journey immediately afterwards gave **1/3**, not the green
the previous report got from the same manoeuvre. Across today that archetype measured 2/3 and 1/3,
against a history of 2/3, 3/3, 2/3 — the low end of the documented range. At three runs a piece there
is no way to separate model drift from ordinary noise, and it would be dishonest to present either
reading as established. What is established is that this work cannot be the cause.

The journey's own conclusion still stands and is untouched by this work: a safety claim resting on a
prompt is the defect, and the fix is wiring `NoAbsenceClaimInProse` into `ProseAudit` so the reply
carries a warning the way unbacked prices already do.
