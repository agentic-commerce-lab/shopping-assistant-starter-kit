<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;
use Swag\AssistantStarterKit\Core\Grounding\OrderRenderer;

/**
 * What {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer} is for products, this is for
 * orders: the request-scoped authority on what the turn actually fetched.
 *
 * The replacement rule is the interesting one and it is inherited deliberately — see the test at the
 * bottom.
 */
final class OrderRendererTest extends TestCase
{
    public function testHoldsWhatTheTurnRetrieved(): void
    {
        $renderer = new OrderRenderer();
        $renderer->registerRetrieved([self::summary('10023')]);

        self::assertCount(1, $renderer->retrievedOrders());
        self::assertSame('10023', $renderer->retrievedOrders()[0]->orderNumber);
    }

    /**
     * Replaced, never merged — including with an empty array, which resets it.
     *
     * The same rule and the same reason as `FactRenderer::registerRetrieved()`: if the last call
     * returned nothing, the honest card set is nothing. Merging would leave the previous turn's
     * orders on screen beside a reply that found none, which is the one thing a shopper reading
     * their own order history must never see.
     */
    public function testRegistrationReplacesRatherThanMerges(): void
    {
        $renderer = new OrderRenderer();
        $renderer->registerRetrieved([self::summary('10023')]);
        $renderer->registerRetrieved([]);

        self::assertSame([], $renderer->retrievedOrders());
    }

    private static function summary(string $number): OrderSummary
    {
        return new OrderSummary(
            id: 'id-' . $number,
            orderNumber: $number,
            orderedAt: new \DateTimeImmutable('2026-09-12'),
            stateLabel: 'Shipped',
            total: 10.0,
            currency: 'EUR',
            itemCount: 1,
            documents: [],
        );
    }
}
