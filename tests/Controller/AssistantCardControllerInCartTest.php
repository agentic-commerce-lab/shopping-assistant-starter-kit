<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Controller\AssistantCardController;
use Swag\AssistantStarterKit\Core\Commerce\CardResolver;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Tests\Core\Config\FakeSystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The other half of the same defect, on the path a returning shopper takes.
 *
 * `_renderHistory()` rebuilds **every** card in a restored conversation from this route, so before
 * `inCart` existed a re-hydrated transcript showed "Add to cart" on products already in the cart —
 * including the ones the shopper had clicked themselves in that very conversation, because the
 * succeeded state was a DOM mutation the rebuild threw away.
 *
 * This route deliberately re-renders from the catalogue **now** rather than replaying stored figures
 * (see the class docblock), and the cart is read on exactly the same terms: what is true at the
 * moment of asking, not what was true when the turn was written.
 */
#[CoversClass(AssistantCardController::class)]
final class AssistantCardControllerInCartTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    /**
     * A real Shopware id, not one of `catalog.json`'s readable `fx-001` ones.
     *
     * This route validates ids against {@see \Swag\AssistantStarterKit\Controller\CardIdList::ID_PATTERN}
     * — 32 lowercase hex characters — and silently drops anything else, so the readable fixture
     * catalogue cannot reach it at all. See `catalog-shopware-ids.json`.
     */
    private const CHAIN_LUBE = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';

    /**
     * `array-key` rather than `string`: this comes back through `json_decode`, which cannot promise
     * the analyzer that a decoded object's keys are strings.
     *
     * @return array<array-key, mixed>
     */
    private function firstCard(FixtureCommerceGateway $gateway): array
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::CHANNEL);

        $controller = new AssistantCardController(
            new CardResolver($gateway),
            new SystemConfigAssistantConfig(new FakeSystemConfigService([])),
            $gateway,
        );

        $response = $controller->cards(Request::create('/assistant/cards', 'GET', [
            'ids' => self::CHAIN_LUBE,
        ]), $context);

        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['cards'] ?? null);
        self::assertArrayHasKey(0, $body['cards']);
        self::assertIsArray($body['cards'][0]);

        return $body['cards'][0];
    }

    public function testARestoredCardKnowsItIsAlreadyInTheCart(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../Fixtures/catalog-shopware-ids.json');
        $gateway->addToCart(self::CHAIN_LUBE, 4);

        self::assertSame(4, $this->firstCard($gateway)['inCart']);
    }

    public function testARestoredCardForAnEmptyCartSaysZero(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../Fixtures/catalog-shopware-ids.json');

        self::assertSame(0, $this->firstCard($gateway)['inCart']);
    }
}
