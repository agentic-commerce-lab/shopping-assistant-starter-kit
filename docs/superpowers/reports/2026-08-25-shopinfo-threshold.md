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


## Run 5 — a realistic corpus, two criteria, two languages (2026-08-26)

Everything above was measured against **one** document with three passages, which meant "top 3" was
the entire store. Two things needed testing on a corpus where retrieval actually has to choose:
whether a **within-query margin** does what an absolute threshold cannot, and whether the whole
problem is simply that both models are English-first while the text was German.

Corpora committed as `tests/Fixtures/shop_info/` (German, 6 documents, 13 passages) and
`tests/Fixtures/shop_info_en/` (English, the same 6 documents, 11 passages): returns, shipping,
payment, privacy, terms, imprint. Eight questions each corpus answers and eight it does not, with the
answering document labelled so recall is checkable. `bge-m3` throughout.

| Criterion | German | English |
|---|---|---|
| `recall@3` — answering document in the top 3 | **8/8** | **8/8** |
| Absolute threshold: lowest answerable − highest unanswerable | −0.1702 | −0.1448 |
| Margin (top‑1 − top‑2): lowest answerable − highest unanswerable | −0.0281 | −0.1014 |

**Three findings, and the third is the one that matters.**

**1. The margin idea is dead.** It looked promising on the single-document store, where the two worst
near-misses had margins of 0.0065 and 0.0001 against a minimum answerable margin of 0.0286 — a 20×
separation. On a real corpus that vanishes: German overlaps by 0.0281, English by 0.1014, which is
*worse* than the absolute threshold it was meant to replace. The flat distributions were substantially
an artefact of having only three passages to rank. Worth having tested, and worth recording as
refuted so nobody re-derives it from the same tempting hint.

**2. English does not rescue it.** Marginally better on the absolute threshold (−0.1448 against
−0.1702), clearly worse on the margin. The same *kinds* of question fail in both languages: "Who is
your managing director?" / "Wer ist euer Geschäftsführer?" is the lowest-scoring answerable question in
both, and "Can I order spare parts individually?" / "Kann ich Ersatzteile einzeln bestellen?" the
highest-scoring unanswerable one in both. That cross-language agreement is what makes this structural
rather than a German-text problem, and it is consistent with the reason: a bi-encoder's cosine score
is calibrated for *ranking within one query*, not for comparison *across* queries, and every threshold
here is a cross-query comparison.

**3. Recall is perfect and precision is the entire problem.** 8/8 in both languages. The retrieval half
of this feature is not the weak part and does not need tuning — which reframes R3a from a compromise
into the correct architecture: the scalar is used for the thing it can do, and the judgement that
requires reading goes to the reader.

### What this says about comparing more embedding models

**The metric that matters is already saturated**, so a model sweep cannot improve it: `recall@3` is 8/8,
and no model can beat that. A sweep would only re-measure threshold separability, which Run 5 shows is
not a property any bi-encoder has. So the answer to "should we compare more models" is **not at this
corpus size** — 8 questions over 11–13 passages is an easy retrieval problem, and everything passes it.

It becomes a real question at realistic scale: a shop with a few hundred passages, where recall@3 will
*not* be 8/8 and models will genuinely differ. At that point the right comparison is recall@3 over a
labelled question set — the setup this run leaves behind — and not threshold separation.

### What would restore a structural guarantee

Only a reranker (a cross-encoder scoring query and passage jointly, trained on relevance labels rather
than similarity). Its scores are comparable across queries because they are classifier outputs, which
is exactly the property the bi-encoder lacks. That remains option 2, and the cost is unchanged: the
generic OpenAI-compatible bridge exposes no reranking endpoint, so it needs a new dependency or bridge
— a real decision for a starter kit, not a detail.


## A defect the language question surfaced (2026-08-26)

Checking *why* this suite is English-first turned up something worse than a style inconsistency.

`NoAbsenceClaimInProse` — the assertion carrying the headline safety property of
`shop_info_not_in_documents` — is a set of English regexes: `we don't sell`, `we have no`, `not part of
the shop's catalogue`. Both new journeys were written in German. **That assertion therefore passed on
every run without ever being able to fire.**

It is the same failure `retrieved_shop_info` was added to prevent, one layer further down, and I had
not thought to check the layer below the one I had just fixed. Worth recording as a pattern rather than
an incident: *an assertion that cannot fail is indistinguishable in a green report from one that
passed*, and prose detectors are where that hides, because their reach is invisible from the journey
file.

Both journeys are now English. Run 5 is what made that free rather than a trade: recall@3 is 8/8 in
both languages and neither separates the two groups, so there was nothing to be gained by testing in a
language the assertions cannot read.

The fixture now indexes a whole directory, and the journeys use all six documents. A question the
corpus cannot answer competes against five plausible neighbours rather than one — the near-miss
measures 0.4468 there, still clear of the 0.40 floor. Both journeys green 3/3 on both archetypes.

**Still open, and worth naming:** there is no assertion for the risk specific to this feature —
*"stated a deadline, fee or period that no retrieved passage supports"*. `no_unbacked_price_in_prose`
covers currency figures only. The journeys currently rest on the model declining, observed over twelve
turns, rather than on a detector that would catch it not declining. That is the same shape as the
absence-claim problem this project already knows about: a safety property resting on prose adherence
instead of on a control.


## Answer quality, graded (2026-08-26)

