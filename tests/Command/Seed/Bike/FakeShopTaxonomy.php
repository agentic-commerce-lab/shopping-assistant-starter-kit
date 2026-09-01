<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use Swag\AssistantStarterKit\Command\Seed\Bike\BikeCatalogue;
use Swag\AssistantStarterKit\Command\Seed\Bike\ShopTaxonomy;
use Swag\AssistantStarterKit\Command\Seed\SeedTax;

/**
 * A shop that has everything {@see BikeCatalogue} expects of it.
 *
 * A named helper rather than a private method on each test class: building it is three nested loops,
 * and carrying those in a `TestCase` pushed both test classes over mago's per-class complexity
 * budget — which is the linter correctly reporting that the fixture is not a test.
 *
 * The ids are derived from the names rather than random, so a failure message names something a
 * reader can trace back to the input that produced it.
 */
final class FakeShopTaxonomy
{
    public const TAX_ID = 'tax0000000000000000000000000000';

    /** The rate that goes with {@see self::TAX_ID}: seeded prices are gross, so the net derives from it. */
    public const TAX_RATE = 19.0;

    public static function tax(): SeedTax
    {
        return new SeedTax(self::TAX_ID, self::TAX_RATE);
    }

    public const SALES_CHANNEL_ID = 'saleschannel00000000000000000000';

    private function __construct() {}

    /** Everything resolves: every category, every option value, every brand. */
    public static function complete(): ShopTaxonomy
    {
        return new ShopTaxonomy(
            self::ids(BikeCatalogue::EXISTING_CATEGORIES),
            ['Colour' => self::id('group-colour'), 'Size' => self::id('group-size')],
            self::options(),
            self::ids(BikeCatalogue::EXISTING_MANUFACTURERS),
            self::tax(),
            self::existingProductIds(),
        );
    }

    /** A shop that has the taxonomy but none of the products the enrichment list names. */
    public static function withoutExistingProducts(): ShopTaxonomy
    {
        return new ShopTaxonomy(
            self::ids(BikeCatalogue::EXISTING_CATEGORIES),
            ['Colour' => self::id('group-colour'), 'Size' => self::id('group-size')],
            self::options(),
            self::ids(BikeCatalogue::EXISTING_MANUFACTURERS),
            self::tax(),
            [],
        );
    }

    /** The id this fake shop gives one of its own products, so a test can look a payload up by it. */
    public static function productId(string $number): string
    {
        return self::id('product-' . $number);
    }

    /** @return array<string, string> */
    private static function existingProductIds(): array
    {
        $ids = [];

        foreach (array_keys(BikeCatalogue::existingProductProperties()) as $number) {
            $ids[(string) $number] = self::productId((string) $number);
        }

        return $ids;
    }

    /** A shop the catalogue was not written against: nothing resolves at all. */
    public static function empty(): ShopTaxonomy
    {
        return new ShopTaxonomy([], [], [], [], self::tax());
    }

    /** @return array<string, array<string, string>> */
    private static function options(): array
    {
        $options = [];

        foreach (BikeCatalogue::propertyGroups() as $group => $values) {
            foreach ($values as $value) {
                $options[$group][$value] = self::id($group . '-' . $value);
            }
        }

        return $options;
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    private static function ids(array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $ids[$name] = self::id($name);
        }

        return $ids;
    }

    private static function id(string $seed): string
    {
        return substr(md5($seed), 0, 32);
    }
}
