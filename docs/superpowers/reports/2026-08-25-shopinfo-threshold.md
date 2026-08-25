# Shop Information Threshold — Measurement

**Date:** 2026-08-25
**Task:** Part 1, Task 7 (spec R4 — the threshold is measured, not chosen)
**Outcome:** **No separating threshold exists.** The Gate is not passed. `MIN_SCORE` is left at its
provisional value, and the next step is a design decision on R3, not a number.

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
