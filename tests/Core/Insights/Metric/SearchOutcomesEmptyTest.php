<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights\Metric;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\Metric\SearchOutcomes;

/**
 * Every event shape here is copied from a real trace export, not invented.
 *
 * Two versions of this metric were wrong before this one, and both were caught by running it over
 * real conversations rather than by a test:
 *
 * 1. It read `query`, `total` and `many` off the `retrieve` payload. None of those keys is there,
 *    so it reported zero for every real night — and the test agreed with it, because the test
 *    invented the same keys.
 * 2. It counted every search and listed every search term. The assistant retries a failed search in
 *    English, so "Zündkerzen" became "spark plug" and "Fahrradhelm" became "Helmet" — and a
 *    merchant reading *"spark plug found nothing"* would stock spark plugs for a shopper who asked
 *    about a Yamaha in German. The words in the list were the assistant's guesses, not anybody's
 *    question.
 *
 * Hence: **one count per turn, on that turn's FIRST search.** The first search is built from what
 * the shopper wrote; everything after it is the assistant reacting to its own miss.
 *
 * This half covers turns that found nothing. The cap and the term carry-forward live in
 * {@see SearchOutcomesTermsTest}, split because one class of twelve methods trips
 * `too-many-methods` at `error` level.
 */
final class SearchOutcomesEmptyTest extends TestCase
{
    public function testATurnWhoseSearchHandedTheModelNothingIsCountedOnce(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'understand', 'payload' => ['term' => 'Zündkerzen', 'brand' => null]],
            ['seq' => 2, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Zündkerzen', 'sort' => null]],
            ['seq' => 3, 'stage' => 'retrieve', 'payload' => ['hits' => 0, 'candidateLimit' => 50]],
            ['seq' => 4, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 0]],
            ['seq' => 5, 'stage' => 'turn.end', 'payload' => ['outcome' => 'no_result']],
        ])]);

        self::assertSame(1, $outcomes->turnsFoundNothing);
        self::assertSame(['Zündkerzen'], $outcomes->emptyTerms);
    }

    public function testTheAssistantsEnglishRetryIsNotCountedAndNotListed(): void
    {
        // Measured on 2026-09-17. The shopper asked "Habt ihr Zündkerzen für eine Yamaha MT-07?";
        // the assistant searched `Zündkerzen`, found nothing, and retried `spark plug`. Counting
        // both made one shopper look like two, and listing both put a word nobody typed in front of
        // a merchant deciding what to stock.
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Zündkerzen']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 0]],
            ['seq' => 3, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'spark plug']],
            ['seq' => 4, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 0]],
            ['seq' => 5, 'stage' => 'turn.end', 'payload' => ['outcome' => 'no_result']],
        ])]);

        self::assertSame(1, $outcomes->turnsFoundNothing);
        self::assertSame(['Zündkerzen'], $outcomes->emptyTerms);
    }

    public function testATurnWhoseFirstSearchSucceededIsNotCountedEvenIfALaterOneFailed(): void
    {
        // The assistant searches `Bremsbelag`, finds three, and then searches `brake pads` anyway.
        // Real, measured. The shopper got an answer, so the turn is not a miss — and counting the
        // second search would make a successful turn read as a catalogue gap.
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Bremsbelag']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 3]],
            ['seq' => 3, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'brake pads']],
            ['seq' => 4, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 0]],
            ['seq' => 5, 'stage' => 'turn.end', 'payload' => ['outcome' => 'product_shown']],
        ])]);

        self::assertSame(0, $outcomes->turnsFoundNothing);
        self::assertSame([], $outcomes->emptyTerms);
    }

    public function testEachTurnGetsItsOwnCount(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Zündkerzen']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
            ['seq' => 3, 'stage' => 'turn.end', 'payload' => ['outcome' => 'no_result']],
            ['seq' => 4, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Auspuff']],
            ['seq' => 5, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
            ['seq' => 6, 'stage' => 'turn.end', 'payload' => ['outcome' => 'no_result']],
        ])]);

        self::assertSame(2, $outcomes->turnsFoundNothing);
        self::assertSame(['Zündkerzen', 'Auspuff'], $outcomes->emptyTerms);
    }

    public function testATurnThatNeverEndedStillCounts(): void
    {
        // A turn cut short by the tool-call budget writes no `turn.end`. Its search still happened
        // and the shopper still got nothing, so dropping it would hide the worst turns.
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Schloss']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
        ])]);

        self::assertSame(1, $outcomes->turnsFoundNothing);
    }

    public function testAToolResultFromAnotherToolIsIgnored(): void
    {
        // `browse_categories` carries `departments` and `note`, no `total`. A missing key must not
        // read as a zero, and it must not consume the turn's one countable search either.
        $outcomes = SearchOutcomes::of([self::trace([
            [
                'seq' => 1,
                'stage' => 'tool.result',
                'payload' => ['name' => 'browse_categories', 'keys' => 'departments,note'],
            ],
            ['seq' => 2, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Schloss']],
            ['seq' => 3, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 0]],
        ])]);

        self::assertSame(1, $outcomes->turnsFoundNothing);
        self::assertSame(['Schloss'], $outcomes->emptyTerms);
    }

    /** @param list<array{seq:int,stage:string,payload:array<string,mixed>}> $events */
    private static function trace(array $events): ConversationTrace
    {
        return new ConversationTrace('c1', new \DateTimeImmutable('2026-09-17 08:00:00'), $events, []);
    }
}
