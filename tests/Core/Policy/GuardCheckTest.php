<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Policy;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\GuardCheck;
use Swag\AssistantStarterKit\Core\Policy\PolicyVerdict;

/**
 * The daily cap used to be asserted here, against a `$requestsToday` argument no caller supplied.
 * Those two tests passed for years while the cap enforced nothing — a reminder that a green test
 * proves the unit works, not that anything calls it. The cap is now
 * {@see \Swag\AssistantStarterKit\Core\Policy\RequestBudget}'s, and asserted through the endpoint
 * that consumes it.
 */
final class GuardCheckTest extends TestCase
{
    public function testBlocksWhenKillSwitchIsOn(): void
    {
        $decision = (new GuardCheck())->check(new AssistantConfig(killSwitch: true));

        self::assertSame(PolicyVerdict::Block, $decision->verdict);
        self::assertSame('kill_switch', $decision->reasonCode);
    }

    public function testAllowsWhenTheKillSwitchIsOff(): void
    {
        $decision = (new GuardCheck())->check(new AssistantConfig());

        self::assertSame(PolicyVerdict::Allow, $decision->verdict);
    }
}
