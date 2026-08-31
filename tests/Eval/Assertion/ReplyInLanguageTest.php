<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\ReplyInLanguage;

/**
 * The only assertion in the suite that reads {@see AssistantTurn::$prose} for its own sake, and the
 * reason is that the thing being asserted has no other home: the language of a reply is a property
 * of the sentence, and nothing in the trace or on a rendered card records it.
 *
 * **A function-word count rather than a model judge.** The rule under test — answer in the shopper's
 * language — is decided by the model, so a model judging it would be the same class of component
 * grading its own homework, non-deterministically, for money, on every run. Function words are the
 * part of a sentence that cannot be avoided and cannot be borrowed: a German reply about a "Trail
 * Jersey" still says "das", "ist" and "für", and an English one still says "the", "is" and "with".
 * Product names, which are the only vocabulary the two share in this catalogue, are not function
 * words and so do not count for either side.
 */
final class ReplyInLanguageTest extends TestCase
{
    private function verdict(string $prose, string $expect): bool
    {
        return (new ReplyInLanguage())->evaluate(new AssistantTurn($prose, [], 'product_shown'), new TraceRecorder(), [
            'expect' => $expect,
        ])->passed;
    }

    public function testAGermanReplyPassesAGermanExpectation(): void
    {
        self::assertTrue($this->verdict(
            'Ich habe das Trail Jersey in Blau gefunden. Möchtest du eine bestimmte Größe sehen?',
            'German',
        ));
    }

    public function testAnEnglishReplyPassesAnEnglishExpectation(): void
    {
        self::assertTrue($this->verdict(
            'I found the Trail Jersey in Blue. Would you like to see a particular size?',
            'English',
        ));
    }

    /** The failure the German journeys exist to catch: the shopper wrote German, the shop did not. */
    public function testAnEnglishReplyFailsAGermanExpectation(): void
    {
        self::assertFalse($this->verdict(
            'I found the Trail Jersey in Blue. Would you like to see a particular size?',
            'German',
        ));
    }

    public function testAGermanReplyFailsAnEnglishExpectation(): void
    {
        self::assertFalse($this->verdict(
            'Ich habe das Trail Jersey in Blau gefunden. Möchtest du eine bestimmte Größe sehen?',
            'English',
        ));
    }

    /**
     * **The case that would otherwise pass for the wrong reason.** An English product name carries
     * no function words, so a reply that is nothing but names has nothing to count on either side —
     * and a verdict of "German wins 0 to 0" would make every such turn green regardless of what the
     * model did. A journey asserting a language needs a sentence, so this is a failure with a
     * message that says which one it is, not a silent pass.
     */
    public function testAReplyWithNoFunctionWordsAtAllFailsRatherThanPassingVacuously(): void
    {
        self::assertFalse($this->verdict('Trail Jersey, Blue, M.', 'German'));
    }

    /**
     * A German reply naming English products must not be dragged across by the names. This is the
     * shape every reply in the German journeys actually has, since the fixture catalogue's products
     * are named in English.
     */
    public function testEnglishProductNamesInsideAGermanReplyDoNotFlipTheVerdict(): void
    {
        self::assertTrue($this->verdict(
            'Ich habe für dich das Trail Jersey, den Commuter Glove und das Winter Mudguard Set '
            . 'gefunden. Welches davon soll ich dir genauer zeigen?',
            'German',
        ));
    }

    public function testAnUnknownExpectationIsAFailureRatherThanASilentPass(): void
    {
        self::assertFalse($this->verdict('Ich habe das Trail Jersey gefunden.', 'Klingon'));
    }

    public function testItIsASafetyAssertion(): void
    {
        // Answering a German shopper in English is not a grounding failure, but it is the kind that
        // must hold on every run rather than two of three: a control that works twice in three
        // conversations is not a control, and the whole point of the language rule is that a shopper
        // never has to wonder which language they will get.
        self::assertTrue((new ReplyInLanguage())->isSafety());
    }
}
