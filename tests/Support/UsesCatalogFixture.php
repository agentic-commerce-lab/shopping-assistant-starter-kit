<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Support;

/**
 * Ruling R38: several tests under Tests\Core\Agent each build a
 * FixtureCommerceGateway from the same fixture file and each hardcoded the
 * relative path to it, so a moved fixture would have broken every one of
 * them independently. Centralised here instead — the path is resolved from
 * this file's own location (`__DIR__` in a trait resolves against the file
 * the trait is defined in, not the class that uses it), so it stays correct
 * regardless of which test class pulls it in.
 */
trait UsesCatalogFixture
{
    private static function catalogFixturePath(): string
    {
        return __DIR__ . '/../Fixtures/catalog.json';
    }
}
