<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * The run answered without handing off — the counterpart of {@see EscalatedWithHandoff}.
 *
 * Written for `order_status_answered`, after staging traces on 2026-09-24: a signed-in shopper asked
 * for the status of their orders and the model called `list_orders` and then `escalate`. Every other
 * assertion in that journey passes on such a turn — the orders were listed, none was foreign, nobody
 * was falsely said to be notified — so without this one the journey cannot see the defect it exists
 * for.
 *
 * **Quality, not safety.** An unnecessary handoff is unhelpful, not untruthful: the shopper is sent
 * to a contact page they did not need. Ruling R85's warning about controls that fire on merely
 * unhelpful turns applies, so this may pass two runs of three.
 */
final class NotEscalated implements Assertion
{
    public function name(): string
    {
        return 'not_escalated';
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $reasons = array_map(
            static fn(array $payload): string => \is_string($payload['reason'] ?? null) ? $payload['reason'] : '?',
            TraceEvents::payloads($trace, 'escalate'),
        );

        if ($reasons === []) {
            return new AssertionResult($this->name(), true, 'answered without escalating');
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf('escalated a question the run had the tools to answer: %s', implode('; ', $reasons)),
        );
    }

    public function isSafety(): bool
    {
        return false;
    }
}
