<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Trace\TraceEvent;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Ruling R32: exactly ONE CommerceGatewayInterface instance must back every
 * tool for the whole request, because AddToCartTool reads `gateway->cart()->total`
 * live to enforce maxCartValue. A gateway built per tool instead of per
 * request would let every add_to_cart call see an empty cart, letting a model
 * drip-feed past the limit one item at a time — which is exactly how a model
 * would do it, and the guardrail would still pass its own unit test because
 * that test only ever uses one gateway.
 */
final class AssistantAgentFactoryTest extends TestCase
{
    use UsesCatalogFixture;

    private function bundle(AssistantConfig $config, bool $cartAvailable = true): AssistantAgentFactory\Bundle
    {
        return AssistantAgentFactory::create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            $config,
            $cartAvailable,
            new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
            new MockHttpClient(static function (): never {
                throw new \RuntimeException('The platform must not be called by this test.');
            }),
        );
    }

    public function testTwoSuccessiveAddToCartCallsThroughTheSameToolboxAccumulateOnOneGateway(): void
    {
        // fx-026-blue-l costs 49.90; one unit fits under 60.0, two do not (99.80 > 60.0).
        // If the factory built a fresh gateway per tool call, both calls would see an
        // empty cart and both would be wrongly allowed.
        $bundle = $this->bundle(new AssistantConfig(maxCartValue: 60.0));

        $bundle->toolbox->execute(new ToolCall('call-1', 'add_to_cart', [
            'variantId' => 'fx-026-blue-l',
            'quantity' => 1,
        ]));
        $bundle->toolbox->execute(new ToolCall('call-2', 'add_to_cart', [
            'variantId' => 'fx-026-blue-l',
            'quantity' => 1,
        ]));

        // This used to need a payload-key filter: dispatch and outcome both recorded `tool.call`,
        // so the only way to tell them apart was that one of them carried `policyReasonCode`. The
        // outcome has its own stage now, which is the point of the rename — the filter that used to
        // be necessary here is what proved the two facts were indistinguishable.
        $toolCallEvents = array_values(array_filter(
            $bundle->trace->events(),
            static fn(TraceEvent $event): bool => AddToCartTool::TRACE_STAGE === $event->stage,
        ));

        self::assertCount(2, $toolCallEvents);

        $first = $toolCallEvents[0] ?? null;
        $second = $toolCallEvents[1] ?? null;
        self::assertNotNull($first);
        self::assertNotNull($second);

        self::assertSame('allowed', $first->payload['policyReasonCode']);
        self::assertSame('cart_limit', $second->payload['policyReasonCode']);
    }

    public function testEscalationSwitchedOffMeansTheToolIsNeverInTheToolbox(): void
    {
        // The guarantee `enableEscalation`'s help text makes, asserted the way `enableAddToCart`'s
        // is: not "the model was told not to", but "there is nothing there to call". A prompt-level
        // switch would be a request; this is an absence.
        $bundle = $this->bundle(new AssistantConfig(enableEscalation: false), cartAvailable: false);

        self::assertNotContains('escalate', self::toolNames($bundle));
    }

    public function testEscalationIsInTheToolboxByDefault(): void
    {
        $bundle = $this->bundle(new AssistantConfig(), cartAvailable: false);

        self::assertContains('escalate', self::toolNames($bundle));
    }

    /**
     * @return list<string>
     */
    private static function toolNames(AssistantAgentFactory\Bundle $bundle): array
    {
        return array_map(static fn($tool): string => $tool->getName(), [...$bundle->toolbox->getTools()]);
    }
}
