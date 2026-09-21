<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dto;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalFilterTranslator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * Telling *"do you have the Trail Jersey in blue, size M?"* apart from *"show me jerseys"*.
 *
 * The distinction decides whether a search may withhold anything. Found on staging 2026-09-21: the
 * first question came back as *"a jersey by that exact name was not found"* for a product the shop
 * carries, because its Blue/M is an out-of-stock closeout variant and discovery reads hide those.
 * Hiding is right while a shopper is browsing and wrong the moment they have named the thing.
 */
final class ProductQueryNamesAVariantTest extends TestCase
{
    private function clause(string $field, string $value): FilterClause
    {
        return new FilterClause($field, FilterOperator::Equals, $value);
    }

    public function testASizeOrColourMeansTheShopperNamedTheUnit(): void
    {
        $query = new ProductQuery(term: 'Trail Jersey', filters: [$this->clause('properties.Size', 'M')]);

        self::assertTrue($query->namesAVariant());
    }

    public function testBrowsingWithNoFilterAtAllNamesNothing(): void
    {
        self::assertFalse((new ProductQuery(term: 'jerseys'))->namesAVariant());
    }

    public function testABrandIsNotAUnit(): void
    {
        // "Do you have Shimano brakes" names a maker, not a thing on a shelf. A shopper who has not
        // picked a size is still browsing, and browsing is where hiding the unbuyable belongs.
        $query = new ProductQuery(term: 'brakes', filters: [$this->clause(
            DalFilterTranslator::MANUFACTURER_FIELD,
            'Shimano',
        )]);

        self::assertFalse($query->namesAVariant());
    }

    public function testAPriceBoundIsNotAUnitEither(): void
    {
        $query = new ProductQuery(term: 'jerseys', filters: [
            new FilterClause(DalFilterTranslator::PRICE_FIELD, FilterOperator::Range, ['lte' => 80.0]),
        ]);

        self::assertFalse($query->namesAVariant());
    }

    public function testABrandBesideASizeStillCountsAsNamed(): void
    {
        $query = new ProductQuery(term: 'jersey', filters: [
            $this->clause(DalFilterTranslator::MANUFACTURER_FIELD, 'Shimano'),
            $this->clause('properties.Size', 'M'),
        ]);

        self::assertTrue($query->namesAVariant());
    }
}
