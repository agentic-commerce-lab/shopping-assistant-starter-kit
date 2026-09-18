<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

/**
 * Off by default, like every other capability — and this one more deliberately than the rest.
 *
 * `enableAddToCart`, `enableCompareProducts` and `enableEscalation` all gate something the assistant
 * does with the CATALOGUE. This is the first that gates something it reads about the SHOPPER, so a
 * plugin update that silently flipped it on would start handing order data to a model in shops that
 * never asked for it. The default is asserted rather than assumed for that reason.
 */
final class OrderHistoryToggleTest extends TestCase
{
    public function testDefaultsToOff(): void
    {
        self::assertFalse((new AssistantConfig())->enableOrderHistory);
    }

    public function testCanBeSwitchedOn(): void
    {
        self::assertTrue((new AssistantConfig(enableOrderHistory: true))->enableOrderHistory);
    }
}
