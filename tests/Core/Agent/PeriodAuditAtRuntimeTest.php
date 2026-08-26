<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\PassageAudit;
use Swag\AssistantStarterKit\Core\ShopInfo\RetrievedPassages;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * That a reply stating a period no retrieved passage supports leaves a record.
 *
 * ## Why this is not covered by the eval assertion
 *
 * `no_unsupported_period_in_prose` runs when someone runs the eval suite. This is about the path a
 * real shopper's reply travels: the audit runs on every turn, and an invented deadline lands in
 * `claims.audit` where the Administration already renders it.
 *
 * The two catch opposite halves of one problem, and the distinction is worth stating because
 * escalation looks like it should already cover this. Handoff fires when the model *knows* it cannot
 * help. This fires when it does not know — it states a deadline confidently and wrongly, which
 * escalation cannot catch by construction, since the model would have to know it was wrong in order
 * to escalate.
 *
 * ## What is asserted here
 *
 * The join, not the detection: {@see \Swag\AssistantStarterKit\Tests\Core\Grounding\PassageAuditTest}
 * covers the rules. What matters here is that the passages written by the tool are the ones the audit
 * reads back — if that trace key ever changes, every correct answer would start reading as invented,
 * and nothing else would notice.
 */
final class PeriodAuditAtRuntimeTest extends TestCase
{
    private const PASSAGE = 'You have the right to withdraw within fourteen days without giving any reason.';

    public function testThePassagesTheToolWroteAreTheOnesTheAuditReadsBack(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve.shopinfo', ['accepted' => 1, 'passages' => [self::PASSAGE]]);

        self::assertSame([self::PASSAGE], RetrievedPassages::from($trace));
    }

    public function testAnInventedPeriodIsFoundAgainstWhatTheToolRetrieved(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve.shopinfo', ['accepted' => 1, 'passages' => [self::PASSAGE]]);

        self::assertSame(
            ['30 day'],
            (new PassageAudit())->unsupportedPeriods(
                'You have 30 days to return the goods.',
                RetrievedPassages::from($trace),
            ),
        );
    }

    /** The paraphrase a model is supposed to produce must not be reported as an invention. */
    public function testAParaphraseOfTheRetrievedPeriodIsClean(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve.shopinfo', ['accepted' => 1, 'passages' => [self::PASSAGE]]);

        self::assertSame(
            [],
            (new PassageAudit())->unsupportedPeriods('You have 14 days to withdraw.', RetrievedPassages::from($trace)),
        );
    }

    /** Every retrieval in the run, because one recorder spans a whole conversation (ruling R84). */
    public function testPassagesFromEveryTurnOfTheConversationCount(): void
    {
        $trace = new TraceRecorder();
        $trace->record('retrieve.shopinfo', ['accepted' => 1, 'passages' => ['Delivery takes three working days.']]);
        $trace->record('retrieve.shopinfo', ['accepted' => 1, 'passages' => [self::PASSAGE]]);

        self::assertSame(
            [],
            (new PassageAudit())->unsupportedPeriods(
                'Delivery takes three working days and you have fourteen days to withdraw.',
                RetrievedPassages::from($trace),
            ),
        );
    }

    /** A turn that retrieved nothing has nothing to support a period the model states. */
    public function testWithNoRetrievalAStatedPeriodIsUnsupported(): void
    {
        self::assertSame(
            ['14 day'],
            (new PassageAudit())->unsupportedPeriods(
                'Under EU law you generally have 14 days to withdraw.',
                RetrievedPassages::from(new TraceRecorder()),
            ),
        );
    }
}
