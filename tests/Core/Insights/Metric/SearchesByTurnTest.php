<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights\Metric;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\Metric\SearchesByTurn;

/**
 * Both event shapes here are copied verbatim from one real conversation, seq numbers included,
 * because the shapes are the whole argument: three earlier versions of this metric were written
 * against invented fixtures with one search per result, and none of them could fail the way the
 * real traces do.
 */
final class SearchesByTurnTest extends TestCase
{
    public function testABatchOfSearchesSharingOneResultIsOneSearchUnderTheFirstTerm(): void
    {
        // Seq 9-26 of a real conversation: the model built three queries and the trace carries one
        // `tool.result` for all of them. The first is the one built from the shopper's sentence.
        $turns = SearchesByTurn::in(self::trace([
            ['seq' => 9, 'stage' => 'understand', 'payload' => ['term' => 'Reifen 28 Zoll']],
            ['seq' => 10, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Reifen 28 Zoll']],
            ['seq' => 13, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Reifen 28']],
            ['seq' => 18, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'tire 28']],
            ['seq' => 22, 'stage' => 'tool.result', 'payload' => ['total' => 5, 'matched' => 100]],
            ['seq' => 26, 'stage' => 'turn.end', 'payload' => ['outcome' => 'product_shown']],
        ]));

        self::assertCount(1, $turns);
        self::assertSame([['term' => 'Reifen 28 Zoll', 'total' => 5, 'matched' => 100]], $turns[0] ?? null);
    }

    public function testSearchesWithTheirOwnResultsArePairedInOrder(): void
    {
        // Seq 36-65 of the same conversation. Three queries, three results, and the first one
        // missed while the two after it did not — which is the case that made a turn the shopper
        // got products from report as a catalogue gap.
        $turns = SearchesByTurn::in(self::trace([
            ['seq' => 37, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Reifen 28']],
            ['seq' => 43, 'stage' => 'tool.result', 'payload' => ['total' => 0, 'matched' => 0]],
            ['seq' => 47, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Reifen']],
            ['seq' => 51, 'stage' => 'tool.result', 'payload' => ['total' => 5, 'matched' => 100]],
            ['seq' => 55, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Reifen']],
            ['seq' => 60, 'stage' => 'tool.result', 'payload' => ['total' => 5, 'matched' => 100]],
            ['seq' => 65, 'stage' => 'turn.end', 'payload' => ['outcome' => 'product_shown']],
        ]));

        self::assertCount(1, $turns);
        self::assertCount(3, $turns[0] ?? []);
        self::assertSame('Reifen 28', $turns[0][0]['term'] ?? null);
        self::assertSame(0, $turns[0][0]['total'] ?? null);
        self::assertSame('Reifen', $turns[0][1]['term'] ?? null);
        self::assertSame(100, $turns[0][1]['matched'] ?? null);
    }

    public function testEachTurnIsItsOwnGroup(): void
    {
        $turns = SearchesByTurn::in(self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Kette']],
            ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['total' => 3]],
            ['seq' => 3, 'stage' => 'turn.end', 'payload' => ['outcome' => 'product_shown']],
            ['seq' => 4, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Schlauch']],
            ['seq' => 5, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
            ['seq' => 6, 'stage' => 'turn.end', 'payload' => ['outcome' => 'no_result']],
        ]));

        self::assertCount(2, $turns);
        self::assertSame('Kette', $turns[0][0]['term'] ?? null);
        self::assertSame('Schlauch', $turns[1][0]['term'] ?? null);
    }

    public function testATurnWithNoSearchAtAllProducesNoGroup(): void
    {
        // An escalation or a shop-information turn searches nothing. An empty group would divide
        // into every ratio drawn from these numbers.
        $turns = SearchesByTurn::in(self::trace([
            ['seq' => 1, 'stage' => 'escalate', 'payload' => ['destination' => '']],
            ['seq' => 2, 'stage' => 'turn.end', 'payload' => ['outcome' => 'escalated']],
        ]));

        self::assertSame([], $turns);
    }

    public function testAToolResultWithoutATotalIsNotASearch(): void
    {
        // `browse_categories` carries `departments` and `note`. It must not consume a pending term
        // or appear as a search with a zero total.
        $turns = SearchesByTurn::in(self::trace([
            ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'Bremse']],
            [
                'seq' => 2,
                'stage' => 'tool.result',
                'payload' => ['name' => 'browse_categories', 'keys' => 'departments'],
            ],
            ['seq' => 3, 'stage' => 'tool.result', 'payload' => ['total' => 0]],
        ]));

        self::assertCount(1, $turns);
        self::assertSame([['term' => 'Bremse', 'total' => 0, 'matched' => 0]], $turns[0] ?? null);
    }

    /** @param list<array{seq:int,stage:string,payload:array<string,mixed>}> $events */
    private static function trace(array $events): ConversationTrace
    {
        return new ConversationTrace('c1', new \DateTimeImmutable('2026-09-17 09:32:00'), $events, []);
    }
}
