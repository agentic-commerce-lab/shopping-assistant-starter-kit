<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolFactoryInterface;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * What an agency's own tool actually gets, asserted through the real factory.
 *
 * The interfaces are types; these are the three behaviours that make them worth having.
 */
final class ContributedToolTest extends TestCase
{
    use UsesCatalogFixture;

    public function testAContributedToolReachesTheToolboxTheModelSees(): void
    {
        $bundle = $this->factoryWith([new RecordingToolFactory()], [])->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            new AssistantConfig(),
            cartAvailable: false,
            llm: new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
        );

        self::assertContains('recording_probe', self::toolNames($bundle));
    }

    public function testEveryToolInOneTurnSharesOneGatewayInstance(): void
    {
        // **Ruling R32, and the reason making this factory a service was risky.** AddToCartTool reads
        // gateway->cart()->total live to enforce maxCartValue, so a tool holding its own gateway sees
        // an empty cart on every call and a model can drip-feed past the limit one item at a time. A
        // contributed factory must be handed the same instances the shipped ones get — not equivalent
        // ones, the same ones.
        //
        // Verified falsifiable, 2026-08-23: building a fresh GroundedToolContext per factory with a
        // cloned gateway fails this test — and **passes** AssistantAgentFactoryTest's cart-limit
        // guard, which only exercises repeated calls through one tool. That guard does not cover
        // tools sharing a gateway with each other, which is why this assertion is not redundant.
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $first = new CapturingGroundedToolFactory();
        $second = new CapturingGroundedToolFactory();

        $this->factoryWith([], [$first, $second])->create(
            $gateway,
            new AssistantConfig(),
            cartAvailable: true,
            llm: new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
        );

        $firstContext = $first->context;
        $secondContext = $second->context;
        self::assertNotNull($firstContext);
        self::assertNotNull($secondContext);

        self::assertSame($gateway, $firstContext->gateway);
        self::assertSame($firstContext->gateway, $secondContext->gateway);
        self::assertSame($firstContext->trace, $secondContext->trace);
        self::assertSame($firstContext->renderer, $secondContext->renderer);
    }

    public function testAnUnprivilegedToolSharesTheSameTraceAsTheGroundedOnes(): void
    {
        // One trace per turn is the other half of R32: a contributed tool recording into its own
        // recorder would leave its work out of the merchant's trace entirely.
        $grounded = new CapturingGroundedToolFactory();
        $plain = new RecordingToolFactory();

        $this->factoryWith([$plain], [$grounded])->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            new AssistantConfig(),
            cartAvailable: false,
            llm: new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
        );

        $plainContext = $plain->context;
        $groundedContext = $grounded->context;
        self::assertNotNull($plainContext);
        self::assertNotNull($groundedContext);

        self::assertSame($groundedContext->trace, $plainContext->trace);
    }

    public function testAFactoryReturningNullContributesNothing(): void
    {
        // How enableAddToCart and enableEscalation work, and the mechanism a contributed tool should
        // use for its own switch: never constructed means never in the schema the model sees (D6).
        $bundle = $this->factoryWith([new DecliningToolFactory()], [])->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            new AssistantConfig(),
            cartAvailable: false,
            llm: new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
        );

        self::assertNotContains('declining_probe', self::toolNames($bundle));
    }

    public function testTheShippedToolsAreStillThereAlongsideAContributedOne(): void
    {
        // A contributed tool must add to the assistant, not replace it.
        $bundle = $this->factoryWith([new RecordingToolFactory()], [])->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            new AssistantConfig(),
            cartAvailable: true,
            llm: new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
        );

        $names = self::toolNames($bundle);

        foreach (['search_products', 'get_product', 'add_to_cart', 'escalate'] as $shipped) {
            self::assertContains($shipped, $names);
        }
    }

    /**
     * @param list<ToolFactoryInterface>         $toolFactories
     * @param list<GroundedToolFactoryInterface> $groundedToolFactories
     */
    private function factoryWith(array $toolFactories, array $groundedToolFactories): AssistantAgentFactory
    {
        // withCoreToolsOnly() plus extras, so the shipped four are present exactly as a real shop has
        // them — a fixture that dropped them would not be testing the object the storefront builds.
        return AssistantAgentFactory::withCoreToolsOnly(new MockHttpClient(static function (): never {
            throw new \RuntimeException('The platform must not be called by this test.');
        }))->withAdditionalFactories($toolFactories, $groundedToolFactories);
    }

    /**
     * @return list<string>
     */
    private static function toolNames(AssistantAgentFactory\Bundle $bundle): array
    {
        return array_map(static fn($tool): string => $tool->getName(), [...$bundle->toolbox->getTools()]);
    }
}