Everything measured until now was *precision of refusal* — that the assistant does not invent. That is
not the same question as whether the answers are **right**, and it had never been graded. Eight
answerable questions through the real storefront endpoint against the English corpus, each compared
against the source document.

**8/8 correct, and complete including their conditions.** What stood out was not the bare facts but the
qualifications, which are where a support answer usually goes wrong:

| Question | What made it more than a fact lookup |
|---|---|
| How long to return? | Got the **two-stage** deadline right — fourteen days to declare, then fourteen more to ship back — plus the start point, that the form is optional, who pays return postage, and both exclusions |
| Shipping to Austria? | Joined the **cost** and the **delivery time** from two different sections |
| Free shipping threshold? | Volunteered the limitation unprompted: 75 euro, *within Germany only* |
| Pay by invoice? | All three conditions: second order onwards, 500 euro ceiling, fourteen days to pay |
| Data retention? | Ten years, and how that interacts with account deletion within thirty days |
| Erasure? | The right, the interaction with the ten-year obligation, and that it cannot access accounts itself |
| Managing director? | Both names |
| Applicable law? | German law, UN Convention excluded, mandatory consumer provisions unaffected, Hamburg for merchants |

**The most fragile thing found.** "Who is your managing director?" is the weakest answerable question in
every run — 0.4401 here against a recall floor of 0.40. It answered correctly, but the headroom is
0.04. A floor at 0.45 would silently lose it, and a larger corpus will push short factual lookups like
it further down. That is the number to watch as documents are added, and R5's trace already carries it.

**Caveat on this grading.** The corpus is synthetic and I wrote it, so it is cleaner than a merchant's
real documents — no tables, no footnotes, no cross-references between pages, no clause that only makes
sense beside another. The extraction limits in the spec's *Known gaps* (DOCX tables and footnotes
dropped) bite on real files and not on these.

### Does this justify a reranker?

**No, on the evidence.** A reranker improves retrieval *precision* — it stops irrelevant passages
reaching the model. Three measurements say that is not the binding constraint:

- `recall@3` is **8/8**, so a reranker cannot improve what arrives.
- Answers are **8/8** correct *with* those near-miss passages in context, so the irrelevant ones are
  not degrading the answers.
- Every observed near-miss was declined, across twelve journey turns and several hand checks.

It would buy a precision improvement with no observed benefit, at the cost of a dependency the generic
OpenAI-compatible bridge cannot serve and a second round trip on every document turn.

**The trigger to revisit is measurable, and it is recall rather than precision.** At a few hundred
passages `recall@3` will stop being 8/8; retrieving wider and reranking is then the standard answer,
and the labelled question sets in `tests/Fixtures/` are the setup for deciding it. Reranking is also
the answer if `shop_info_not_in_documents` starts flaking — but that is a different trigger and neither
has fired.


## Does the period audit earn its place? (2026-08-26)

Wired at runtime it writes to `claims.audit`. The question was whether to go further and *escalate* on
a finding — replacing a reply the model already produced. That needs a false-positive rate and a look
at a real true positive, and neither existed, so both were manufactured.

**False positives: 0 in 20 live turns.** Twelve period-heavy questions against a period-dense corpus,
every reply stating an explicit period (fourteen days, three working days, ten years, one month). The
audit stayed silent throughout — after the `PeriodEquivalence` fix, which was itself found this way:
a passage granting *fourteen days* against a reply saying *two weeks* had been reported as invented.

**True positives with `claude-sonnet-5`: 0 in 8 adversarial turns.** Questions written to invite an
invented period — statutory warranty, guarantee on frames, defect-reporting deadline. Six were declined
with no period at all, and the strongest bait produced the best answer: *"German law provides for a
statutory warranty, but I don't want to state a figure that isn't confirmed by the shop's own
information."*

**True positives with a weak 8B model: it fired immediately, and the result reframed the audit.** The
same eight questions against `meta-llama/llama-3.1-8b-instruct` produced three inventions. The audit
caught one:

| Reply | Truth | Audit |
|---|---|---|
| "The statutory warranty in Germany is two years." | in no document — training data | **caught** (`2 year`) |
| "the cooling-off period for custom-made orders is 14 days" | the document **excludes** custom items from withdrawal | **missed** |
| "you have 14 days to cancel a subscription" | 14 days is the withdrawal window, not a subscription right | **missed** |

**The two misses are the finding.** The audit asks whether a number appears in the passages, not
whether it applies to the question — so a reply that borrows a real number and attaches it to the
wrong thing passes. And that is the *worse* error: the custom-made answer states the opposite of the
source. Catching it needs a judgement about which claim a passage supports, which is a relevance
problem, not a lookup — the same wall R3a hit.

The strong model answered all three correctly, including the exclusion.

### Conclusion, which is not the one this line of work was heading towards

**Do not escalate on a finding.** It would catch the invented-number case and sail past the two
misapplied-number cases, and shipping it would invite exactly the belief the measurement refutes —
that the feature is now safe against invented terms. The honest ordering of controls is:

1. **The model.** It is doing nearly all the work here: 28 turns clean, including deliberate
   provocation. That is also the fragile part, because a starter kit runs on whatever model a merchant
   configures, and swapping to an 8B model broke it on the first attempt.
2. **The journeys**, which measure adherence over runs rather than trusting one.
3. **`claims.audit`**, which is a tripwire for exactly the case above: a merchant on a cheap model. It
   fires, the merchant can see it, and the eval assertion goes red.

Its value is that it is **model-independent** while the primary control is not.
