<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;

/**
 * Until this class exists, `config.xml` is a form that changes nothing — including the kill switch,
 * which `ARCHITECTURE.md` calls a security control rather than an operational nicety. So these
 * assertions are about a setting reaching the pipeline at all, and about what happens when one is
 * absent.
 */
final class SystemConfigAssistantConfigTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    public function testEveryMerchantSettingReachesTheConfigObject(): void
    {
        $factory = new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'agentVoice' => 'Be concise.',
            self::PREFIX . 'enableAddToCart' => false,
            self::PREFIX . 'maxItemQuantity' => 2,
            self::PREFIX . 'maxCartValue' => 250.0,
            self::PREFIX . 'killSwitch' => true,
            self::PREFIX . 'dailyRequestCap' => 50,
            self::PREFIX . 'maxToolCallsPerTurn' => 3,
        ]));

        $config = $factory->forSalesChannel(self::CHANNEL);

        self::assertSame('Be concise.', $config->agentVoice);
        self::assertFalse($config->enableAddToCart);
        self::assertSame(2, $config->maxItemQuantity);
        self::assertSame(250.0, $config->maxCartValue);
        self::assertTrue($config->killSwitch);
        self::assertSame(50, $config->dailyRequestCap);
        self::assertSame(3, $config->maxToolCallsPerTurn);
    }

    public function testAnUnsetSettingFallsBackToTheDefaultRatherThanToZero(): void
    {
        // `getInt()` returns 0 for an absent key, and 0 is a *valid but catastrophic* value here:
        // maxToolCallsPerTurn 0 means no tool may ever run, dailyRequestCap 0 means every request
        // is refused. A shop that never opened config.xml must get the documented defaults, not a
        // silently disabled assistant.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);

        self::assertSame(5, $config->maxItemQuantity);
        self::assertSame(1000.0, $config->maxCartValue);
        self::assertSame(500, $config->dailyRequestCap);
        self::assertSame(5, $config->maxToolCallsPerTurn);
    }

    public function testAddToCartDefaultsToOnAndTheKillSwitchToOff(): void
    {
        // These two cannot use "absent means default" the way the ints do, because false is a
        // legitimate stored value. `getBool()` returns false for both an absent key and a stored
        // false — so enableAddToCart is read through get() to tell them apart, while killSwitch
        // reads false either way, which is the safe direction.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);

        self::assertTrue($config->enableAddToCart);
        self::assertFalse($config->killSwitch);
    }

    public function testAStoredFalseForAddToCartIsHonouredAndNotMistakenForAbsent(): void
    {
        // The whole point of the previous test's mechanism: a merchant who deliberately switched
        // add-to-cart off must not have it silently switched back on by a default.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'enableAddToCart' => false,
        ])))->forSalesChannel(self::CHANNEL);

        self::assertFalse($config->enableAddToCart);
    }

    public function testBlockedIdsAreSplitPerLineAndTrimmed(): void
    {
        // One long string would mean the blocklist matches nothing. A compliance control that
        // silently does nothing is worse than an absent one (D5).
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'blockedProducts' => "a2a2\n  b3b3  \n\nc4c4",
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame(['a2a2', 'b3b3', 'c4c4'], $config->scope->blockedProductIds);
    }

    public function testAllThreeIdListsLandInTheirOwnScopeField(): void
    {
        // Mixing these up would block what should merely be out of scope, or vice versa — and
        // both failures are invisible without reading a trace.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'blockedProducts' => 'prod-1',
            self::PREFIX . 'blockedCategories' => 'cat-1',
            self::PREFIX . 'excludedCategories' => 'cat-2',
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame(['prod-1'], $config->scope->blockedProductIds);
        self::assertSame(['cat-1'], $config->scope->blockedCategoryIds);
        self::assertSame(['cat-2'], $config->scope->excludeCategoryIds);
    }

    public function testAnEmptyListFieldYieldsAnEmptyArrayAndNotAnArrayWithAnEmptyString(): void
    {
        // [''] would make the blocklist compare every product id against the empty string, which
        // matches nothing but reports a configured blocklist in the trace.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'blockedProducts' => "\n  \n",
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame([], $config->scope->blockedProductIds);
    }

    public function testCarriageReturnsFromAWindowsTextareaDoNotBecomePartOfAnId(): void
    {
        // A merchant pasting ids from Windows sends \r\n. An id with a trailing \r matches nothing.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'blockedProducts' => "a2a2\r\nb3b3",
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame(['a2a2', 'b3b3'], $config->scope->blockedProductIds);
    }
}
