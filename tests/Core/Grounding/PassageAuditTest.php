<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\PassageAudit;

/**
 * A period stated in the reply that no retrieved passage supports.
 *
 * ## The risk this closes
 *
 * Shop-information retrieval hands the model passages and lets it paraphrase them (spec R6), and
 * spec R3a then makes the model — not a threshold — responsible for noticing that a retrieved passage
 * does not answer the question. Twelve journey turns showed it doing that correctly, but an
 * instruction is not a control, and the failure it guards against is specific: a reply that states a
 * revocation deadline, a retention period or a warranty term the documents never granted. That is a
 * legal statement about the merchant's business, and unlike a wrong price no card contradicts it.
 *
 * ## Why the false-positive half is tested harder than the true-positive half
 *
 * Because it is what kills the control. `ProseAudit::unbackedPrices()` already carries the lesson in
 * its own docblock — a safety warning that fires on correct behaviour trains people to ignore it —
 * and here the temptation is severe: the model *is supposed to* paraphrase, so "vierzehn Tagen" comes
 * back as "14 Tage" and a naive check calls the correct answer an invention.
 */
final class PassageAuditTest extends TestCase
{
    private const RETURNS_PASSAGE =
        'You have the right to withdraw from this contract within fourteen days without giving any '
            . 'reason. We will reimburse all payments no later than fourteen days from the day on which '
            . 'we are informed of your decision.';

    private const GERMAN_PASSAGE = 'Sie haben das Recht, binnen dreissig Tagen ohne Angabe von Gruenden zu widerrufen.';

    public function testAPeriodTheDocumentsStateIsNotFlagged(): void
    {
        self::assertSame(
            [],
            (new PassageAudit())->unsupportedPeriods('You have fourteen days to send the goods back.', [
                self::RETURNS_PASSAGE,
            ]),
        );
    }

    /** The paraphrase that a substring check would report as an invention. */
    public function testDigitsAreNotAnInventionOfWhatThePassageSpelledOut(): void
    {
        self::assertSame(
            [],
            (new PassageAudit())->unsupportedPeriods('You have 14 days to send the goods back.', [
                self::RETURNS_PASSAGE,
            ]),
        );
    }

    public function testTheSameHoldsInGerman(): void
    {
        self::assertSame(
            [],
            (new PassageAudit())->unsupportedPeriods('Die Widerrufsfrist betraegt 30 Tage.', [self::GERMAN_PASSAGE]),
        );
    }

    /** The case the whole class exists for: a warranty term no passage grants. */
    public function testAPeriodNoPassageSupportsIsFlagged(): void
    {
        self::assertSame(
            ['24 month'],
            (new PassageAudit())->unsupportedPeriods('We give a 24 month warranty on frames.', [self::RETURNS_PASSAGE]),
        );
    }

    /** Stretching a retrieved deadline into a different number is the subtle version of the same thing. */
    public function testAPeriodNearButNotEqualToThePassagesIsFlagged(): void
    {
        self::assertSame(
            ['30 day'],
            (new PassageAudit())->unsupportedPeriods('You have 30 days to return the goods.', [self::RETURNS_PASSAGE]),
        );
    }

    /**
     * Working days are not days, so a passage promising working days does not license a reply
     * promising calendar days. A Friday order arriving in three working days arrives on Wednesday.
     */
    public function testWorkingDaysDoNotSupportCalendarDays(): void
    {
        self::assertSame(
            ['3 day'],
            (new PassageAudit())->unsupportedPeriods('Your order arrives within 3 days.', [
                'Delivery within Germany normally takes one to three working days.',
            ]),
        );
    }

    public function testWithNoPassagesEveryStatedPeriodIsUnsupported(): void
    {
        // The no-match path: the tool returned nothing, so anything numeric the model says about a
        // deadline came from its own general knowledge of consumer law.
        self::assertSame(
            ['14 day'],
            (new PassageAudit())->unsupportedPeriods('Under EU law you generally have 14 days to withdraw.', []),
        );
    }

    public function testAReplyThatStatesNoPeriodIsClean(): void
    {
        self::assertSame(
            [],
            (new PassageAudit())->unsupportedPeriods('I cannot find that in the shop information — please check the returns page.', [
                self::RETURNS_PASSAGE,
            ]),
        );
    }

    public function testEveryUnsupportedPeriodIsReportedAndSupportedOnesAreNot(): void
    {
        self::assertSame(
            ['24 month'],
            (new PassageAudit())->unsupportedPeriods('You have fourteen days to withdraw, and we give a 24 month warranty.', [
                self::RETURNS_PASSAGE,
            ]),
        );
    }
}
