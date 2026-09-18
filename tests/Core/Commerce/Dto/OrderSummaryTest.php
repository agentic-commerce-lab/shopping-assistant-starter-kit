<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dto;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDocumentRef;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;

/**
 * The DTO carries what a card shows, and nothing that identifies the shopper.
 *
 * The property list is asserted rather than trusted because this class is the one place where a
 * field about a *person* could enter the pipeline. Everything the gateway returns reaches the wire
 * through {@see \Swag\AssistantStarterKit\Controller\OrderPayload}, so a name or an address added
 * here in a hurry would be shipped by the next serialisation without anyone deciding to ship it.
 *
 * The assistant is talking to the shopper. It does not need to be told who they are.
 */
final class OrderSummaryTest extends TestCase
{
    public function testCarriesOnlyOrderFacingFields(): void
    {
        $properties = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(OrderSummary::class))->getProperties(),
        );

        sort($properties);

        self::assertSame(
            ['currency', 'documents', 'id', 'itemCount', 'orderNumber', 'orderedAt', 'stateLabel', 'total'],
            $properties,
        );
    }

    public function testHoldsItsDocuments(): void
    {
        $summary = new OrderSummary(
            id: 'o1',
            orderNumber: '10023',
            orderedAt: new \DateTimeImmutable('2026-09-12'),
            stateLabel: 'Shipped',
            total: 118.44,
            currency: 'EUR',
            itemCount: 3,
            documents: [new OrderDocumentRef('Invoice', '/account/order/document/abc/def', 'pdf')],
        );

        self::assertSame('10023', $summary->orderNumber);
        self::assertSame('Invoice', $summary->documents[0]->title);
        self::assertSame('/account/order/document/abc/def', $summary->documents[0]->url);
    }
}
