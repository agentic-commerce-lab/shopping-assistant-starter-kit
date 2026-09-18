<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use Swag\AssistantStarterKit\Controller\AssistantController;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;

/**
 * The bug this fixes, at the boundary the shopper actually meets.
 *
 * Reported from the storefront: *"I get a product card, I tell the agent to add it to the cart, and
 * the reply shows the same card again — but the button still says Add to cart."* The button was not
 * merely mislabelled. It was **live**: the click handler adds through the store-api cart route
 * regardless, so the shopper who trusted the label bought a second one.
 *
 * The cause was that "added" existed only as a DOM mutation inside the click handler
 * (`markAdded()` in `card.js`), and never as a fact on the card. Anything that rebuilt a card from
 * the server — the assistant's own confirmation card, a re-hydrated conversation, a later turn
 * showing the same product — rebuilt it without that knowledge.
 *
 * The real {@see FixtureCommerceGateway} and its real cart, rather than a stub returning a fixed
 * summary: the whole claim is that the figure on the card is *the cart's*, so a test that invents
 * the cart tests the assertion rather than the mechanism.
 */
#[CoversClass(AssistantController::class)]
final class AssistantCardInCartTest extends AssistantEndpointTestCase
{
    /** A real product in the fixture catalogue, so the gateway's cart will accept it. */
    private const CHAIN_LUBE = 'fx-001';

    private function chainLube(): ProductCard
    {
        return new ProductCard(
            id: self::CHAIN_LUBE,
            parentId: null,
            name: 'Unnamed Chain Lube',
            description: null,
            price: 12.90,
            currency: 'EUR',
            stock: 20,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . self::CHAIN_LUBE,
            imageUrl: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function firstCardAfterChat(FixtureCommerceGateway $gateway): array
    {
        $controller = $this->controller(gateway: $gateway);
        $this->runner->card = $this->chainLube();

        $response = $controller->chat($this->post(['message' => 'add the chain lube']), $this->context());

        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['cards'] ?? null);
        self::assertArrayHasKey(0, $body['cards']);
        self::assertIsArray($body['cards'][0]);

        return $body['cards'][0];
    }

    public function testACardForSomethingTheCartHoldsSaysHowMany(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../Fixtures/catalog.json');
        $gateway->addToCart(self::CHAIN_LUBE, 2);

        self::assertSame(2, $this->firstCardAfterChat($gateway)['inCart']);
    }

    /**
     * The ordinary case, and the one that must not regress into a permanent "In cart" badge on
     * every card in the shop.
     */
    public function testACardForSomethingTheCartDoesNotHoldSaysZero(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../Fixtures/catalog.json');

        self::assertSame(0, $this->firstCardAfterChat($gateway)['inCart']);
    }
}
