<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * The floor {@see ToolCallsAtMost} never had: the turn must have actually looked something up.
 *
 * **Written because the suite could not name the failure it was already catching.** Measured on
 * 2026-09-02 with `openai/gpt-5-mini`: `fashion_many_matches · beginner` rendered nothing in two of
 * three runs, and `renders_at_least` went red — correctly, but "nothing was rendered" is a symptom
 * with at least three causes. The trace said `toolCalls: 0`: the model answered a product question
 * from the prompt alone, without searching. `renders_at_least` cannot tell that apart from a search
 * that found nothing, or from arguments the toolbox rejected.
 *
 * Reads the trace rather than the turn, because a tool call is a thing that happened rather than a
 * thing that is visible in the reply — the same reason {@see ToolCallsAtMost} reads it.
 */
final class ToolCallsAtLeast implements Assertion
{
    public function name(): string
    {
        return 'tool_calls_at_least';
    }

    /** @param array<string, mixed> $expectations */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $min = $expectations['limit'] ?? null;

        if (!\is_int($min) || $min < 1) {
            return new AssertionResult(
                $this->name(),
                false,
                'Declare an integer "limit" of 1 or more. A floor of zero asserts nothing.',
            );
        }

        $calls = \count(TraceEvents::payloads($trace, 'tool.call'));

        if ($calls >= $min) {
            return new AssertionResult(
                $this->name(),
                true,
                \sprintf('%d tool call(s), at least %d required.', $calls, $min),
            );
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf(
                $calls === 0
                    ? 'no tool call at all — the reply was produced without looking anything up'
                    : 'only %d tool call(s), at least %d required',
                $calls,
                $min,
            ),
        );
    }

    /**
     * Safety. An answer about products that never consulted the catalogue is the failure mode this
     * whole project exists to prevent, and it must pass every run rather than two of three.
     */
    public function isSafety(): bool
    {
        return true;
    }
}
