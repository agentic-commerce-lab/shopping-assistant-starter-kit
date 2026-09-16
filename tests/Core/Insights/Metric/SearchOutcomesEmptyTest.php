<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights\Metric;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\Metric\SearchOutcomes;

/**
 * Every event shape here is copied from a real trace export, not invented.
 *
 * The first version of this test invented the payload keys along with the class, so both agreed
 * with each other and neither agreed with the shop: `retrieve` was asserted to carry `query` and
 * `total`, and it carries `hits`, `categoryId`, `retainedIds` and `candidateLimit`. The metric read
 * zero for every real night until a dry run over 131 archived conversations showed it.
 *
 * This half covers "the search handed the model nothing". The cap and the term carry-forward live
 * in {@see SearchOutcomesTermsTest}, split because one class of twelve methods trips
 * `too-many-methods` at `error` level.
 */
final class SearchOutcomesEmptyTest extends TestCase
{
    public function testItCountsASearchThatHandedTheModelNothingAndKeepsItsWords(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'understand', 'payload' => ['term' => 'headphones', 'brand' => null]],
            ['seq' => 2, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'headphones', 'sort' => null]],
            ['seq' => 3, 'stage' => 'retrieve', 'payload' => ['hits' => 0, 'candidateLimit' => 50]],
            [
                'seq' => 4,
                'stage' => 'tool.result',
                'payload' => ['name' => 'search_products', 'total' => 0, 'matched' => 0],
            ],
        ])]);

        self::assertSame(1, $outcomes->searchesEmpty);
        self::assertSame(['headphones'], $outcomes->emptyTerms);
    }

    public function testASearchThatFoundSomethingIsNotCounted(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'sattel']],
            [
                'seq' => 2,
                'stage' => 'tool.result',
                'payload' => ['name' => 'search_products', 'total' => 4, 'matched' => 4],
            ],
        ])]);

        self::assertSame(0, $outcomes->searchesEmpty);
        self::assertSame([], $outcomes->emptyTerms);
    }

    public function testItReadsTheCountFromTheToolResultRatherThanFromRetrieve(): void
    {
        // `retrieve.hits` is the gateway's raw candidate count. A search that found candidates and
        // then filtered them all away still handed the model nothing, and what the model saw is
        // what the shopper saw.
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'rower']],
            ['seq' => 2, 'stage' => 'retrieve', 'payload' => ['hits' => 7, 'retainedIds' => []]],
            ['seq' => 3, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 0]],
        ])]);

        self::assertSame(1, $outcomes->searchesEmpty);
    }

    public function testASearchWithNoTermRecordedIsCountedButContributesNoBlankRow(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
        ])]);

        self::assertSame(1, $outcomes->searchesEmpty);
        self::assertSame([], $outcomes->emptyTerms);
    }

    public function testAToolResultFromAnotherToolIsIgnored(): void
    {
        // `browse_categories` carries `departments` and `note`, no `total`. Nothing here should
        // read a missing key as a zero.
        $outcomes = SearchOutcomes::of([self::trace([
            [
                'seq' => 1,
                'stage' => 'tool.result',
                'payload' => ['name' => 'browse_categories', 'keys' => 'departments,note'],
            ],
        ])]);

        self::assertSame(0, $outcomes->searchesEmpty);
    }

    /** @param list<array{seq:int,stage:string,payload:array<string,mixed>}> $events */
    private static function trace(array $events): ConversationTrace
    {
        return new ConversationTrace('c1', new \DateTimeImmutable('2026-09-16 01:00:00'), $events, []);
    }
}
