<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\ShopInfo\EmbedderFactory;
use Swag\AssistantStarterKit\Core\ShopInfo\SearchShopInfoTool;
use Swag\AssistantStarterKit\Core\Tool\Factory\SearchShopInfoToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\ShopInfo\InMemoryPassageStore;
use Swag\AssistantStarterKit\Tests\Core\Config\FakeSystemConfigService;

/**
 * Spec R13's off switch, asserted rather than only checked by hand against a shop.
 *
 * This is the guarantee that a shop which never configured an embedding model gets no shop-info tool
 * at all, rather than one that fails on every call. It matters more than it looks: returning null is
 * the *only* mechanism that keeps the tool out of the schema the model sees, so a regression here
 * would not throw — it would offer the model a capability that always errors, and the model would
 * keep reaching for it.
 *
 * Nothing here touches the network. Building an embedder resolves settings and constructs a platform;
 * a request happens only when something asks it to embed.
 */
final class SearchShopInfoToolFactoryTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testAnEmptyEmbeddingModelContributesNoToolAtAll(): void
    {
        self::assertNull(self::factory()->create(self::contextFor('')));
    }

    public function testAConfiguredEmbeddingModelContributesTheTool(): void
    {
        $tool = self::factory()->create(self::contextFor('text-embedding-3-small'));

        self::assertInstanceOf(SearchShopInfoTool::class, $tool);
    }

    private static function factory(): SearchShopInfoToolFactory
    {
        $prefix = SystemConfigAssistantConfig::PREFIX;

        return new SearchShopInfoToolFactory(
            new EmbedderFactory(new SystemConfigLlmSettings(new FakeSystemConfigService([
                $prefix . 'llmBaseUrl' => 'https://provider.invalid',
                $prefix . 'llmModel' => 'some-chat-model',
                $prefix . 'llmApiKey' => 'not-a-real-key',
            ]))),
            new InMemoryPassageStore(),
        );
    }

    private static function contextFor(string $embeddingModel): ToolContext
    {
        return new ToolContext(
            new TraceRecorder(),
            new AssistantConfig(salesChannelId: self::CHANNEL, embeddingModel: $embeddingModel),
        );
    }
}
