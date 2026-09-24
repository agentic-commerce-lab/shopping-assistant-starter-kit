<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Tool\Factory\GoToCheckoutToolFactory;
use Swag\AssistantStarterKit\Tests\Support\BuildsChatResponses;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * "Add it and take me to checkout", in one turn, through the real runner.
 *
 * **Found in production traces.** The turn called `add_to_cart` and then `go_to_checkout` on a
 * filled cart, the tool told the model a checkout link followed, and none did. The outcome is one
 * value and `cart_added` outranks `checkout_offered` — rightly, since the widget refreshes the header
 * cart only on `cart_added` — so a link that could only come from the outcome could never appear
 * beside an add.
 *
 * Driven through `AssistantRunner` rather than asserted on the resolver, because the defect lived in
 * the hand-over: the resolver and the payload were each right on their own.
 */
final class CheckoutBesideACartAddTest extends TestCase
{
    use BuildsChatResponses;
    use UsesCatalogFixture;

    public function testATurnThatAddsAndThenOffersCheckoutKeepsBoth(): void
    {
        $turn = self::runTurn([
            self::toolCallResponse(
                'add_to_cart',
                [
                    'variantId' => 'fx-026-blue-l',
                    'quantity' => 1,
                    'options' => [['Colour', 'Blue'], ['Size', 'L']],
                ],
                'call-1',
            ),
            self::toolCallResponse('go_to_checkout', [], 'call-2'),
            self::textResponse('Added. You can go to checkout now.'),
        ]);

        self::assertSame('cart_added', $turn->outcome, 'the header cart refresh keys off this value');
        self::assertTrue($turn->checkoutOffered, 'the offer must survive being outranked by the add');
    }

    public function testCheckingAnEmptyCartOffersNothing(): void
    {
        // The stage alone is not the offer: `go_to_checkout` records it for an empty cart too, and a
        // checkout link beside "your cart is empty" is the empty promise this whole path avoids.
        $turn = self::runTurn([
            self::toolCallResponse('go_to_checkout', []),
            self::textResponse('Your cart is empty.'),
        ]);

        self::assertFalse($turn->checkoutOffered);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private static function runTurn(array $responses): AssistantTurn
    {
        $config = new AssistantConfig();

        // `withCoreToolsOnly()` has no checkout tool — its factory is gated on a cart, which the eval
        // harness it serves never has — so it is added here the way a contributed tool would be.
        $bundle = AssistantAgentFactory::withCoreToolsOnly(new MockHttpClient($responses))
            ->withAdditionalFactories([], [new GoToCheckoutToolFactory()])
            ->create(
                FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
                $config,
                cartAvailable: true,
                // A resolvable public host, so the mocked platform clears the SSRF guard — the same
                // reason AssistantRunnerTest uses it.
                llm: new LlmSettings('https://1.1.1.1', 'test-key', 'gpt-x'),
            );

        return (new AssistantRunner($config, $bundle))->run('add it and take me to checkout', new MessageBag());
    }
}
