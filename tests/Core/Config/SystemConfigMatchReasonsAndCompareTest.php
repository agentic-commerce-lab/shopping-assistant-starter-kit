<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;

/**
 * `enableMatchReasons`/`enableCompareProducts`, in their own class rather than in
 * {@see SystemConfigAssistantConfigTest}: the same reason {@see SystemConfigEscalationTest} is split
 * out — mago's method cap is a fair reading of the alternative, a config test class that covers
 * everything covers nothing in particular.
 *
 * Unlike `enableAddToCart`/`enableEscalation`, both flags default to **off**: they are new, unproven
 * capabilities (design spec Phase 2a/2b), so an absent key must read as "not enabled" rather than the
 * shipped-on reading the older flags get.
 */
final class SystemConfigMatchReasonsAndCompareTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    public function testMatchReasonsAndCompareProductsDefaultToOff(): void
    {
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);

        self::assertFalse($config->enableMatchReasons);
        self::assertFalse($config->enableCompareProducts);
    }

    public function testAStoredTrueForMatchReasonsAndCompareProductsIsHonoured(): void
    {
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'enableMatchReasons' => true,
            self::PREFIX . 'enableCompareProducts' => true,
        ])))->forSalesChannel(self::CHANNEL);

        self::assertTrue($config->enableMatchReasons);
        self::assertTrue($config->enableCompareProducts);
    }
}
