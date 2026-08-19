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

Use **`symfony/ai-agent` `0.12.*`** as the agent runtime and
**`symfony/ai-generic-platform` `0.12.*`** as the OpenAI-compatible platform bridge. Keep our
own grounding, commerce gateway, policy and eval layers.

## Why

**The seams exist.** Verified against the **installed `vendor/` tree at 0.12.0**:

> **Correction, 2026-08-18.** This ADR was first written against `symfony/ai@b7fb4cb`, the
> project's GitHub trunk. That commit is 0.13-in-development and **0.13 is not released** —
> Packagist's latest is `v0.12.0`. Two claims in the first draft were therefore wrong: that
> `Toolbox\AgentProcessor` had been removed (it exists in 0.12 and is how tool calling is
> wired), and that `Agent` takes `toolbox`/`toolExecutor`/`maxToolCalls` constructor arguments
> (0.12 takes only platform, model, input processors, output processors, name). The 0.x risk
> this ADR describes materialised on the first task of implementation. Rule in force: no
> Symfony AI API enters the plan unless it was read from the installed `vendor/` tree.

- `OutputProcessorInterface::processOutput(Output)` — where id validation, fact rendering and
  prose auditing live
- `InputProcessorInterface::processInput(Input)` with `Input::setMessageBag()` — context compression
- `Agent::__construct(PlatformInterface, string $model, iterable $inputProcessors, iterable $outputProcessors, string $name)`
- `Toolbox\AgentProcessor(ToolboxInterface, ToolResultConverter, ?EventDispatcherInterface, bool $excludeToolMessages, bool $includeSources, ?int $maxToolCalls)` — both an input and an output processor
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

**Confirmed, not assumed (2026-08-19).** The installed `vendor/symfony/ai-agent/README.md`
states it outright: *"This Component is experimental. Experimental features are not covered by
Symfony's Backward Compatibility Promise."* The first draft of this ADR guessed that parts
might be marked experimental; the installed package says so in its own README. Every consumer
of this dependency is therefore outside Symfony's BC promise by the maintainers' own
declaration — which is the fact to put in front of anyone deciding whether this graduates
beyond a research preview.

The 0.12 changelog also shows the churn reaching the primary entry point: 0.12 changed
`AgentInterface::call()` to accept `string|MessageBag|UserMessage` and **renamed its first
parameter from `$messages` to `$input`**. Positional calls survived; named-argument calls did
not. Pass the message bag positionally.

For a **lab prototype** this is acceptable: we pin exactly and do not follow upgrades. For a
**plugin shipped to other people's shops** it would not be — a merchant's `composer update`
would break us. Two consequences:

1. Constraints are exact (`0.12.*`, `0.12.*`), never caret. Widening requires reading
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

## Addendum, 2026-08-19 — what the first live runs cost us at 0.12

The "cost we are accepting" section above was written from the changelog. Two live runs against
a real model turned it into measured evidence. Both findings are the same shape: **a 0.12
default that looks like a safety feature and is not one, discovered only by running it.**

| What 0.12 appears to give us | What it actually does | What we had to build |
|---|---|---|
| `AgentProcessor(maxToolCalls: n)` — a bound on tool calls per turn | The round counter is a local inside the method that recurses once per tool round, so every recursion resets it to zero. The cap is unreachable at any depth. A live run made 21 HTTP calls at a configured cap of 3 | `Agent\BoundedToolbox`, counting in a property that survives recursion |
| `Toolbox::execute()` — tool dispatch with error handling | Its `catch (\Throwable)` wraps *everything* into `ToolExecutionException`, argument-coercion failures and genuine tool faults alike, then rethrows. A model sending `options: "Blue"` for a list-of-objects parameter therefore destroys the turn — a 500 for a shopper | The same decorator, inspecting `$previous` to separate bad model input (→ retryable `['note' => …]`) from a real fault (→ rethrow) |

Neither was visible from the changelog, from the README, or from any test that used a scripted
transcript. Both required a real model and a real endpoint.

A process note worth keeping, because it cost time twice: **both findings were initially
misdiagnosed from a partial read of the vendor source.** The first plan claimed an API that
existed only on unreleased trunk; the fix brief for the second claimed `Toolbox::execute()` let
the serializer exception propagate raw, because the read stopped three lines above the
`catch (\Throwable)` that wraps it. The rule from this ADR's original draft — *no Symfony AI
API enters a plan unless it was read from the installed `vendor/`* — holds, with an amendment:
**read the whole method, not the part that confirms the hypothesis.**

None of this changes the decision. Writing the tool-calling loop ourselves would have cost more
than these two decorators, and the seams the ADR bet on (`InputProcessorInterface`,
`OutputProcessorInterface`, `ToolboxInterface`) were exactly the seams that let us fix both
defects without forking. But it sharpens the revisit trigger: the framework's defaults are not
yet load-bearing at 0.x, so **every safety-relevant framework default this project relies on
must be verified by an integration test that would fail if the default silently stopped
working** — not by reading that the option exists.
