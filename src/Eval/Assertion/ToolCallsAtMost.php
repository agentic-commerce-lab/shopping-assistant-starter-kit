<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * How many tools the model reached for, bounded from above.
 *
 * ## Why this exists
 *
 * Measured 2026-08-24 against the live shop: asked *"is this in stock?"* on the page of a product
 * already named in its prompt and already registered on the `FactRenderer`, the model called
 * `get_product` anyway — two round trips and 13.8 s for a card the shop was going to render either
 * way. One sentence of {@see \Swag\AssistantStarterKit\Core\Prompt\ViewingContext} was the cause,
 * and changing it took the same question to one round trip, zero tool calls and 3.7 s.
 *
 * **No unit test in this project can see that.** A tool call the model chose to make is not a bug in
 * any class; the suite stays green through it. The eval suite is the only layer that observes model
 * behaviour, so this is the only place the regression can be caught.
 *
 * ## Why it is not a safety assertion
 *
 * A turn that calls a tool and renders the right card is **correct**, only slower. {@see self::isSafety()}
 * returns false so this gets the 2-of-3 threshold quality assertions get; demanding three of three
 * would turn ordinary model variance into a report that the assistant is unsafe, which it would not
 * be.
 *
 * ## What it counts
 *
 * Every `tool.call` event in the run, which is what
 * {@see \Swag\AssistantStarterKit\Core\Agent\TurnToolCallCounter} counts and therefore what
 * `turn.end`'s own `toolCalls` figure means. In this harness one {@see TraceRecorder} spans every
 * turn of a journey (ruling R84), so **the bound is per run, not per turn** — a multi-turn journey
 * must budget for all of its turns together.
 */
final class ToolCallsAtMost implements Assertion
{
    public function name(): string
    {
        return 'tool_calls_at_most';
    }

    /**
     * @param array<string, mixed> $expectations
     */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $limit = $expectations['limit'] ?? null;

        if (!\is_int($limit) || $limit < 0) {
            return new AssertionResult(
                $this->name(),
                false,
                'Declare an integer "limit" of 0 or more. An expectation nothing reads reports green about nothing.',
            );
        }

        $names = array_map(
            static fn(array $payload): string => \is_string($payload['name'] ?? null) ? $payload['name'] : '?',
            TraceEvents::payloads($trace, 'tool.call'),
        );

        $count = \count($names);

        if ($count <= $limit) {
            return new AssertionResult($this->name(), true, \sprintf('%d tool call(s), limit %d.', $count, $limit));
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf('%d tool call(s) against a limit of %d: %s.', $count, $limit, implode(', ', $names)),
        );
    }

    public function isSafety(): bool
    {
        return false;
    }
}
