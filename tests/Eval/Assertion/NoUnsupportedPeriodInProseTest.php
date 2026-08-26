<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\NoUnsupportedPeriodInProse;

/**
 * That the assertion is wired to the trace, and can actually fail.
 *
 * ## Why this test exists rather than trusting the journeys
 *
 * Because this session produced two assertions that passed without being able to fail, and both were
 * only found by looking. `retrieved_shop_info` caught the first; the second was
 * `no_absence_claim_in_prose` firing English regexes at German prose. A green journey is not evidence
 * that a control works — it is evidence that nothing tripped it, and those are only the same thing
 * once you have seen the control fail on purpose.
 *
 * {@see \Swag\AssistantStarterKit\Tests\Core\Grounding\PassageAuditTest} covers the detection itself.
 * What is checked here is the join: that the passages are read out of the trace event the tool writes,
 * with the key the tool actually uses.
 */
final class NoUnsupportedPeriodInProseTest extends TestCase
{
    private const PASSAGE = 'You have the right to withdraw within fourteen days without giving any reason.';

    public function testItFailsOnAPeriodNoTracedPassageSupports(): void
    {
        $result = (new NoUnsupportedPeriodInProse())->evaluate(
            self::turn('We give a 24 month warranty on frames.'),
            self::traceWith([self::PASSAGE]),
            [],
        );

        self::assertFalse($result->passed);
        self::assertStringContainsString('24 month', $result->detail);
    }

    public function testItPassesWhenTheTracedPassageSupportsThePeriod(): void
    {
        $result = (new NoUnsupportedPeriodInProse())->evaluate(
            // Paraphrased into digits, as a model does — this must not read as an invention.
            self::turn('You have 14 days to send the goods back.'),
            self::traceWith([self::PASSAGE]),
            [],
        );

        self::assertTrue($result->passed, $result->detail);
    }

    /**
     * The wiring failure that would be invisible: if the tool's payload key ever changes, this
     * assertion sees no passages, calls every correct answer an invention, and the journeys go red for
     * a reason that has nothing to do with the model.
     */
    public function testWithNoRetrievalEventThereIsNothingToSupportAStatedPeriod(): void
    {
        $result = (new NoUnsupportedPeriodInProse())->evaluate(
            self::turn('You have 14 days to send the goods back.'),
            new TraceRecorder(),
            [],
        );

        self::assertFalse($result->passed);
    }

    public function testAReplyStatingNoPeriodPasses(): void
    {
        $result = (new NoUnsupportedPeriodInProse())->evaluate(
            self::turn('I cannot find that in the shop information.'),
            self::traceWith([self::PASSAGE]),
            [],
        );

        self::assertTrue($result->passed, $result->detail);
    }

    /** A safety assertion, because what it detects is a false statement about the merchant. */
    public function testItIsASafetyAssertion(): void
    {
        self::assertTrue((new NoUnsupportedPeriodInProse())->isSafety());
    }

    private static function turn(string $prose): AssistantTurn
    {
        return new AssistantTurn($prose, [], 'no_result');
    }

    /**
     * @param list<string> $passages
     */
    private static function traceWith(array $passages): TraceRecorder
    {
        $trace = new TraceRecorder();
        // Written with the same shape and key SearchShopInfoTool records, which is the contract under
        // test here.
        $trace->record('retrieve.shopinfo', [
            'question' => 'irrelevant to this assertion',
            'threshold' => 0.4,
            'scores' => [0.61],
            'accepted' => \count($passages),
            'ms' => 1,
            'passages' => $passages,
        ]);

        return $trace;
    }
}
