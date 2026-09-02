<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Split out of {@see AddToCartToolTest} for the same reason
 * {@see AddToCartToolStoredQuantityTest} was: a new fixture double, not a suppression.
 *
 * Covers the defect this task exists to fix: the widget (`card.js`) already refuses to render an
 * add-to-cart button for a product family, but {@see AddToCartTool} — the write authority — had no
 * equivalent check, so a shopper could be told "Added 1 to the cart" beside a family's aggregate
 * stock and cheapest entry price, with nobody able to say which variant actually reached the cart.
 */
final class AddToCartToolFamilyTest extends TestCase
{
    private TraceRecorder $trace;

    private FactRenderer $renderer;

    // Initialized here for the same reason as AddToCartToolTest's own constructor: the analyzer's
    // uninitialized-property check cannot see that toolWith()/tool() always replace these before
    // any assertion runs.
    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);
    }

    private function tool(): AddToCartTool
    {
        return $this->toolWith(FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json'));
    }

    private function toolWith(CommerceGatewayInterface $gateway): AddToCartTool
    {
        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);

        return new AddToCartTool($gateway, new BlocklistFilter(), $this->renderer, $this->trace, new AssistantConfig());
    }

    /**
     * A gateway that returns a FAMILY card — a parent product whose stock figure is the sum across
     * its variants. `FixtureCommerceGateway` cannot do this: `FixtureIndex` never emits
     * `StockSource::Parent`, because a family parent is not a sellable unit there. Only the DAL
     * gateway produces these, which is why no fixture test could ever have caught the defect this
     * file exists for. Measured against the demo shop on 2026-08-30, a real one looks like
     * "Midi Bag 0539": five variants, stockSource=parent, stock 6, price 19.31.
     */
    private function familyGateway(): CommerceGatewayInterface
    {
        $inner = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $family = $this->familyCard();

        return new class($inner, $family) implements CommerceGatewayInterface {
            public int $addCalls = 0;

            public function __construct(
                private readonly CommerceGatewayInterface $inner,
                private readonly ProductCard $family,
            ) {}

            public function facets(CatalogScope $scope): FacetSet
            {
                return $this->inner->facets($scope);
            }

            /** @return list<ProductCard> */
            public function search(ProductQuery $query, CatalogScope $scope): array
            {
                return $this->inner->search($query, $scope);
            }

            public function product(string $productId, CatalogScope $scope): ?ProductCard
            {
                if ($productId === $this->family->id) {
                    return $this->family;
                }

                return $this->inner->product($productId, $scope);
            }

            /** @param list<VariantSelection> $selections */
            public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
            {
                return $this->inner->resolveVariant($parentId, $selections, $scope);
            }

            public function addToCart(string $variantId, int $quantity): CartSummary
            {
                // Records the call rather than delegating: `fx-026` is a family id, not a
                // sellable unit, so the real fixture gateway has no line for it and would
                // throw. If the guard ever regresses, this must still return something rather
                // than error, so the test failure is "a family was added" and not an
                // unrelated exception.
                $this->addCalls++;

                return new CartSummary(itemCount: $quantity, total: $this->family->price * $quantity);
            }

            public function cart(): CartSummary
            {
                return new CartSummary();
            }
        };
    }

    private function familyCard(): ProductCard
    {
        return new ProductCard(
            id: 'fx-026',
            parentId: null,
            name: 'Trail Jersey',
            description: 'A family, not a variant.',
            price: 19.31,
            currency: 'EUR',
            stock: 6,
            stockSource: StockSource::Parent,
            deliveryTime: null,
            url: '/detail/fx-026',
            imageUrl: null,
        );
    }

    public function testAFamilyIsRefusedRatherThanAdded(): void
    {
        // The defect: the widget refuses the button for a family, the tool did not. A shopper was
        // told "Added 1 to the cart" beside a price that is the family's cheapest entry point and a
        // stock figure summed across five variants — with no way for anyone to say which colour
        // was bought.
        $result = $this->toolWith($this->familyGateway())(variantId: 'fx-026', quantity: 1);

        self::assertArrayNotHasKey('cart', $result);
        self::assertStringContainsString('family', strtolower($result['note']));
    }

    // @mago-expect analysis:non-existent-property
    // `addCalls` is declared on the anonymous class `familyGateway()` returns, not on
    // `CommerceGatewayInterface` — the analyzer only sees the declared return type. Widening
    // the interface for one test double's counter would be the wrong fix; the property really
    // is there at runtime, which is all this assertion needs.
    public function testNothingReachesTheCartWhenAFamilyIsRefused(): void
    {
        // Asserting the note alone would pass against a tool that refuses politely and adds anyway.
        $gateway = $this->familyGateway();

        $this->toolWith($gateway)(variantId: 'fx-026', quantity: 1);

        self::assertSame(0, $gateway->addCalls);
    }

    public function testTheRefusalIsTraceableUnderItsOwnReasonCode(): void
    {
        // A merchant reading the trace must be able to tell this refusal from a blocklist hit or a
        // cart limit, all three of which reach the same stage.
        $tool = $this->toolWith($this->familyGateway());
        $tool(variantId: 'fx-026', quantity: 1);

        $codes = [];
        foreach ($this->trace->events() as $event) {
            if ($event->stage === AddToCartTool::TRACE_STAGE) {
                $codes[] = $event->payload['policyReasonCode'] ?? null;
            }
        }

        self::assertContains('variant_required', $codes);
    }

    public function testAnOrdinaryVariantIsStillAdded(): void
    {
        // The guard must not refuse what it was never meant to: the fixture's own sellable units
        // carry StockSource::Variant or StockSource::Product and go through unchanged.
        //
        // `options` because this one is a variant of `fx-026`, and a variant the shopper did not
        // name is refused by the guard {@see AddToCartToolVariantChoiceTest} covers — a different
        // refusal from this file's subject, which is the family PARENT.
        $result = $this->tool()(variantId: 'fx-026-blue-l', quantity: 2, options: [['Colour', 'Blue'], ['Size', 'L']]);

        self::assertArrayHasKey('cart', $result);
        self::assertSame('Added 2 to the cart.', $result['note']);
    }
}
