<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Page context is a **hint, not an authority**: the id the storefront reports is resolved through
 * the same gateway and the same {@see CatalogScope} as any search hit, so the blocklist decides
 * what the assistant may see — not the client.
 */
final class PageContextTest extends TestCase
{
    use UsesCatalogFixture;

    private const OPEN_PRODUCT = 'fx-026-blue-l';

    public function testAnOpenProductIsPreGroundedSoNoToolCallIsNeededToNameIt(): void
    {
        $gateway = self::gateway();
        $card = $gateway->product(self::OPEN_PRODUCT, new CatalogScope());
        self::assertNotNull($card);

        $bundle = AssistantAgentFactory::withCoreToolsOnly(self::http())->create(
            $gateway,
            new AssistantConfig(),
            cartAvailable: false,
            llm: self::llm(),
            viewing: $card,
        );

        // Registered on the renderer: the model can name this product without being flagged as
        // having invented it, and it renders if the turn calls no tool at all.
        self::assertContains($card->id, $bundle->renderer->retrievedIds());
        self::assertStringContainsString($card->name, $bundle->viewing);
    }

    /**
     * A detail page reports the **variant** the shopper selected, and until 2026-09-02 that was all
     * the model was told — while the same line instructed it not to call a tool to look this product
     * up. Measured on the staging shop: asked which sizes existed, the model answered from the only
     * size it had, and said no others were listed. Correctly grounded and wrong.
     *
     * `fx-026-blue-l` is Blue/L of a Trail Jersey that also exists in Black and in M. Both of those
     * must reach the prompt, or the same question has the same wrong answer.
     */
    public function testAViewedVariantAlsoCarriesWhatTheRestOfItsFamilyOffers(): void
    {
        $gateway = self::gateway();
        $card = $gateway->product(self::OPEN_PRODUCT, new CatalogScope());
        self::assertNotNull($card);

        $bundle = AssistantAgentFactory::withCoreToolsOnly(self::http())->create(
            $gateway,
            new AssistantConfig(),
            cartAvailable: false,
            llm: self::llm(),
            viewing: $card,
        );

        self::assertStringContainsString('Black', $bundle->viewing, 'the sibling colour is missing');
        self::assertStringContainsString('M', $bundle->viewing, 'the sibling size is missing');
    }

    /**
     * The family is resolved through the same {@see CatalogScope} as everything else, so a blocked
     * sibling is not named. Otherwise the blocklist would leak the exact catalogue it exists to hide
     * — into the system prompt, where nothing downstream filters it.
     */
    public function testABlockedSiblingIsNeverNamedInThePrompt(): void
    {
        $gateway = self::gateway();
        $scope = new CatalogScope(blockedProductIds: ['fx-026-black-m']);
        $card = $gateway->product(self::OPEN_PRODUCT, $scope);
        self::assertNotNull($card);

        $bundle = AssistantAgentFactory::withCoreToolsOnly(self::http())->create(
            $gateway,
            new AssistantConfig(scope: $scope),
            cartAvailable: false,
            llm: self::llm(),
            viewing: $card,
        );

        self::assertStringNotContainsString('Black', $bundle->viewing);
    }

    public function testABlockedProductIsNotPreGrounded(): void
    {
        $gateway = self::gateway();

        $card = $gateway->product(self::OPEN_PRODUCT, new CatalogScope(blockedProductIds: [self::OPEN_PRODUCT]));

        // The gateway refuses it, so nothing reaches the renderer or the prompt. This is the whole
        // of the trust model: a forged id buys an attacker no more than a search would.
        self::assertNull($card);
    }

    public function testNoOpenProductLeavesThePromptAndRendererUntouched(): void
    {
        $bundle = AssistantAgentFactory::withCoreToolsOnly(self::http())->create(
            self::gateway(),
            new AssistantConfig(),
            cartAvailable: false,
            llm: self::llm(),
        );

        self::assertSame('', $bundle->viewing);
        self::assertSame([], $bundle->renderer->retrievedIds());
    }

    private static function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(self::catalogFixturePath());
    }

    private static function llm(): LlmSettings
    {
        return new LlmSettings('https://example.invalid', 'test-key', 'gpt-x');
    }

    private static function http(): MockHttpClient
    {
        return new MockHttpClient(static function (): never {
            throw new \RuntimeException('The platform must not be called by this test.');
        });
    }
}
