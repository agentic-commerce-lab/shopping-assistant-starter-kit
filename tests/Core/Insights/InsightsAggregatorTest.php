<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\InsightsAggregator;

final class InsightsAggregatorTest extends TestCase
{
    public function testItCountsATurnThatReturnedProductsWithoutHandingOverADescription(): void
    {
        // Measured on 2026-09-16 over a real export: descriptions reached the model on 5 of 148
        // turns. That ratio is computable; "was a detail asked for" is a judgement and is not.
        $trace = new ConversationTrace(
            'c1',
            new \DateTimeImmutable(),
            [
                ['seq' => 1, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products']],
                ['seq' => 2, 'stage' => 'turn.end', 'payload' => ['outcome' => 'product_shown']],
                ['seq' => 3, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products']],
                ['seq' => 4, 'stage' => 'descriptions.given', 'payload' => ['descriptions' => ['A chain.']]],
                ['seq' => 5, 'stage' => 'turn.end', 'payload' => ['outcome' => 'product_shown']],
            ],
            [],
        );

        $metrics = InsightsAggregator::aggregate([$trace]);

        self::assertSame(1, $metrics->descriptions->turnsWithoutDescription);
        self::assertSame(1, $metrics->descriptions->turnsWithDescription);
    }

    public function testATurnThatReturnedNoProductAtAllCountsForNeither(): void
    {
        // A shop-information turn has nothing to describe. Counting it as "no description handed
        // over" would make the coverage figure look worse the more questions the assistant answers
        // well.
        $trace = new ConversationTrace(
            'c1',
            new \DateTimeImmutable(),
            [
                ['seq' => 1, 'stage' => 'retrieve.shopinfo', 'payload' => ['accepted' => 2]],
                ['seq' => 2, 'stage' => 'turn.end', 'payload' => ['outcome' => 'answered']],
            ],
            [],
        );

        $metrics = InsightsAggregator::aggregate([$trace]);

        self::assertSame(0, $metrics->descriptions->turnsWithoutDescription);
        self::assertSame(0, $metrics->descriptions->turnsWithDescription);
    }

    public function testItSumsTheClaimsTheDetectorAlreadyRecorded(): void
    {
        $trace = new ConversationTrace(
            'c1',
            new \DateTimeImmutable(),
            [
                [
                    'seq' => 1,
                    'stage' => 'claims.audit',
                    'payload' => ['unsupportedFactClaims' => ['included bracket', 'rated for gravel']],
                ],
                ['seq' => 2, 'stage' => 'turn.end', 'payload' => ['outcome' => 'product_shown']],
            ],
            [],
        );

        self::assertSame(2, InsightsAggregator::aggregate([$trace])->claims->unsupportedClaims);
    }

    public function testItCountsAbortedTurnsAndEscalationsWithoutADestination(): void
    {
        // Both measured live on 2026-09-16: two of four multi-category missions ended in
        // tool_limit_exceeded because maxToolCallsPerTurn was configured to 5 — a number in the
        // administration, not a defect. This metric is what would have shown that.
        $trace = new ConversationTrace(
            'c1',
            new \DateTimeImmutable(),
            [
                ['seq' => 1, 'stage' => 'turn.end', 'payload' => ['outcome' => 'tool_limit_exceeded']],
                ['seq' => 2, 'stage' => 'escalate', 'payload' => ['destination' => '']],
                ['seq' => 3, 'stage' => 'turn.end', 'payload' => ['outcome' => 'escalated']],
            ],
            [],
        );

        $metrics = InsightsAggregator::aggregate([$trace]);

        self::assertSame(1, $metrics->turns->abortedTurns);
        self::assertSame(1, $metrics->turns->escalations);
        self::assertSame(1, $metrics->turns->escalationsWithoutDestination);
    }

    public function testAnEscalationWithADestinationIsNotCountedAsMisconfigured(): void
    {
        $trace = new ConversationTrace(
            'c1',
            new \DateTimeImmutable(),
            [
                ['seq' => 1, 'stage' => 'escalate', 'payload' => ['destination' => 'https://shop.test/contact']],
            ],
            [],
        );

        self::assertSame(0, InsightsAggregator::aggregate([$trace])->turns->escalationsWithoutDestination);
    }

    public function testItCountsTheCartFunnelPerConversationNotPerTurn(): void
    {
        // A shopper who adds twice is one conversation with a cart, not two. Counting turns would
        // inflate the middle of the funnel and make the drop-off look better than it is — and this
        // is the one metric a managing director reads first, so it must not flatter.
        $withCart = new ConversationTrace(
            'c1',
            new \DateTimeImmutable(),
            [
                ['seq' => 1, 'stage' => 'turn.end', 'payload' => ['outcome' => 'cart_added']],
                ['seq' => 2, 'stage' => 'turn.end', 'payload' => ['outcome' => 'cart_added']],
                ['seq' => 3, 'stage' => 'turn.end', 'payload' => ['outcome' => 'checkout_offered']],
            ],
            [],
        );
        $browsed = new ConversationTrace(
            'c2',
            new \DateTimeImmutable(),
            [
                ['seq' => 1, 'stage' => 'turn.end', 'payload' => ['outcome' => 'product_shown']],
            ],
            [],
        );

        $metrics = InsightsAggregator::aggregate([$withCart, $browsed]);

        self::assertSame(2, $metrics->cart->conversations);
        self::assertSame(1, $metrics->cart->cartAdded);
        self::assertSame(1, $metrics->cart->checkoutOffered);
    }

    public function testCountsAndTermsAreSeparateSoRetentionCanPruneOneOfThem(): void
    {
        // D23 at the value-object level: whatever merges these two would make the prune impossible
        // downstream, and the trend chart would have to die with the conversations.
        //
        // The event shapes are copied from a real export. An earlier version of this test invented
        // them — `retrieve` carrying `query` and `total` — which agreed with a metric that invented
        // the same keys, and with nothing in the shop.
        $trace = new ConversationTrace(
            'c1',
            new \DateTimeImmutable(),
            [
                ['seq' => 1, 'stage' => 'query.build', 'payload' => ['searchTerm' => 'harley', 'sort' => null]],
                ['seq' => 2, 'stage' => 'tool.result', 'payload' => ['name' => 'search_products', 'total' => 0]],
            ],
            [],
        );

        $metrics = InsightsAggregator::aggregate([$trace]);

        self::assertSame(1, $metrics->counts()['searchesEmpty']);
        self::assertSame(['harley'], $metrics->searchTerms()['empty']);
        self::assertArrayNotHasKey('empty', $metrics->counts());
    }

    public function testEveryCountIsAnIntegerSoTheJsonColumnStaysChartable(): void
    {
        $metrics = InsightsAggregator::aggregate([]);

        foreach ($metrics->counts() as $key => $value) {
            self::assertIsInt($value, $key . ' must be an integer');
        }
    }
}
