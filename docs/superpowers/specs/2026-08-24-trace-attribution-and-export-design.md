# Trace Attribution and Export — Design

**Date:** 2026-08-24
**Status:** approved in conversation; implementation plan to follow

## Purpose

Two things a merchant cannot do today with the conversation list:

1. **See who a conversation belonged to.** The table shows when, which sales channel, how it ended and how long it took — never who was on the other side. A support question ("this customer says the assistant told them the wrong price") cannot be answered from the list at all.
2. **Get the traces out.** Everything lives behind the admin UI. Handing one conversation to a developer, or counting outcomes across a week, both mean reading rows off a screen.

These ship together because the second is where the first earns its keep: a CSV of conversations without the customer is a table nobody can act on.

## Decisions

Each row is a decision that was actually taken, not a summary of options.

| # | Decision | Why |
|---|---|---|
| T1 | **Store the customer id and nothing else**, as a real foreign key: `swag_assistant_conversation.customer_id BINARY(16) NULL`, `FOREIGN KEY … REFERENCES customer (id) ON DELETE SET NULL`. The name is read through the association | When a customer deletes their account the link is severed **by the database**, with no erasure routine over this table for anyone to forget. Snapshotting the name would leave personal data behind; keeping an unconstrained id would leave a pseudonymous identifier pointing at a transcript forever, which is the same problem wearing a hat. A real association also lets the list and the export read the name through `addAssociation('customer')` rather than the client-side map the sales-channel column needs (its column is VARCHAR, so the DAL cannot join it) |
| T2 | **Attribution is captured once, when the conversation starts.** A guest who logs in mid-conversation stays a guest on that conversation | The column answers "who produced this trace". Updating it per turn would make it "the last identity seen", which answers nothing exactly. A shopper who logs in and keeps talking starts a conversation that is attributed correctly the moment their next one begins |
| T3 | **Two display states:** no customer → `Guest user`; otherwise the customer's name | A third state for "was a customer, now deleted" is not available and that is the point: `ON DELETE SET NULL` removes the id itself, so a deleted customer's conversation becomes indistinguishable from a guest's. **Accepted cost:** a merchant reading an old trace sees `Guest user` where someone had been logged in. Preserving the distinction would mean keeping a marker about a person after they asked to be forgotten, which is the trade this design refuses |
| T4 | **The export carries the resolved name**, exactly as the screen shows it | Robin's decision, made against the stated alternative of exporting ids only. **Consequence, accepted:** an exported file is personal data. Whoever forwards it forwards customer names, and it is not covered by the shop's retention pruning once it has left. Recorded here so the trade is on the record rather than discovered later |
| T5 | ~~**Two formats, chosen by which button was pressed** — CSV for counting, JSON for handing over~~ **Reversed 2026-08-24: JSON only, and no format parameter.** | Both uses were named, so two formats looked right. Building it showed the flaw: a trace is **nested and formvariable** — N events, each with an arbitrary payload — and CSV is the one shape that cannot hold it. What the CSV actually carried was one summary row per conversation: no `page.context`, no `retrieve`, no payloads. That is a useful artefact, but it is a **metrics table, not a trace**, and a button labelled "export" beside a list of traces promises the latter. Robin asked for it to be removed after opening one. The metrics use is not served today; if it comes back it should be one row per **event**, which is the trace flattened rather than the trace summarised |
| T6 | **One server endpoint produces both formats** | The CSV's useful columns — shop time against model time, tool calls — are derived from the events, which the list does not load. Any format needs the events, so both belong on the server. It also puts the privilege check (T4 makes it a real one) somewhere the frontend cannot skip, and gives the size bound a place to live |
| T7 | **A hard bound of 1 000 conversations per export**, refused with the count | Retention keeps 30 days; a busy shop is well past a thousand. A synchronous download that assembles tens of thousands of event rows is not a feature, and failing loudly beats a request that times out |
| T8 | **The two buttons act on the selection when there is one, and on every row matching the current filter when there is not.** The label carries the count either way | Robin asked for "select all or tick boxes". Shopware's grid select-all covers the current page only — 25 rows — so a literal reading would quietly under-deliver. Falling back to the filter needs no second pair of controls, and the count in the label is what stops it being a surprise |

## Architecture

Three pieces, each testable on its own.

