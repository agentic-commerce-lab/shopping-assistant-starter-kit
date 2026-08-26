<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\PassageAudit;

/**
 * Which two ways of writing a period count as the same one.
 *
 * This exists because of a false positive found by manufacturing the measurement instead of waiting
 * for it: a passage granting *fourteen days* and a reply saying *two weeks* are the same period, and
 * the audit called the second an invented deadline. Models produce that paraphrase constantly — and
 * had the check been wired to escalate, it would have handed a shopper to a human over a right answer.
 *
 * The non-equivalences matter as much as the equivalences, which is why they are asserted here beside
 * them rather than left implicit.
 *
 * Split from {@see PassageAuditTest} because Mago bounds methods per class.
 */
final class PeriodEquivalenceTest extends TestCase
{
    private const RETURNS_PASSAGE =
        'You have the right to withdraw from this contract within fourteen days without giving any '
            . 'reason. We will reimburse all payments no later than fourteen days from the day on which '
            . 'we are informed of your decision.';

    /**
     * The false positive that would have made this check ignorable.
     *
     * Found by manufacturing the measurement rather than waiting for it: a passage granting *fourteen
     * days* and a reply saying *two weeks* are the same period, and the first version of this audit
     * reported the second as an invented deadline. Models produce that paraphrase constantly, so the
     * check would have fired on correct behaviour — and had it been wired to escalate, it would have
     * handed a shopper to a human over a right answer.
     */
    public function testAnEquivalentUnitIsNotAnInvention(): void
    {
        $audit = new PassageAudit();

        self::assertSame(
            [],
            $audit->unsupportedPeriods('You have two weeks to send the goods back.', [self::RETURNS_PASSAGE]),
        );

        self::assertSame([], $audit->unsupportedPeriods('A 2 week window applies.', [self::RETURNS_PASSAGE]));
    }

    /** Years and months are exact too, so a two-year warranty may be stated as twenty-four months. */
    public function testYearsAndMonthsAreInterchangeable(): void
    {
        self::assertSame(
            [],
            (new PassageAudit())->unsupportedPeriods('The warranty runs for 24 months.', [
                'We grant a warranty of two years on frames.',
            ]),
        );
    }

    /**
     * Months are deliberately NOT folded into days.
     *
     * "One month" runs to the same date in the following month, which is what a contract means by it.
     * Treating it as thirty days would let a reply quietly turn a one-month payment window into a
     * thirty-day one — a different date, stated as if the document said it.
     */
    public function testAMonthIsNotThirtyDays(): void
    {
        self::assertSame(
            ['30 day'],
            (new PassageAudit())->unsupportedPeriods('Payment is due within 30 days.', [
                'Payment on invoice is due within one month.',
            ]),
        );
    }
}
