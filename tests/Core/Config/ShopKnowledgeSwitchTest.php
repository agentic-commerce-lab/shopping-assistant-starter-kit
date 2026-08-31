<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;
use Swag\AssistantStarterKit\Core\ShopInfo\VectorSupport;

/**
 * The switch a merchant actually reads.
 *
 * `embeddingModel` sat in the "Language model" card beside the base URL and the API key, so nothing
 * on that screen said the field turns on document-based answering. A merchant could not find the
 * feature, and the one who did configure it did so blind — which is half of why an embedding model
 * ended up set on a shop that could not run it.
 *
 * The boolean resolves INTO `embeddingModel`, which is already this plugin's one off-state: no tool
 * constructed, nothing in the model's schema, ingestion refused at the CLI. A second kind of
 * "switched off" would be a second thing to get wrong.
 */
final class ShopKnowledgeSwitchTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    private function config(bool $enabled): SystemConfigAssistantConfig
    {
        $vectors = new class implements VectorSupport {
            public function isAvailable(): bool
            {
                return true;
            }

            public function describe(): string
            {
                return 'MariaDB 11.8';
            }
        };

        return new SystemConfigAssistantConfig(
            new FakeSystemConfigService([
                self::PREFIX . 'enableShopKnowledge' => $enabled,
                self::PREFIX . 'embeddingModel' => 'baai/bge-m3',
                self::PREFIX . 'autoIndexShopPages' => true,
            ]),
            new ShopInfoAvailability($vectors, packagesInstalled: true),
        );
    }

    public function testTheModelIsReadWhenTheMerchantSwitchedShopKnowledgeOn(): void
    {
        self::assertSame('baai/bge-m3', $this->config(true)->forSalesChannel(self::CHANNEL)->embeddingModel);
    }

    /**
     * The stored model name is left alone — switching the feature back on must not require retyping
     * it, and the settings screen still shows what was chosen.
     */
    public function testTheModelReadsAsEmptyWhenTheSwitchIsOff(): void
    {
        self::assertSame('', $this->config(false)->forSalesChannel(self::CHANNEL)->embeddingModel);
    }

    public function testAutomaticReindexingIsOffWithTheSwitch(): void
    {
        self::assertFalse($this->config(false)->forSalesChannel(self::CHANNEL)->autoIndexShopPages);
    }

    public function testTheSwitchIsOffOnAShopThatNeverSetIt(): void
    {
        $config = new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'embeddingModel' => 'baai/bge-m3',
        ]));

        self::assertSame('', $config->forSalesChannel(self::CHANNEL)->embeddingModel);
    }
}
