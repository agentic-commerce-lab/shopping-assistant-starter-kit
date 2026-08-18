# ADR 0001 — Symfony AI as the agent runtime

- **Date:** 2026-08-18
- **Status:** accepted for v0 (research preview)
- **Deciders:** Robin Schulte
- **Supersedes:** an earlier draft in which we wrote the tool registry, tool-calling loop and
  chat client ourselves

## Context

The assistant needs a tool registry, a tool-calling loop, message handling, context-window
management and — because a shopper must not start over after a page load — conversation
memory. The initial plan built all of that by hand (~300 lines) on the argument that a
framework's loop is opaque exactly where our grounding discipline has to intervene.

That argument turned out to be wrong on the facts.

## Decision

Use **`symfony/ai-agent` `0.13.*`** as the agent runtime and
**`symfony/ai-generic-platform` `0.12.*`** as the OpenAI-compatible platform bridge. Keep our
own grounding, commerce gateway, policy and eval layers.

## Why

**The seams exist.** Verified against the source at `symfony/ai@b7fb4cb` (2026-08-17), not the
published docs — which still describe a `Toolbox\AgentProcessor` that 0.13 removed:

- `OutputProcessorInterface::processOutput(Output)` — where id validation, fact rendering and
  prose auditing live
- `InputProcessorInterface::processInput(Input)` with `Input::setMessageBag()` — context compression
- `Agent::__construct(..., ?ToolboxInterface, ?ToolExecutorInterface, ?int $maxToolCalls, ...)`
- `Generic\Factory::createPlatform(string $baseUrl, ?string $apiKey, ?HttpClientInterface, ...)`
  — a configurable base URL *and* an injectable HTTP client, so the SSRF guard survives

**Version compatibility is verified.** `shopware/core v6.7.13.0` pins `symfony/*: ~7.4.0` and
`php: ~8.2 … ~8.5`; `symfony/ai-agent` requires `symfony/*: ^7.3|^8.0` and `php: >=8.2`.
`~7.4.0` satisfies `^7.3` — no conflict.

**Memory and context management come with it.** `Symfony\AI\Chat\MessageStoreInterface` is two
methods with `InMemory`, `Cache` and `SurrealDb` bridges already shipped. Sliding-window and
summarisation input processors are documented recipes. Both were on our own to-build list.

**Plus:** 35+ platform bridges, streaming, a Symfony profiler panel for trace debugging during
the build, and an MCP bundle for the deferred MCP surface.

## The cost we are accepting

`symfony/ai-*` is **0.x**: twelve breaking-change releases from 0.1 to 0.13, and a ~50 KB
`UPGRADE.md`. The most recent release removed the very API the published docs recommend.

For a **lab prototype** this is acceptable: we pin exactly and do not follow upgrades. For a
**plugin shipped to other people's shops** it would not be — a merchant's `composer update`
would break us. Two consequences:

1. Constraints are exact (`0.13.*`, `0.12.*`), never caret. Widening requires reading
   `UPGRADE.md` for the target version.
2. Our public tool contract becomes `#[AsTool]`, i.e. third-party extensions depend on a 0.x
   attribute class. Better DX for Symfony developers, inherited breakage as the price.

**Revisit this ADR before any release beyond a research preview**, or when Symfony AI reaches
1.0 — whichever comes first.

## What we did not delegate, and why

| Kept ours | Reason |
|---|---|
| `CommerceGatewayInterface` + DTOs | Our seam for SaaS portability, fixtures and pre-environment work. Nothing to gain by outsourcing it |
| `FactRenderer` (validate → render → audit prose) | This *is* the product. No framework enforces "the model never states a figure" |
| `VariantResolver` | Shopware-specific correctness, and the most expensive failure mode |
| `BlocklistFilter` + reason-coded `PolicyDecision` | Compliance evidence has to be ours |
| Eval harness | Reads our trace; the differentiator |
| SSRF egress guard | `baseUrl` is merchant-configurable; the framework does not police it |

## Consequences

- Task 4 shrinks to platform wiring plus the egress guard; Task 12 becomes two processors, a
  factory and a ~60-line runner.
- We lose declarative schema bounds (`maxLength`, `maxItems`) because `#[AsTool]` derives the
  schema by reflection. `Core\Tool\Guard` enforces them in the method body instead. This is a
  regression against the hand-written design and must not be skipped.
- Tools must return ids, never `ProductCard`s — anything returned is serialised into the model
  context.
