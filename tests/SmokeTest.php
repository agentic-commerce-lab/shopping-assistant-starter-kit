<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Version;

final class SmokeTest extends TestCase
{
    public function testVersionIsExposed(): void
    {
        self::assertSame('0.2.0', Version::CURRENT);
    }
}
