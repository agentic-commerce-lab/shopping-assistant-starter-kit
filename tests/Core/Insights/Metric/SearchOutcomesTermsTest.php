<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights\Metric;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\Metric\SearchOutcomes;

/**
 * The other half of {@see SearchOutcomesEmptyTest}: the cap, and where a search's words come from.
 *
 * Every event shape is copied from a real trace export. The words are the part most likely to be
 * got wrong quietly — attributing a miss to the assistant's own retry sends a merchant to stock the
 * wrong product, and nothing in the output would say so.
 */
final class SearchOutcomesTermsTest extends TestCase
{
    public function testATurnThatFoundMoreThanTheShopWillCountIsReported(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Kette']],
            [
                'seq' => 2,
                'stage' => 'tool.result',
                'payload' => ['name' => 'search_products', 'total' => 8, 'matched' => 480],
            ],
            ['seq' => 3, 'stage' => 'turn.end', 'payload' => ['outcome' => 'product_shown']],
        ])]);

        self::assertSame(1, $outcomes->turnsOverCap);
        self::assertSame(['Kette'], $outcomes->overCapTerms);
    }

    public function testTheCapIsReadOffMatchedRatherThanOffTheManyFlag(): void
    {
        // Reverses an earlier decision, on measured grounds. Over 257 real `tool.result` events,
        // `many: true` appeared 0 times while 109 carried `matched >= 100` — the flag only appears
        // on a path that computes an exact count, and that path did not run once. Reading the flag
        // made this metric structurally always zero, which reads as good news.
        $withoutFlag = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Kettenöl']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['total' => 8, 'matched' => SearchOutcomes::MATCH_CAP]],
        ])]);

        self::assertSame(1, $withoutFlag->turnsOverCap);
    }

    public function testJustUnderTheCapIsNotReported(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Kettenöl']],
            [
                'seq' => 2,
                'stage' => 'tool.result',
                'payload' => ['total' => 8, 'matched' => SearchOutcomes::MATCH_CAP - 1],
            ],
        ])]);

        self::assertSame(0, $outcomes->turnsOverCap);
    }

    public function testAResultWithNoMatchedCountFallsBackToItsTotal(): void
    {
        // Older traces carry `total` without `matched`. Treating a missing `matched` as zero would
        // silently exempt every one of them from the cap half.
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Reifen']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
        ])]);

        self::assertSame(1, $outcomes->turnsFoundNothing);
        self::assertSame(0, $outcomes->turnsOverCap);
    }

    public function testTheTermCarriesForwardFromUnderstandWhenNoQueryWasBuilt(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'understand', 'payload' => ['term' => 'Schloss', 'source' => 'tool_arguments']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
        ])]);

        self::assertSame(['Schloss'], $outcomes->emptyTerms);
    }

    public function testTheFirstTermOfATurnWinsOverTheAssistantsRetry(): void
    {
        // "Ich brauche einen Fahrradhelm für Schotterwege" produced `Helm`, then `Gravel`, then
        // `Helmet`, then `helmet`. Only the first was the shopper's.
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Helm']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
            ['seq' => 3, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Helmet']],
            ['seq' => 4, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
            ['seq' => 5, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'helmet']],
            ['seq' => 6, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
            ['seq' => 7, 'stage' => 'turn.end', 'payload' => ['outcome' => 'no_result']],
        ])]);

        self::assertSame(1, $outcomes->turnsFoundNothing);
        self::assertSame(['Helm'], $outcomes->emptyTerms);
    }

    public function testAParallelSearchBatchIsAttributedToItsFirstTerm(): void
    {
        // The exact event sequence of a real conversation, seq numbers included. The model issues
        // two searches in one batch and the trace carries ONE `tool.result` for both, so carrying
        // the latest term forward paired the result with `Gravel` — the assistant's second guess.
        // The shopper wrote "Ich brauche einen Fahrradhelm für Schotterwege".
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 9, 'stage' => 'understand', 'payload' => ['term' => 'Helm']],
            ['seq' => 10, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Helm']],
            ['seq' => 12, 'stage' => 'understand', 'payload' => ['term' => 'Gravel']],
            ['seq' => 13, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Gravel']],
            ['seq' => 19, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 0]],
            ['seq' => 22, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Helm']],
            ['seq' => 26, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Helmet']],
            ['seq' => 32, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 0]],
            ['seq' => 36, 'stage' => 'turn.end', 'payload' => ['outcome' => 'no_result']],
        ])]);

        self::assertSame(1, $outcomes->turnsFoundNothing);
        self::assertSame(['Helm'], $outcomes->emptyTerms);
    }

    public function testATermIsListedOnceHoweverManyTurnsAskedForIt(): void
    {
        $outcomes = SearchOutcomes::of([self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Schloss']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
            ['seq' => 3, 'stage' => 'turn.end', 'payload' => ['outcome' => 'no_result']],
            ['seq' => 4, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Schloss']],
            ['seq' => 5, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
            ['seq' => 6, 'stage' => 'turn.end', 'payload' => ['outcome' => 'no_result']],
        ])]);

        self::assertSame(2, $outcomes->turnsFoundNothing);
        self::assertSame(['Schloss'], $outcomes->emptyTerms);
    }

    public function testTheTermListIsCappedSoACountsTableDoesNotBecomeATextTable(): void
    {
        $events = [];

        for ($i = 0; $i < (SearchOutcomes::MAX_TERMS + 10); ++$i) {
            $events[] = ['seq' => $i * 3, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'wort' . $i]];
            $events[] = ['seq' => ($i * 3) + 1, 'stage' => 'tool.result', 'payload' => ['total' => 0]];
            $events[] = ['seq' => ($i * 3) + 2, 'stage' => 'turn.end', 'payload' => ['outcome' => 'no_result']];
        }

        $outcomes = SearchOutcomes::of([self::trace($events)]);

        self::assertCount(SearchOutcomes::MAX_TERMS, $outcomes->emptyTerms);
        self::assertSame(SearchOutcomes::MAX_TERMS + 10, $outcomes->turnsFoundNothing);
    }

    /** @param list<array{seq:int,stage:string,payload:array<string,mixed>}> $events */
    private static function trace(array $events): ConversationTrace
    {
        return new ConversationTrace('c1', new \DateTimeImmutable('2026-09-17 08:00:00'), $events, []);
    }
}
