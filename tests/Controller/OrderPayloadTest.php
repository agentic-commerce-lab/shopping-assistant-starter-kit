<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Controller\OrderPayload;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDocumentRef;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;

/**
 * The wire shape, and what must never appear in it.
 *
 * This is where D3 reaches the HTTP boundary for orders, the way {@see CardPayload} does for
 * products: every value here comes from an {@see OrderSummary} the renderer held, and nothing is
 * parsed out of the model's prose. The key list is asserted rather than sampled, because a field
 * added in a hurry is shipped by the next serialisation without anyone deciding to ship it.
 */
final class OrderPayloadTest extends TestCase
{
    public function testSerialisesOnlyTheFieldsTheCardShows(): void
    {
        $payload = (new OrderPayload())->of([self::order()]);

        self::assertSame(
            [['orderNumber', 'orderedAt', 'state', 'total', 'currency', 'itemCount', 'documents']],
            array_map(static fn(array $row): array => array_keys($row), $payload),
        );
    }

    /**
     * A day, not a timestamp.
     *
     * The card shows a day and the shop's own account page shows a day; handing the client a time it
     * will only throw away invites two surfaces to disagree about time zones for no benefit.
     */
    public function testSerialisesTheDateAsADay(): void
    {
        self::assertSame('2026-09-12', (new OrderPayload())->of([self::order()])[0]['orderedAt']);
    }

    public function testCarriesEachDocumentAsATitledLink(): void
    {
        $documents = (new OrderPayload())->of([self::order()])[0]['documents'];

        self::assertSame(
            [['title' => 'Invoice', 'url' => '/account/order/document/abc/def', 'extension' => 'pdf']],
            $documents,
        );
    }

    public function testAnEmptyTurnSerialisesToAnEmptyList(): void
    {
        self::assertSame([], (new OrderPayload())->of([]));
    }

    public function testTheDetailSerialisesItsLinesWithTheirFigures(): void
    {
        $detail = (new OrderPayload())->detail(self::detail());

        self::assertNotNull($detail);
        self::assertSame(
            ['orderNumber', 'orderedAt', 'state', 'total', 'currency', 'lines', 'documents'],
            array_keys($detail),
        );
        self::assertSame(
            [['name' => 'Chain Oil 100ml', 'quantity' => 3, 'unitPrice' => 12.90, 'lineTotal' => 38.70]],
            $detail['lines'],
        );
    }

    /** No order looked up means no card, not an empty one. */
    public function testNoDetailSerialisesToNull(): void
    {
        self::assertNull((new OrderPayload())->detail(null));
    }

    private static function detail(): OrderDetail
    {
        return new OrderDetail(
            orderNumber: '10023',
            orderedAt: new \DateTimeImmutable('2026-09-12 14:31:00'),
            stateLabel: 'Shipped',
            total: 118.44,
            currency: 'EUR',
            lines: [new OrderLine('Chain Oil 100ml', 3, 12.90, 38.70)],
            documents: [],
        );
    }

    private static function order(): OrderSummary
    {
        return new OrderSummary(
            orderNumber: '10023',
            orderedAt: new \DateTimeImmutable('2026-09-12 14:31:00'),
            stateLabel: 'Shipped',
            total: 118.44,
            currency: 'EUR',
            itemCount: 3,
            documents: [new OrderDocumentRef('Invoice', '/account/order/document/abc/def', 'pdf')],
        );
    }
}
