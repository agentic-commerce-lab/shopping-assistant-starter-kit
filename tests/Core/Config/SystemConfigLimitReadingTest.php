<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;

/**
 * One rule, and its single exception: **`0` means unlimited**.
 *
 * It used to mean "refuse everything" on the two rate windows, which made zero the most destructive
 * value a merchant could put in a numeric field — reachable by clearing a box, described in the form
 * as a limit, and redundant with an off switch three cards above it that at least records a reason
 * in the trace. What zero could not express, and now does, is the thing a merchant actually wants
 * from a ceiling they never asked for.
 *
 * Split from {@see SystemConfigAssistantConfigTest} because these assertions are about a reading
 * rule rather than about settings reaching the pipeline, and because a class carrying both was
 * large enough that the lint gate said so.
 */
final class SystemConfigLimitReadingTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    public function testAnUnsetLimitIsUnlimitedRatherThanAGuessedNumber(): void
    {
        // Three of these shipped with figures — 5 items, 1000 in cart value, 500 requests a day —
        // that were guesses in an unspecified currency against an unknown catalogue. The daily cap
        // was the expensive one: a good day's traffic turned the assistant off by mid-afternoon,
        // with nothing in the interface saying why.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);

        self::assertSame(0, $config->maxItemQuantity);
        self::assertSame(0.0, $config->maxCartValue);
        self::assertSame(0, $config->dailyRequestCap);
    }

    public function testThePerShopperRateLimitStaysOnByDefault(): void
    {
        // The one limit that ships enabled. The chat endpoint is public and unauthenticated, and
        // every call spends model credit — this is what stands between a shop and a scripted loop,
        // so its default cannot be "no limit" the way the spend ceiling's can. 60 a minute is about
        // twenty times what a person typing produces, so it costs a real shopper nothing.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);

        self::assertSame(60, $config->requestsPerMinute);
    }

    public function testTheToolCallLimitCannotBeSwitchedOff(): void
    {
        // The exception to "0 means unlimited", in the one place where unlimited is not a merchant
        // preference: it bounds a model that has started looping. Zero would mean no tool may ever
        // run, which is an assistant that loads and then answers everything ungrounded.
        $absent = (new SystemConfigAssistantConfig(new FakeSystemConfigService()))->forSalesChannel(self::CHANNEL);
        self::assertSame(20, $absent->maxToolCallsPerTurn);

        $zeroed = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'maxToolCallsPerTurn' => 0,
        ])))->forSalesChannel(self::CHANNEL);
        self::assertSame(20, $zeroed->maxToolCallsPerTurn);
    }

    public function testATypedToolCallLimitIsHonouredExactlyEvenWhenItIsLow(): void
    {
        // The floor applies to absent and zero, never to a number a merchant typed. Quietly raising
        // a deliberate 3 to 20 would be the config bridge overruling the form.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'maxToolCallsPerTurn' => 3,
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame(3, $config->maxToolCallsPerTurn);
    }

    public function testAStoredZeroLimitIsHonouredAsUnlimited(): void
    {
        // A merchant clearing a limit they had set must land back on unlimited, not on a default.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'dailyRequestCap' => 0,
            self::PREFIX . 'maxItemQuantity' => 0,
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame(0, $config->dailyRequestCap);
        self::assertFalse($config->hasItemQuantityLimit());
    }

    public function testANegativeLimitIsFoldedIntoUnlimitedRatherThanBlockingEverything(): void
    {
        // There is no reading of "-5 items per cart" a merchant meant as a restriction, and letting
        // it through makes every `> $limit` comparison downstream reject unconditionally.
        $config = (new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'maxItemQuantity' => -5,
            self::PREFIX . 'maxCartValue' => -1.0,
        ])))->forSalesChannel(self::CHANNEL);

        self::assertFalse($config->hasItemQuantityLimit());
        self::assertFalse($config->hasCartValueLimit());
    }
}
