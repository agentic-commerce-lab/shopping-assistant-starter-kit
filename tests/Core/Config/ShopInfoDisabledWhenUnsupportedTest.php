<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;
use Swag\AssistantStarterKit\Core\ShopInfo\VectorSupport;

/**
 * A shop that cannot run shop-information retrieval reads back as one that has it switched off.
 *
 * **This is the fix for a live outage.** With `embeddingModel` set on a shop whose vendor tree lacked
 * `symfony/ai-store`, `SearchShopInfoToolFactory` built the tool on every turn and every shopper
 * message returned a 500. The setting was the merchant's; the crash was the plugin's.
 *
 * The choke point is deliberate. `embeddingModel: ''` already means "off" everywhere in this plugin
 * — no tool constructed, nothing in the model's schema, ingestion refused at the CLI (spec R13) — so
 * forcing it here reuses a path that is already tested rather than adding a second kind of
 * switched-off. `autoIndexShopPages` goes with it: re-indexing pages for a store that cannot hold
 * them would spend embedding calls to fill nothing.
 */
final class ShopInfoDisabledWhenUnsupportedTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    private function vectors(bool $available): VectorSupport
    {
        return new class($available) implements VectorSupport {
            public function __construct(
                private readonly bool $available,
            ) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function describe(): string
            {
                return 'MySQL 8.0.46';
            }
        };
    }

    private function config(bool $supported): SystemConfigAssistantConfig
    {
        return new SystemConfigAssistantConfig(
            new FakeSystemConfigService([
                self::PREFIX . 'enableShopKnowledge' => true,
                self::PREFIX . 'embeddingModel' => 'baai/bge-m3',
                self::PREFIX . 'autoIndexShopPages' => true,
            ]),
            new ShopInfoAvailability($this->vectors($supported), packagesInstalled: true),
        );
    }

    public function testAConfiguredModelSurvivesOnAShopThatCanUseIt(): void
    {
        self::assertSame('baai/bge-m3', $this->config(supported: true)->forSalesChannel(self::CHANNEL)->embeddingModel);
    }

    /**
     * The merchant's stored value is untouched — this reads it as off rather than deleting it, so
     * moving the shop to a MariaDB brings the feature back without anybody retyping a model name.
     */
    public function testAConfiguredModelReadsAsEmptyOnAShopThatCannot(): void
    {
        self::assertSame('', $this->config(supported: false)->forSalesChannel(self::CHANNEL)->embeddingModel);
    }

    public function testAutomaticReindexingIsOffTooWhenTheStoreCannotHoldAnything(): void
    {
        self::assertFalse($this->config(supported: false)->forSalesChannel(self::CHANNEL)->autoIndexShopPages);
    }

    public function testAutomaticReindexingIsUntouchedOnASupportedShop(): void
    {
        self::assertTrue($this->config(supported: true)->forSalesChannel(self::CHANNEL)->autoIndexShopPages);
    }

    /**
     * Nothing else on the config is affected. The assistant's own settings have nothing to do with
     * the vector store, and a shop without one must keep answering product questions exactly as
     * before — which is the whole point of not letting this feature take the assistant down.
     */
    public function testNoOtherSettingIsAffected(): void
    {
        $unsupported = $this->config(supported: false)->forSalesChannel(self::CHANNEL);

        self::assertTrue($unsupported->assistantEnabled);
        self::assertTrue($unsupported->enableAddToCart);
        self::assertTrue($unsupported->enableEscalation);
    }
}
