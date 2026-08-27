<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * How many questions the assistant asked, bounded from above.
 *
 * ## What this is really measuring
 *
 * Not politeness — friction. Measured 2026-08-26 on the fashion catalogue: *"looking for wedding
 * stuff"* returned a question about product type, **no search and no cards**. The shopper paid a full
 * round trip and got a form. Meanwhile the yoga runs recommended four products and *then* offered to
 * narrow by size, which costs the shopper nothing because they can ignore it and click a card.
 *
 * So a bound of 0 is the right assertion for a set small enough to show whole, and a bound of 1 for a
 * set too large — but neither is worth anything without {@see RendersAtLeast} beside it, which is what
 * separates "asked while showing" from "asked instead of showing".
 *
 * ## What counts as one question
 *
 * Question SENTENCES, not question marks — the rule and its reasoning live in {@see ProseQuestions}.
 *
 * ## Why it is not a safety assertion
 *
 * A turn that asks one question too many is *worse*, not unsafe, and demanding three of three on a
 * stochastic model turns ordinary variance into a report that the assistant is unsafe — the argument
 * {@see ToolCallsAtMost::isSafety()} already makes.
 */
final class QuestionsAtMost implements Assertion
{
    public function name(): string
    {
        return 'questions_at_most';
    }

    /** @param array<string, mixed> $expectations */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $max = $expectations['max'] ?? null;

        if (!\is_int($max) || $max < 0) {
            return new AssertionResult(
                $this->name(),
                false,
                'Declare an integer "max" of 0 or more. An expectation nothing reads reports green about nothing.',
            );
        }

        $questions = ProseQuestions::in($turn->prose);
        $count = \count($questions);

        if ($count <= $max) {
            return new AssertionResult(
                $this->name(),
                true,
                \sprintf('%d question(s), at most %d allowed.', $count, $max),
            );
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf('%d question(s) against a limit of %d: "%s".', $count, $max, implode('" / "', $questions)),
        );
    }

    public function isSafety(): bool
    {
        return false;
    }
}
