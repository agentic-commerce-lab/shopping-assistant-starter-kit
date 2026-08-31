<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;

/**
 * The two settings shop-information retrieval adds, and the tenant it filters on.
 *
 * Split from {@see SystemConfigAssistantConfigTest} when the settings rework and this feature met:
 * the union of both sides\' tests put that class over mago\'s per-class method cap. The boundary is
 * the right one anyway — these three assertions are about one feature\'s configuration, and the
 * embedding model is that feature\'s off switch rather than one more merchant preference.
 */
final class SystemConfigShopInfoSettingsTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    public function testItRecordsTheSalesChannelItWasBuiltFor(): void
    {
        // The tenant every shop-info query filters on. AssistantConfig is already built per sales
        // channel; until now it simply did not record which one, and nothing above the gateway seam
        // could ask. Spec R12 needs it, and widening ToolContext — which docs/extending.md presents
        // as an extension point — would have been the more invasive way to get it there.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);

        self::assertSame(self::CHANNEL, $config->salesChannelId);
    }

    public function testTheEmbeddingModelIsReadAndDefaultsToEmpty(): void
    {
        $configured = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'enableShopKnowledge' => true,
            self::PREFIX . 'embeddingModel' => '  text-embedding-3-small  ',
        ])))->forSalesChannel(self::CHANNEL);

        // Trimmed, because a trailing space in a model name is a 404 from the provider and reads
        // in the admin form as a correctly filled field.
        self::assertSame('text-embedding-3-small', $configured->embeddingModel);

        // Empty is the off switch for the entire feature (R13), so it must be the default rather
        // than a fallback model nobody chose. A starter kit offering a tool that always fails is
        // worse than one offering no tool.
        $absent = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);

        self::assertSame('', $absent->embeddingModel);
    }

    /**
     * Off by default, because each change costs an embedding call — and read through
     * `StoredValueReader::bool()` rather than a cast, since `system:config:set ... false` stores the
     * string "false" and `(bool) "false"` is true. The kill switch was measured failing exactly that
     * way; this one would spend money rather than open a guardrail.
     */
    public function testAutomaticReindexingIsOffByDefaultAndAStoredFalseSurvives(): void
    {
        $absent = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);

        self::assertFalse($absent->autoIndexShopPages);

        $storedFalse = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'autoIndexShopPages' => 'false',
        ])))->forSalesChannel(self::CHANNEL);

        self::assertFalse($storedFalse->autoIndexShopPages);

        $storedTrue = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'enableShopKnowledge' => true,
            self::PREFIX . 'embeddingModel' => 'baai/bge-m3',
            self::PREFIX . 'autoIndexShopPages' => true,
        ])))->forSalesChannel(self::CHANNEL);

        self::assertTrue($storedTrue->autoIndexShopPages);
    }
}
