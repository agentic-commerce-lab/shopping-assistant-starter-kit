<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Tool\BrowseCategoriesTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * An answer made of department names must not inherit the cards a search left behind.
 *
 * Measured on staging after the tool shipped: *"I want to buy a bike"* produced the right prose —
 * the shop's real departments, no cleaner named — with a **Bike Wash 1L** card underneath it,
 * because the model searched first and `GroundingOutputProcessor` falls back to the last retrieved
 * batch when the prose names nothing. `PreGrounding` already does this reset one step earlier, for
 * the same reason and with the same trade.
 */
#[CoversClass(BrowseCategoriesTool::class)]
final class BrowseCategoriesClearsStaleCardsTest extends TestCase
{
    /**
     * **An answer with no products must not inherit the last search's cards.** Measured on staging:
     * *"I want to buy a bike"* produced the right prose — the real departments, no cleaner named —
     * with a Bike Wash 1L card underneath, because the model searched first and grounding falls back
     * to the last retrieved batch when the prose names nothing.
     */
    public function testItClearsTheCardsAPreviousSearchLeftBehind(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);
        $renderer->registerRetrieved([self::cleaner()]);

        self::assertNotSame([], $renderer->lastRetrievedBatch(), 'the search left a batch behind');

        (new BrowseCategoriesTool($this->reader(), $trace, $renderer))();

        self::assertSame([], $renderer->lastRetrievedBatch());
    }

    /**
     * The authoritative index is untouched, so a reply that goes on to name a product the turn really
     * retrieved still renders its card — the trade `PreGrounding` documents for the same reset.
     */
    public function testItLeavesTheTurnsRetrievedProductsNameable(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);
        $renderer->registerRetrieved([self::cleaner()]);

        (new BrowseCategoriesTool($this->reader(), $trace, $renderer))();

        self::assertSame(['bw'], array_map(static fn($card): string => $card->id, $renderer->retrievedCards()));
    }

    private static function cleaner(): \Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard
    {
        return new \Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard(
            id: 'bw',
            parentId: null,
            name: 'Bike Wash 1L',
            description: null,
            price: 9.9,
            currency: 'EUR',
            stock: 5,
            stockSource: \Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource::Product,
            deliveryTime: null,
            url: '/detail/bw',
            imageUrl: null,
        );
    }

    private function reader(): CategoryTreeReader
    {
        return new class implements CategoryTreeReader {
            public function categories(?string $parentId, CatalogScope $scope): array
            {
                return match ($parentId) {
                    null => [
                        self::node('acc', 'Accessories', hasChildren: true),
                        self::node('app', 'Apparel', hasChildren: true),
                        self::node('bag', 'Bags'),
                        // Stocked nowhere: an empty aisle is not an offer.
                        self::node('cle', 'Clearance', hasProducts: false),
                    ],
                    'acc' => [self::node('loc', 'Locks'), self::node('rac', 'Racks')],
                    'app' => [self::node('jac', 'Jackets')],
                    default => [],
                };
            }

            private static function node(
                string $id,
                string $name,
                bool $hasProducts = true,
                bool $hasChildren = false,
            ): CategoryNode {
                return new CategoryNode($id, $name, [$name], $hasProducts, $hasChildren);
            }
        };
    }
}
