<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;

/**
 * Until this class exists, `config.xml` is a form that changes nothing — including the off switch,
 * which `ARCHITECTURE.md` calls a security control rather than an operational nicety. So these
 * assertions are about a setting reaching the pipeline at all, and about what happens when one is
 * absent.
 *
 * **Absent no longer means "the shipped number".** For the four limits it means *unlimited*, which
 * is the reading a merchant who never opened the form should get: an assistant that works, rather
 * than one throttled by figures nobody chose. The one exception is `maxToolCallsPerTurn`, and the
 * tests below pin the difference in both directions.
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
            self::PREFIX . 'assistantEnabled' => false,
            self::PREFIX . 'dailyRequestCap' => 50,
            self::PREFIX . 'maxToolCallsPerTurn' => 3,
            self::PREFIX . 'requestsPerMinute' => 2,
        ]));

        $config = $factory->forSalesChannel(self::CHANNEL);

        self::assertSame('Be concise.', $config->agentVoice);
        self::assertFalse($config->enableAddToCart);
        self::assertSame(2, $config->maxItemQuantity);
        self::assertSame(250.0, $config->maxCartValue);
        self::assertFalse($config->assistantEnabled);
        self::assertSame(50, $config->dailyRequestCap);
        self::assertSame(3, $config->maxToolCallsPerTurn);
        self::assertSame(2, $config->requestsPerMinute);
    }

    public function testAddToCartAndTheAssistantItselfBothDefaultToOn(): void
    {
        // Neither can use "absent means default" the way the ints do, because false is a legitimate
        // stored value: `getBool()` returns false for an absent key *and* for a stored false, so
        // both are read through the raw get() to tell them apart. It matters more for
        // `assistantEnabled` than it ever did for the switch it replaced — the old `killSwitch`
        // read false either way, which happened to be the safe direction. This one does not have
        // that luxury: read it wrong and a shop that never opened the form ships with a dead
        // assistant.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);

        self::assertTrue($config->enableAddToCart);
        self::assertTrue($config->assistantEnabled);
    }

    public function testAStoredFalseForTheAssistantItselfSurvives(): void
    {
        // The direction the migration exists to protect: a merchant who stopped the assistant must
        // stay stopped.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'assistantEnabled' => false,
        ])))->forSalesChannel(self::CHANNEL);

        self::assertFalse($config->assistantEnabled);
    }

    public function testLoggingDefaultsToOn(): void
    {
        // It writes no shopper text, and a merchant who has to discover a logging switch before
        // they can debug a failing assistant has already had the bad afternoon it would prevent.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);

        self::assertTrue($config->logTraces);
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

    public function testBothIdListsLandInTheirOwnScopeField(): void
    {
        // Swapping these would hide a whole branch where one product was meant to go, or the
        // reverse — and both failures are invisible without reading a trace.
        //
        // There used to be a third list, `excludedCategories`, and this test asserted it landed
        // somewhere of its own. It did, and then `DalCriteriaBuilder` concatenated it straight back
        // together with the blocked one before filtering — so the assertion was true about the
        // plumbing and meaningless about the behaviour.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'blockedProducts' => 'prod-1',
            self::PREFIX . 'blockedCategories' => 'cat-1',
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame(['prod-1'], $config->scope->blockedProductIds);
        self::assertSame(['cat-1'], $config->scope->blockedCategoryIds);
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
