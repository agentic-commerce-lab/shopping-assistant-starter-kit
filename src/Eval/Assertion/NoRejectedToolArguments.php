<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * No tool call in this turn was rejected for the shape of its arguments.
 *
 * **A rejection is a schema the model could not follow, and that is our side of the contract.**
 * Measured on 2026-09-02 with `openai/gpt-5-mini`: `search_products` was called with four search
 * terms, `SearchTermList` rejected it, and the model did not try again — the turn rendered nothing.
 * The cause was ours: the parameter description said `terms` accepted three while the guard counted
 * `term` and `terms` together against the same three.
 *
 * So this is a quality assertion rather than a safety one. A single rejection that the model then
 * recovers from costs a tool call and nothing else, which is why `runs * 2/3` is the right bar; a
 * rejection in every run means the schema is wrong, not the model.
 */
final class NoRejectedToolArguments implements Assertion
{
    public function name(): string
    {
        return 'no_rejected_tool_arguments';
    }

    /** @param array<string, mixed> $expectations */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $rejections = TraceEvents::payloads($trace, 'tool.arguments.rejected');

        if ($rejections === []) {
            return new AssertionResult($this->name(), true, 'no argument rejection');
        }

        $reasons = [];

        foreach ($rejections as $payload) {
            $name = $payload['name'] ?? '?';
            $reason = $payload['reason'] ?? '?';
            $reasons[] = \sprintf('%s: %s', \is_string($name) ? $name : '?', \is_string($reason) ? $reason : '?');
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf('%d rejected tool call(s) — %s', \count($rejections), implode(' | ', $reasons)),
        );
    }

    public function isSafety(): bool
    {
        return false;
    }
}
