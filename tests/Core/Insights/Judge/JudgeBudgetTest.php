<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights\Judge;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeBudget;

final class JudgeBudgetTest extends TestCase
{
    public function testAnEasyNightPassesThroughUntouched(): void
    {
        $traces = [self::trace('c1', 100), self::trace('c2', 100)];

        self::assertSame($traces, JudgeBudget::fit($traces, 10_000)->traces);
    }

    public function testItDropsWholeConversationsRatherThanTruncatingOne(): void
    {
        // The whole point. A truncated conversation still satisfies the quote check — `from()` is
        // handed the complete trace either way — so the judge would be reporting on words it never
        // read while the guard kept passing. Dropping whole conversations keeps "everything the
        // judge saw, it saw in full" true, which is what the guard's premise rests on.
        $fitted = JudgeBudget::fit([self::trace('c1', 600), self::trace('c2', 600)], 1_000);

        self::assertCount(1, $fitted->traces);
        $kept = $fitted->traces[0] ?? null;
        self::assertNotNull($kept);
        self::assertSame('c1', $kept->id);
        self::assertSame(1, $fitted->dropped);
    }

    public function testASingleConversationOverTheWholeBudgetIsDroppedAndCounted(): void
    {
        // Not kept-and-truncated, and not an exception either: one enormous conversation must not
        // cost a merchant the whole night's findings.
        $fitted = JudgeBudget::fit([self::trace('c1', 5_000), self::trace('c2', 100)], 1_000);

        self::assertCount(1, $fitted->traces);
        $kept = $fitted->traces[0] ?? null;
        self::assertNotNull($kept);
        self::assertSame('c2', $kept->id);
        self::assertSame(1, $fitted->dropped);
    }

    public function testNothingDroppedIsReportedAsZeroRatherThanNull(): void
    {
        self::assertSame(0, JudgeBudget::fit([self::trace('c1', 10)], 10_000)->dropped);
    }

    public function testAnEmptyNightFitsTrivially(): void
    {
        $fitted = JudgeBudget::fit([], 1_000);

        self::assertSame([], $fitted->traces);
        self::assertSame(0, $fitted->dropped);
    }

    private static function trace(string $id, int $proseChars): ConversationTrace
    {
        return new ConversationTrace(
            $id,
            new \DateTimeImmutable(),
            [],
            [
                ['role' => 'user', 'prose' => str_repeat('a', max(0, $proseChars))],
            ],
        );
    }
}