**Attribution** — a nullable column, one new optional parameter on `ConversationStore::start()`, one value read from the `SalesChannelContext` the chat controller already holds. Nothing else changes: `append()`, `history()` and the trace writer never see it.

**Serialisers** — two pure classes turning a list of conversations-with-events into bytes. No repository, no request, no framework. They are where the shop/model split is computed, and they are the reason this feature is testable without a database.

**Endpoint** — an `api`-scoped controller that resolves ids to conversations, resolves customer names, enforces the privilege and the bound, and hands the result to a serialiser. It owns policy; the serialisers own format.

```
Admin list ──ids + format──▶ AssistantTraceExportController
                                 │  privilege, bound, id resolution
                                 ▼
                            conversations + events + customer names
                                 │
                    ┌────────────┴────────────┐
                    ▼                         ▼
            TraceCsvSerialiser        TraceJsonSerialiser
              one row per                full fidelity,
              conversation               payloads verbatim
```

## Data flow

**Writing.** `AssistantController::chat()` reads `$context->getCustomer()?->getId()` and passes it to `start()` on the turn that creates the conversation. A conversation resumed by token never re-reads it (T2).

**Reading, list.** The grid loads a page of conversations with `criteria.addAssociation('customer')`. The name comes off the association; a null association is a guest. No client-side id map is needed — that hack exists for sales channels only because their column is a VARCHAR the DAL cannot join.

**Exporting.** The admin posts ids and a format. With no selection it first asks the repository for the ids matching the current filter, bounded by T7, and posts those. The response is a file; the browser saves it through a blob, because the route is authenticated and cannot be opened in a tab.

## Formats

**CSV** — one row per conversation, header included, RFC 4180 quoting:

```
id,createdAt,salesChannel,user,turns,outcome,totalMs,shopMs,modelMs,toolCalls
01a0337f…,2026-08-24T10:12:04Z,Storefront,Guest user,1,product_shown,3656,22,3634,0
```

`shopMs` and `modelMs` are derived from event offsets with the same rule the detail view uses, so a number in the file matches the number on the screen. `toolCalls` counts `tool.call` events.

**JSON** — an array of objects, one per conversation, each carrying its metadata, its transcript and every event with its payload unchanged. Pretty-printed: it is read by people, and a diff between two exports should be legible.

## Error handling

| Condition | Behaviour |
|---|---|
| An unknown or absent `format` field | Ignored. There is one format, so there is nothing to choose and nothing to refuse |
| More than 1 000 conversations | 400 with the count, naming the bound. Nothing is assembled |
| Empty id list | 400. An export of nothing is a mistake, not an empty file — and it should be unreachable: with T8 an empty list means the filter matched nothing, so the buttons are disabled in that state. The check exists because "unreachable" is a claim about today's caller |
| Unknown or malformed id | Skipped, and the response header names how many were dropped. One stale id must not cost the merchant the rest of the export |
| Missing privilege | 403 from Shopware's own ACL layer, before the controller runs |
| Conversation whose customer was deleted | `Guest user` in the file, the same string the screen shows — the id is gone, so there is nothing else it could truthfully say (T3) |

## Testing

- **Serialiser and summary**: PHPUnit against hand-built conversations. The shop/model split against a known event sequence; a guest and a named customer; payloads surviving verbatim; events ordered by `seq`.
- **Controller**: the bound refuses at 1 001 and passes at 1 000; an empty list is refused; an unknown id is skipped rather than fatal; the privilege is declared on the route.
- **Attribution**: a turn from a logged-in context stores the id; a guest stores null; a resumed conversation does not overwrite it (T2).
- **No browser test.** The format logic is pure derivation, and the download mechanics are three lines of standard admin plumbing.

## Out of scope

- **Distinguishing one guest from another.** It would need a visitor identifier, which is the tracking this design deliberately does not introduce (T1).
- **Scheduled or emailed exports.** Nothing asked for them.
- **Re-attributing a conversation when a guest logs in** (T2). If it turns out merchants want it, it is a new decision, not a bug.
- **A human-readable export** (PDF/Markdown). Considered and explicitly not chosen: the two named uses were analysis and hand-over.
- **A metrics export.** The CSV that served the analysis use was removed (T5). If it returns, it should be one row per event — the trace flattened — not one row per conversation, which is a summary wearing a trace's name.
