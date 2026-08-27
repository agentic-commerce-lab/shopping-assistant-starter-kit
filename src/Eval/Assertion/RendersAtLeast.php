<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * The turn put at least this many products in front of the shopper.
 *
 * ## The failure it catches
 *
 * A turn that asks a question and shows nothing. Measured 2026-08-26: *"looking for wedding stuff"*
 * against a catalogue holding six occasion dresses and six occasion suits produced *"could you tell me
 * a bit more — an occasion dress, a suit, accessories, or a gift?"* with **no search and no cards**.
 *
 * That is the shape shoppers experience as being interrogated, and it is not the same thing as asking
 * too many questions. A question *beside* products costs nothing — the shopper can ignore it and click
 * a card. A question *instead of* products costs a whole round trip. So this assertion, not
 * {@see QuestionsAtMost}, is the anti-friction control; the two are only meaningful together.
 *
 * Deliberately a floor rather than an exact set: {@see RenderedIdsExactly} already owns "these ids and
 * no others" for the journeys that can name them, and this one exists for the journeys that cannot —
 * where which products is a quality question and *whether any* is not.
 */
final class RendersAtLeast implements Assertion
{
    public function name(): string
    {
        return 'renders_at_least';
    }

    /** @param array<string, mixed> $expectations */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $count = $expectations['count'] ?? null;

        if (!\is_int($count) || $count < 1) {
            return new AssertionResult(
                $this->name(),
                false,
                'Declare an integer "count" of 1 or more. A floor of zero asserts nothing.',
            );
        }

        $rendered = \count($turn->cards);

        if ($rendered >= $count) {
            return new AssertionResult(
                $this->name(),
                true,
                \sprintf('%d card(s) rendered, at least %d required.', $rendered, $count),
            );
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf(
                '%d card(s) rendered against a floor of %d — the shopper was asked or told something without being shown products.',
                $rendered,
                $count,
            ),
        );
    }

    /**
     * Safety, unlike {@see QuestionsAtMost}.
     *
     * A turn that shows the shopper nothing is not a matter of degree: every run of a journey that
     * declares this floor is claiming the assistant answers with products, and one run in three that
     * does not is the behaviour the journey exists to forbid.
     */
    public function isSafety(): bool
    {
        return true;
    }
}
