# Glossary

Terms that have already cost us time. Read this before joining the project.

## Who faces whom

The word "customer" is ambiguous in Shopware-land: Shopware's customer is the **merchant**;
the merchant's customer is the **shopper**. So "customer-facing" parses two ways. We do not
use it.

| Term | Meaning |
|---|---|
| **Shopper-facing** | The shopper talks to it. This project. |
| **Merchant-facing** | Merchant staff talk to it. Copilot. Not this project. |
| **Merchant-operated** | The merchant owns and controls the agent. This project. |
| **Owned surface** | The merchant's own storefront, as opposed to ChatGPT's surface. |

## Three different "personas"

| Term | What it is | Where it lives |
|---|---|---|
| **Agent voice** | How the assistant talks. Merchant-authored. | `config.xml`, a constrained prompt slot — it cannot reach grounding or policy |
| **Shopper profile** | Who this shopper is: customer group, locale, and things inferred in conversation ("I'm a beginner") | Runtime session state, dropped at TTL |
| **Archetype** | A synthetic shopper used to parameterise eval journeys (beginner, expert) | Frozen eval fixtures, never runtime |

## Pipeline terms

| Term | Meaning |
|---|---|
| **Grounding** | Every product claim traces to a retrieved record. The model picks IDs; the server renders the facts. |
| **Facet probe** | Asking the catalog which filter fields actually exist, before building a query. Stops the model inventing filter fields. |
| **Variant resolution** | Parent product → the specific variant → *its* price and stock. Where these systems usually break. |
| **Gateway** | `CommerceGatewayInterface`. The one seam. Plain DTOs cross it; no Shopware types. |
| **Trace** | The structured record of all pipeline stages for one turn. Also the data source for the evals. |
| **Blocklist** | Products/categories filtered out before *and* after retrieval. A filter, never a prompt instruction. |

## Eval terms

| Term | Meaning |
|---|---|
| **Fixture** | A synthetic product with a deliberate defect (missing description, sold-out variant, injection payload in the description). |
| **Journey** | A scripted shopper conversation with assertions attached. |
| **Assertion** | A check evaluated against the **trace**, not the prose. Deterministic; no LLM judge. |
| **Safety vs quality assertion** | Safety must pass 3/3 runs — a control that works twice in three is not a control. Quality passes at 2/3. |
