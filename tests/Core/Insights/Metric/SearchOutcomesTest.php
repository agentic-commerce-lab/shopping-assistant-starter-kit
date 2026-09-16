<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights\Metric;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\Metric\SearchOutcomes;

final class SearchOutcomesTest extends TestCase
{
    public function testItCountsSearchesThatFoundNothingAndKeepsTheirTerms(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'retrieve', 'payload' => ['query' => 'harley belag', 'total' => 0]],
            ['seq' => 2, 'stage' => 'retrieve', 'payload' => ['query' => 'sattel', 'total' => 4]],
        ])]);

        self::assertSame(1, $outcomes->searchesEmpty);
        self::assertSame(['harley belag'], $outcomes->emptyTerms);
    }

    public function testItCountsSearchesThatHitTheMatchCap(): void
    {
        // `many: true` is what SearchResultCounts sets at the cap of 100. Past that point the count
        // itself is a bound, so the flag is the only honest signal that the shopper was handed a
        // category rather than an answer.
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'retrieve', 'payload' => ['query' => 'kette', 'total' => 3, 'many' => true]],
        ])]);

        self::assertSame(1, $outcomes->searchesOverCap);
        self::assertSame(['kette'], $outcomes->overCapTerms);
    }

    public function testATermIsListedOnceHoweverOftenItWasSearched(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'retrieve', 'payload' => ['query' => 'harley', 'total' => 0]],
            ['seq' => 2, 'stage' => 'retrieve', 'payload' => ['query' => 'harley', 'total' => 0]],
        ])]);

        self::assertSame(2, $outcomes->searchesEmpty);
        self::assertSame(['harley'], $outcomes->emptyTerms);
    }

    public function testTheTermListIsCappedSoACountsTableDoesNotBecomeATextTable(): void
    {
        $events = [];

        for ($i = 0; $i < (SearchOutcomes::MAX_TERMS + 10); ++$i) {
            $events[] = ['seq' => $i, 'stage' => 'retrieve', 'payload' => ['query' => 'term' . $i, 'total' => 0]];
        }

        $outcomes = SearchOutcomes::of([self::trace($events)]);

        self::assertCount(SearchOutcomes::MAX_TERMS, $outcomes->emptyTerms);
        self::assertSame(SearchOutcomes::MAX_TERMS + 10, $outcomes->searchesEmpty);
    }

    public function testASearchWithNoQueryRecordedIsCountedButContributesNoTerm(): void
    {
        // A count without a term is still a count. An empty string in the term list would render in
        // the administration as a blank row a merchant cannot act on.
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'retrieve', 'payload' => ['total' => 0]],
        ])]);

        self::assertSame(1, $outcomes->searchesEmpty);
        self::assertSame([], $outcomes->emptyTerms);
    }

    /** @param list<array{seq:int,stage:string,payload:array<string,mixed>}> $events */
    private static function trace(array $events): ConversationTrace
    {
        return new ConversationTrace('c1', new \DateTimeImmutable('2026-09-16 01:00:00'), $events, []);
    }
}
