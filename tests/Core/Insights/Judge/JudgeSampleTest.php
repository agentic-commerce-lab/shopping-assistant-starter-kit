<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights\Judge;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeSample;

final class JudgeSampleTest extends TestCase
{
    public function testTheSameSeedDrawsTheSameConversations(): void
    {
        // A sample nobody can redraw is a finding nobody can check, which is why the seed is stored
        // on the run row.
        $traces = self::traces(20);

        self::assertSame(
            self::ids(JudgeSample::draw($traces, 25, 'seed-a')),
            self::ids(JudgeSample::draw($traces, 25, 'seed-a')),
        );
    }

    public function testADifferentSeedDrawsADifferentSample(): void
    {
        $traces = self::traces(50);

        self::assertNotSame(
            self::ids(JudgeSample::draw($traces, 10, 'seed-a')),
            self::ids(JudgeSample::draw($traces, 10, 'seed-b')),
        );
    }

    public function testZeroPercentDrawsNothing(): void
    {
        self::assertSame([], JudgeSample::draw(self::traces(10), 0, 'seed'));
    }

    public function testAPercentageThatRoundsToNothingStillDrawsOneConversation(): void
    {
        // 1 % of 40 conversations is 0.4. Rounding that to zero would make a configured judge
        // silently never run, which reads in the dashboard as "no problems found".
        self::assertCount(1, JudgeSample::draw(self::traces(40), 1, 'seed'));
    }

    public function testAHundredPercentDrawsEverythingInTheOriginalOrder(): void
    {
        $traces = self::traces(5);

        self::assertSame(self::ids($traces), self::ids(JudgeSample::draw($traces, 100, 'seed')));
    }

    public function testAnEmptyNightDrawsNothingRatherThanFailing(): void
    {
        self::assertSame([], JudgeSample::draw([], 100, 'seed'));
    }

    /** @param list<ConversationTrace> $traces */
    private static function ids(array $traces): array
    {
        return array_map(static fn(ConversationTrace $t): string => $t->id, $traces);
    }

    /** @return list<ConversationTrace> */
    private static function traces(int $count): array
    {
        $traces = [];

        for ($i = 0; $i < $count; ++$i) {
            $traces[] = new ConversationTrace('c' . $i, new \DateTimeImmutable(), [], []);
        }

        return $traces;
    }
}
