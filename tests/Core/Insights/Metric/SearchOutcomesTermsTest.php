<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights\Metric;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\Metric\SearchOutcomes;

/**
 * The other half of {@see SearchOutcomesEmptyTest}: the cap the shop will not put a figure behind,
 * and how a search's words travel from the stage that records them to the stage that records its
 * outcome.
 *
 * Every event shape is copied from a real trace export. The term carry-forward is the part most
 * likely to be got wrong quietly: attributing a miss to the previous search's words is worse than
 * reporting no words at all, because it sends a merchant to the wrong product.
 */
final class SearchOutcomesTermsTest extends TestCase
{
    public function testItCountsASearchTheShopWouldOnlyCallMany(): void
    {
        // `many: true` is SearchResultCounts' own statement that it will put no figure behind the
        // count. Nothing here infers the cap from `matched`, because that would be this class
        // inventing a threshold the shop never published.
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'kette']],
            [
                'seq' => 2,
                'stage' => 'tool.result',
                'payload' => ['name' => 'search_products', 'total' => 5, 'matched' => 100, 'many' => true],
            ],
        ])]);

        self::assertSame(1, $outcomes->searchesOverCap);
        self::assertSame(['kette'], $outcomes->overCapTerms);
    }

    public function testAHighMatchedCountWithoutTheFlagIsNotTreatedAsCapped(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'kette']],
            [
                'seq' => 2,
                'stage' => 'tool.result',
                'payload' => ['name' => 'search_products', 'total' => 5, 'matched' => 480],
            ],
        ])]);

        self::assertSame(0, $outcomes->searchesOverCap);
    }

    public function testTheTermCarriesForwardFromUnderstandWhenNoQueryWasBuilt(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'understand', 'payload' => ['term' => 'cake']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 0]],
        ])]);

        self::assertSame(['cake'], $outcomes->emptyTerms);
    }

    public function testASecondSearchInTheSameTurnGetsItsOwnTerm(): void
    {
        // The model searches twice in a turn regularly. Carrying the first term onto the second
        // result would attribute a miss to the wrong words, which is worse than no term at all.
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'sattel']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 4]],
            ['seq' => 3, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'rower']],
            ['seq' => 4, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 0]],
        ])]);

        self::assertSame(['rower'], $outcomes->emptyTerms);
    }

    public function testATermIsListedOnceHoweverOftenItWasSearched(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'harley']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
            ['seq' => 3, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'harley']],
            ['seq' => 4, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
        ])]);

        self::assertSame(2, $outcomes->searchesEmpty);
        self::assertSame(['harley'], $outcomes->emptyTerms);
    }

    public function testTheTermListIsCappedSoACountsTableDoesNotBecomeATextTable(): void
    {
        $events = [];

        for ($i = 0; $i < (SearchOutcomes::MAX_TERMS + 10); ++$i) {
            $events[] = ['seq' => $i * 2, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'term' . $i]];
            $events[] = ['seq' => ($i * 2) + 1, 'stage' => 'tool.result', 'payload' => ['total' => 0]];
        }

        $outcomes = SearchOutcomes::of([self::trace($events)]);

        self::assertCount(SearchOutcomes::MAX_TERMS, $outcomes->emptyTerms);
        self::assertSame(SearchOutcomes::MAX_TERMS + 10, $outcomes->searchesEmpty);
    }

    /** @param list<array{seq:int,stage:string,payload:array<string,mixed>}> $events */
    private static function trace(array $events): ConversationTrace
    {
        return new ConversationTrace('c1', new \DateTimeImmutable('2026-09-16 01:00:00'), $events, []);
    }
}
