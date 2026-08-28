<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\Warnings;

final class WarningsTest extends TestCase
{
    public function testDefaultsToNoWarnings(): void
    {
        $warnings = new Warnings();

        self::assertSame([], $warnings->unbackedPrices);
        self::assertSame([], $warnings->unbackedAvailabilityClaims);
        self::assertSame([], $warnings->unbackedPropertyClaims);
    }

    public function testCarriesEachClaimTypeIndependently(): void
    {
        $warnings = new Warnings(
            unbackedPrices: ['12.90'],
            unbackedAvailabilityClaims: ['is available'],
            unbackedPropertyClaims: ['Merino'],
        );

        self::assertSame(['12.90'], $warnings->unbackedPrices);
        self::assertSame(['is available'], $warnings->unbackedAvailabilityClaims);
        self::assertSame(['Merino'], $warnings->unbackedPropertyClaims);
    }
}
