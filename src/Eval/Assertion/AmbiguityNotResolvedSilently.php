<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * Fails a turn that answered an ambiguous question by quietly picking one reading of it.
 *
 * ## The failure
 *
 * A shop that sells for cars, motorcycles and bicycles has tyres in all three. A shopper who writes
 * *"ich brauche neue Reifen"* has not said which vehicle, and the shop's own search cannot know —
 * every one of the three worlds matches the word. The assistant then shows five products, and if all
 * five happen to be bicycle tyres, the shopper reads an answer rather than a guess. Nothing on the
 * screen says a choice was made on their behalf.
 *
 * That is worse than an empty result. An empty result is visibly an empty result; a confidently
 * wrong category looks exactly like a correct one, and the shopper only finds out when the tyre does
 * not fit.
 *
 * ## What is asserted, and why it is not "did it ask a question"
 *
 * The requirement is usually phrased as *"the agent asks a follow-up question when there is
 * ambiguity"*. Asserting that literally would pin one solution to the problem, and it is not the
 * only honest one: an assistant that shows a car tyre, a motorcycle tyre and a bicycle tyre side by
 * side has also disclosed the ambiguity — arguably better, because the shopper resolves it by
 * looking instead of by typing.
 *
 * So the property is the one that actually matters, stated as an absence: **the ambiguity must not
 * be resolved silently.** A turn passes when it asked a question, or when what it rendered spans
 * more than one of the competing groups. It fails only when every card comes from a single group and
 * nothing was asked — which is precisely the guess nobody can see.
 *
 * This also survives a fix that would otherwise game it. An assistant rewritten to ask "for which
 * vehicle?" on every message passes here and fails its companion journey, where an already-qualified
 * question must be answered rather than interrogated. Neither assertion is worth much without the
 * other.
 *
 * ## Not a safety assertion, deliberately, and not for ever
 *
 * A safety assertion must pass every run (see {@see Assertion::isSafety()}), and nothing in this
 * project has yet been built to produce this behaviour — the prompt says only to ask when the answer
 * would change what is recommended, which leaves this to the model's judgement. Grading an unbuilt
 * behaviour as safety would put a permanent red line in the suite and teach everyone to ignore it.
 * It takes the 2-of-3 leniency while it is a measurement. Promoting it is a one-line change and the
 * right one to make the day the behaviour is real.
 */
final class AmbiguityNotResolvedSilently implements Assertion
{
    public function name(): string
    {
        return 'ambiguity_not_resolved_silently';
    }

    /**
     * @param array<string, mixed> $expectations `groups`: the competing readings, each named by the
     *        first element of a product's `categoryPath` — the vehicle world in this catalogue
     */
    /**
     * @param array<string, mixed> $expectations `groups`: the competing readings, each named by the
     *        first element of a product's `categoryPath` — see {@see CompetingGroups}
     */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $groups = CompetingGroups::named($expectations['groups'] ?? null);

        if ($groups === []) {
            return new AssertionResult(
                $this->name(),
                false,
                'the journey named no competing groups to measure against',
            );
        }

        $questions = ProseQuestions::in($turn->prose);

        if ($questions !== []) {
            return new AssertionResult(
                $this->name(),
                true,
                \sprintf('the reply asked rather than guessed: "%s"', $questions[0]),
            );
        }

        $rendered = CompetingGroups::renderedIn($turn, $groups);

        if (\count($rendered) > 1) {
            return new AssertionResult(
                $this->name(),
                true,
                \sprintf('no question, but the cards show the ambiguity: %s', implode(', ', $rendered)),
            );
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf(
                'the ambiguity was resolved silently — no question asked, and every card came from %s',
                $rendered === [] ? 'nowhere (no cards rendered at all)' : '"' . $rendered[0] . '"',
            ),
        );
    }

    public function isSafety(): bool
    {
        return false;
    }
}
