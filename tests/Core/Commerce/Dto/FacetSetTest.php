<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dto;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;

final class FacetSetTest extends TestCase
{
    public function testHasReportsKnownAndUnknownFields(): void
    {
        $set = new FacetSet([
            new Facet('price', FacetType::Range, min: 9.90, max: 199.00),
            new Facet('properties.Colour', FacetType::Terms, values: ['Blue', 'Black']),
        ]);

        self::assertTrue($set->has('price'));
        self::assertTrue($set->has('properties.Colour'));
        self::assertFalse($set->has('properties.Fabric'));
        self::assertSame(['price', 'properties.Colour'], $set->fields());
    }
}
