<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dto;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDocumentRef;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderLine;

/**
 * The property lists are asserted for {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary}'s
 * reason: these are the classes a field about a *person* would enter through.
 *
 * An order detail is the richest thing this feature reads — it is one step from the shipping address
 * and the payment method, both of which Shopware has already loaded on the entity it is mapped from.
 * Nothing stops someone adding them except a test that says what belongs here.
 */
final class OrderDetailTest extends TestCase
{
    public function testTheDetailCarriesOnlyOrderFacingFields(): void
    {
        self::assertSame(
            ['currency', 'documents', 'lines', 'orderNumber', 'orderedAt', 'stateLabel', 'total'],
            self::propertiesOf(OrderDetail::class),
        );
    }

    public function testALineCarriesOnlyWhatTheRowShows(): void
    {
        self::assertSame(['lineTotal', 'name', 'quantity', 'unitPrice'], self::propertiesOf(OrderLine::class));
    }

    public function testHoldsItsLinesAndDocuments(): void
    {
        $detail = new OrderDetail(
            orderNumber: '10023',
            orderedAt: new \DateTimeImmutable('2026-09-12'),
            stateLabel: 'Shipped',
            total: 118.44,
            currency: 'EUR',
            lines: [new OrderLine('Chain Oil 100ml', 3, 12.90, 38.70)],
            documents: [new OrderDocumentRef('Invoice', '/account/order/document/abc/def', 'pdf')],
        );

        self::assertSame(
            [['Chain Oil 100ml', 3]],
            array_map(static fn(OrderLine $line): array => [$line->name, $line->quantity], $detail->lines),
        );
        self::assertSame(
            ['Invoice'],
            array_map(static fn(OrderDocumentRef $doc): string => $doc->title, $detail->documents),
        );
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function propertiesOf(string $class): array
    {
        $names = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass($class))->getProperties(),
        );

        sort($names);

        return $names;
    }
}
