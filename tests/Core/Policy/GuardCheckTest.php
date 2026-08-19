<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Policy;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\GuardCheck;
use Swag\AssistantStarterKit\Core\Policy\PolicyVerdict;

final class GuardCheckTest extends TestCase
{
    public function testBlocksWhenKillSwitchIsOn(): void
    {
        $decision = (new GuardCheck())->check(new AssistantConfig(killSwitch: true), 0);

        self::assertSame(PolicyVerdict::Block, $decision->verdict);
        self::assertSame('kill_switch', $decision->reasonCode);
    }

    public function testBlocksWhenTheDailyCapIsReached(): void
    {
        $decision = (new GuardCheck())->check(new AssistantConfig(dailyRequestCap: 100), 100);

        self::assertSame(PolicyVerdict::Block, $decision->verdict);
        self::assertSame('daily_cap', $decision->reasonCode);
    }

    public function testAllowsBelowTheCap(): void
    {
        $decision = (new GuardCheck())->check(new AssistantConfig(dailyRequestCap: 100), 99);

        self::assertSame(PolicyVerdict::Allow, $decision->verdict);
    }
}
