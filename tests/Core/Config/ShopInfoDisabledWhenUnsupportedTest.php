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
 * **This is the fix for a live outage, and the outage was the missing PACKAGE.** With `embeddingModel`
 * set on a shop whose vendor tree lacked `symfony/ai-store`, `SearchShopInfoToolFactory` built the
 * tool on every turn and every shopper message returned a 500: *Attempted to load class "Vectorizer"*.
 * The setting was the merchant's; the crash was the plugin's. Without that package there is no way to
 * turn a question into a vector, so there is nothing for any store to search — which is why this test
 * still guards a real failure rather than a hypothetical one.
 *
 * **The database is deliberately NOT one of these cases any more.** It used to be: the only store
 * this plugin shipped needed MariaDB's `VEC_DISTANCE_COSINE`, so a MySQL shop read back switched off.
 * `DalPortablePassageStore` runs anywhere Shopware does, so a vector-less shop now KEEPS its model —
 * pinned below, because that inversion is the whole point of the portable store and a regression
 * would quietly make it dead code.
 *
 * The choke point is unchanged. `embeddingModel: ''` already means "off" everywhere in this plugin
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
                return $this->available ? 'MariaDB 11.8' : 'MySQL 8.0.46';
            }
        };
    }

    private function config(bool $packagesInstalled, bool $vectorsWork = true): SystemConfigAssistantConfig
    {
        return new SystemConfigAssistantConfig(
            new FakeSystemConfigService([
                self::PREFIX . 'enableShopKnowledge' => true,
                self::PREFIX . 'embeddingModel' => 'baai/bge-m3',
                self::PREFIX . 'autoIndexShopPages' => true,
            ]),
            new ShopInfoAvailability($this->vectors($vectorsWork), packagesInstalled: $packagesInstalled),
        );
    }

    public function testAConfiguredModelSurvivesOnAShopThatCanUseIt(): void
    {
        $config = $this->config(packagesInstalled: true);

        self::assertSame('baai/bge-m3', $config->forSalesChannel(self::CHANNEL)->embeddingModel);
    }

    /**
     * The merchant's stored value is untouched — this reads it as off rather than deleting it, so
     * installing the package into the shop brings the feature back without anybody retyping a model
     * name.
     */
    public function testAConfiguredModelReadsAsEmptyWhenTheStorePackageIsMissing(): void
    {
        $config = $this->config(packagesInstalled: false);

        self::assertSame('', $config->forSalesChannel(self::CHANNEL)->embeddingModel);
    }

    public function testAutomaticReindexingIsOffTooWhenTheStorePackageIsMissing(): void
    {
        $config = $this->config(packagesInstalled: false);

        self::assertFalse($config->forSalesChannel(self::CHANNEL)->autoIndexShopPages);
    }

    public function testAutomaticReindexingIsUntouchedOnASupportedShop(): void
    {
        self::assertTrue($this->config(packagesInstalled: true)->forSalesChannel(self::CHANNEL)->autoIndexShopPages);
    }

    /**
     * The case that used to read back as switched off. A MySQL shop has no vector functions and never
     * will — `DISTANCE()` is HeatWave-only — so it answers from the portable store, which needs
     * nothing from the database beyond an ordinary table. Its model and its re-indexing stay exactly
     * as the merchant left them.
     */
    public function testAShopWithoutVectorFunctionsKeepsItsModelAndRunsThePortableStore(): void
    {
        $config = $this->config(packagesInstalled: true, vectorsWork: false)->forSalesChannel(self::CHANNEL);

        self::assertSame('baai/bge-m3', $config->embeddingModel);
        self::assertTrue($config->autoIndexShopPages);
    }

    /**
     * Nothing else on the config is affected. The assistant's own settings have nothing to do with
     * the passage store, and a shop without one must keep answering product questions exactly as
     * before — which is the whole point of not letting this feature take the assistant down.
     */
    public function testNoOtherSettingIsAffected(): void
    {
        $unsupported = $this->config(packagesInstalled: false)->forSalesChannel(self::CHANNEL);

        self::assertTrue($unsupported->assistantEnabled);
        self::assertTrue($unsupported->enableAddToCart);
        self::assertTrue($unsupported->enableEscalation);
    }
}
