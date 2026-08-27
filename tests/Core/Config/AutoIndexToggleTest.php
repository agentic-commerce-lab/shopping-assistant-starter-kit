<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;

/**
 * The one setting in this plugin that spends money when it is on.
 *
 * `autoIndexShopPages` re-indexes a legal page whenever a merchant edits it, which is one embedding
 * call per change. Both assertions here are about it failing safe: off unless asked for, and not
 * switched on by the string "false" — the same measured failure the kill switch had, except this one
 * would spend money rather than open a guardrail.
 *
 * Split from {@see SystemConfigAssistantConfigTest} because Mago bounds methods per class.
 */
final class AutoIndexToggleTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    public function testAutomaticPageIndexingIsOffUnlessTheMerchantAsksForIt(): void
    {
        // Off by default, and the default is the decision: every content change would otherwise spend
        // embedding calls the merchant never asked for. A shop that edits its terms in a busy week
        // would find that out on an invoice.
        self::assertFalse((new SystemConfigAssistantConfig(
            new FakeSystemConfigService(),
        ))->forSalesChannel(self::CHANNEL)->autoIndexShopPages);

        self::assertTrue((new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'autoIndexShopPages' => true,
        ])))->forSalesChannel(self::CHANNEL)->autoIndexShopPages);
    }

    /**
     * A toggle switched off from the CLI arrives as the string "false", and `(bool) "false"` is true.
     *
     * The same measured failure the kill switch had: this one would spend money rather than open a
     * guardrail, which is why it is read through the same helper and not a cast.
     */
    public function testAStringFalseFromTheConsoleDoesNotSwitchIndexingOn(): void
    {
        self::assertFalse((new SystemConfigAssistantConfig(new FakeSystemConfigService([
            self::PREFIX . 'autoIndexShopPages' => 'false',
        ])))->forSalesChannel(self::CHANNEL)->autoIndexShopPages);
    }
}
