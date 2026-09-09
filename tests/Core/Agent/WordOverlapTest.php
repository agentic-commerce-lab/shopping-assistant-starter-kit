<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\WordOverlap;

/**
 * The measure behind {@see \Swag\AssistantStarterKit\Core\Agent\RepeatedAsk}, on its own.
 */
#[CoversClass(WordOverlap::class)]
final class WordOverlapTest extends TestCase
{
    public function testIdenticalTextsOverlapCompletely(): void
    {
        $words = WordOverlap::words('show me helmets in size M');

        self::assertSame(1.0, WordOverlap::of($words, $words));
    }

    /**
     * The pair Jaccard could not see: 0.4 by union, 0.67 by the shorter set. Dividing by the union
     * punishes the second ask for being phrased differently rather than for being about something
     * else.
     */
    public function testItSeesTheSameAskPhrasedDifferently(): void
    {
        $overlap = WordOverlap::of(
            WordOverlap::words('welche sättel gibt es?'),
            WordOverlap::words('was gibt es für sättel?'),
        );

        self::assertGreaterThan(0.5, $overlap);
    }

    public function testUnrelatedTextsDoNotOverlap(): void
    {
        self::assertSame(0.0, WordOverlap::of(
            WordOverlap::words('what is your returns policy'),
            WordOverlap::words('show me helmets'),
        ));
    }

    public function testAnEmptySideOverlapsWithNothingRatherThanDividingByZero(): void
    {
        self::assertSame(0.0, WordOverlap::of([], WordOverlap::words('show me helmets')));
        self::assertSame(0.0, WordOverlap::of([], []));
    }

    public function testNoiseAndShortWordsAreNotTopicWords(): void
    {
        self::assertSame([], WordOverlap::words('do you? it is'));

        // "have" survives, and that is the noise list being deliberately tiny rather than a miss:
        // a real stop-word list per language is a dependency, and a word that appears on both sides
        // of a repeat costs the measure nothing. The threshold does the work.
        self::assertSame(['have', 'helmets'], WordOverlap::words('do you have the helmets'));
    }

    /**
     * Four languages in the corpus, so the split cannot be an English one — and German noise words
     * are on the list beside the English ones for the same reason.
     */
    public function testItSplitsNonEnglishTextOnItsOwnWordBoundaries(): void
    {
        self::assertSame(['welche', 'sättel', 'gibt'], WordOverlap::words('welche sättel gibt es?'));
    }

    public function testAWordIsCountedOnceHoweverOftenItIsRepeated(): void
    {
        self::assertSame(['helmets'], WordOverlap::words('helmets helmets helmets'));
    }
}
