<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Grounding\PropertyClaimExtractor;

final class PropertyClaimExtractorTest extends TestCase
{
    private function facets(): FacetSet
    {
        return new FacetSet([
            new Facet('properties.Material', FacetType::Terms, ['Merino', 'Nylon']),
            new Facet('properties.Colour', FacetType::Terms, ['Blue', 'Black']),
            // Not a property — must never be scanned. Real value taken from the fashion catalogue
            // measurement above, so this test fails honestly if the exclusion is ever dropped.
            new Facet('categoryPath', FacetType::Terms, ['Dresses']),
        ]);
    }

    public function testFindsAKnownValueCaseInsensitively(): void
    {
        $found = (new PropertyClaimExtractor())->extract('It is made of merino wool.', $this->facets());

        self::assertSame(['Merino'], $found);
    }

    public function testFindsNothingWhenNoKnownValueAppears(): void
    {
        $found = (new PropertyClaimExtractor())->extract('It is a great jersey.', $this->facets());

        self::assertSame([], $found);
    }

    public function testDoesNotMatchAValueInsideALongerWord(): void
    {
        // "Nylon" must not match inside "Nylontex", a hypothetical brand word.
        $found = (new PropertyClaimExtractor())->extract('Made by Nylontex.', $this->facets());

        self::assertSame([], $found);
    }

    public function testDeduplicatesRepeatedMentions(): void
    {
        $found = (new PropertyClaimExtractor())->extract('Merino, Merino everywhere.', $this->facets());

        self::assertSame(['Merino'], $found);
    }

    public function testNeverScansACategoryPathFacet(): void
    {
        // "Dresses" is a real categoryPath value in this fixture set, and appears in the prose —
        // it must not be extracted as a property claim, since it is not one.
        $found = (new PropertyClaimExtractor())->extract('We have lovely Dresses in stock.', $this->facets());

        self::assertSame([], $found);
    }
}
