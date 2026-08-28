<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Policy;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

final class AssistantConfigTest extends TestCase
{
    public function testCompareProductsIsOffByDefault(): void
    {
        self::assertFalse((new AssistantConfig())->enableCompareProducts);
    }
}
