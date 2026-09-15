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
 * be resolved silently.** A turn passes when the REPLY names more than one reading, or asks which
 * was meant. It fails when neither happens.
 *
 * ## What used to count and no longer does
 *
 * Rendering cards from two different groups was a third way to pass, on the reasoning that a car
 * tyre beside a bicycle tyre discloses the ambiguity without a word. **Checked 2026-09-14, and it
 * does not:** `CardPayload` never sends `categoryPath` and `card.js` never renders it, so a shopper
 * looking at "Innensechskantschraube 4,20 €" and "Nummernschildschraube 8,90 €" has no way to know
 * one is a bicycle part. The branch was reading a field that reaches nobody — the same mistake, in
 * the same file, as counting a question about screw size as a resolution.
 *
 * It returns the day a card shows its world. The retrieval already spreads across groups as a side
 * effect of {@see \Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier}; what is missing is
 * telling anyone that it did.
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
     * @param array<string, mixed> $expectations `groups`: group name => words naming it in prose;
     *        `axis`: optional words naming the DIMENSION generically ("Fahrzeug") — see
     *        {@see CompetingGroups}
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

        // Still read, but no longer a pass on its own: see the class docblock. The shopper is
        // never told which world a card belongs to, so cards spanning two of them disclose nothing.
        $rendered = CompetingGroups::renderedIn($turn, $groups);

        $mentioned = GroupVocabulary::mentionedIn($turn->prose, $groups);

        if (\count($mentioned) > 1) {
            return new AssertionResult(
                $this->name(),
                true,
                \sprintf('the reply put the ambiguity in words: %s', implode(', ', $mentioned)),
            );
        }

        $axis = CompetingGroups::named(['x' => $expectations['axis'] ?? []])['x'] ?? [];

        if (ProseQuestions::in($turn->prose) !== [] && GroupVocabulary::asksTheAxis($turn->prose, $axis)) {
            return new AssertionResult($this->name(), true, 'the reply asked which reading was meant');
        }

        // Two very different failures, and collapsing them into one sentence sent a reader looking
        // for an ambiguity problem when the turn had simply shown nothing. `renders_at_least` is
        // what names that properly; this only has to stop claiming otherwise.
        if ($rendered === []) {
            return new AssertionResult(
                $this->name(),
                false,
                'nothing was rendered and nothing was asked, so the shopper was left with neither an '
                . 'answer nor the question — see renders_at_least for the half this cannot judge',
            );
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf(
                'the ambiguity was resolved silently — every card came from "%s", and nothing in the '
                . 'reply names another reading or asks which was meant',
                $rendered[0],
            ),
        );
    }

    public function isSafety(): bool
    {
        return false;
    }
}
