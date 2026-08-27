<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The fashion category tree, duplicated from {@see \Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionTaxonomy}.
 *
 * `tests/` is not autoloaded inside a running Shopware installation, so the seed command cannot use the
 * fixture class directly. {@see FashionSeedTaxonomyParityTest} asserts this file matches it exactly —
 * that test is what makes this duplication safe rather than a second, driftable taxonomy.
 *
 * **The word "wedding" must never appear here** — see the fixture class's docblock; the same trap
 * (`fw-occasion-word`) is what this seeded shop exists to let the real assistant fail or succeed at.
 */
final class FashionSeedTaxonomy
{
    public const DEPARTMENTS = ['Women', 'Men', 'Kids'];

    /** @var list<string> */
    public const GARMENT_TYPES = [
        'Dresses',
        'Tops',
        'Knitwear',
        'Trousers',
        'Skirts',
        'Suits & Tailoring',
        'Outerwear',
        'Shoes',
        'Bags & Accessories',
        'Occasion & Party',
        'Swimwear',
        'Denim',
        'Loungewear',
        'Activewear',
    ];

    /** @var list<string> */
    public const GARMENT_SINGULARS = [
        'Dress',
        'Top',
        'Jumper',
        'Trouser',
        'Skirt',
        'Suit',
        'Coat',
        'Shoe',
        'Bag',
        'Gown',
        'Swimsuit',
        'Jean',
        'Lounge Set',
        'Legging',
    ];

    /** @var list<string> */
    public const GARMENT_PLURALS = [
        'Dresses',
        'Tops',
        'Jumpers',
        'Trousers',
        'Skirts',
        'Suits',
        'Coats',
        'Shoes',
        'Bags',
        'Gowns',
        'Swimsuits',
        'Jeans',
        'Lounge Sets',
        'Leggings',
    ];

    public const CUTS = [
        'Maxi',
        'Midi',
        'Mini',
        'Wrap',
        'Shirt',
        'Slip',
        'Bodycon',
        'A-Line',
        'Shift',
        'Sheath',
        'Tea',
        'Smock',
        'Tiered',
        'Cami',
        'Halter',
        'Pinafore',
        'Knitted',
        'Cropped',
        'Oversized',
        'Tailored',
        'Relaxed',
        'Slim',
    ];

    public const BRANDS = [
        'Aurelia',
        'Bergfeld',
        'Calder',
        'Dunmore',
        'Everlyn',
        'Fairholt',
        'Garrick',
        'Halloway',
        'Ingram',
        'Jarrow',
        'Kestrel',
        'Lorne',
        'Mirrenden',
        'Norbury',
        'Oakfield',
        'Pemberton',
        'Quennell',
        'Radcliffe',
        'Salterton',
        'Thackeray',
        'Underhill',
        'Vansittart',
        'Wexford',
        'Yarrow',
        'Ashcombe',
        'Blackmoor',
        'Cranleigh',
        'Denholm',
        'Elverton',
        'Foxholme',
        'Grantley',
        'Hensford',
        'Ilbury',
        'Jessamy',
        'Kelmscott',
        'Langmere',
        'Marchmont',
        'Netherby',
        'Ostler',
        'Prideaux',
    ];

    public const SEASONS = ['Spring/Summer', 'Autumn/Winter', 'Resort', 'Pre-Fall'];

    public const OCCASIONS = [
        'Party',
        'Evening',
        'Cocktail',
        'Black Tie',
        'Garden Party',
        'Christening',
        'Graduation',
        'Prom',
        'Race Day',
        'Festival',
    ];

    private function __construct() {}

    public static function leafCount(): int
    {
        return \count(self::DEPARTMENTS) * \count(self::GARMENT_TYPES) * \count(self::CUTS);
    }

    public static function sideLeafCount(): int
    {
        return \count(self::BRANDS) + \count(self::SEASONS) + \count(self::OCCASIONS);
    }

    /**
     * The garment-tree leaf at `$index`: department, garment type and cut, deterministically.
     * @return array{path: list<string>, name: string}
     */
    // The two `intdiv()` calls below divide by `\count()` of non-empty class constants (never
    // zero, never overflowing `intdiv()`'s bounds), so `ArithmeticError`/`DivisionByZeroError`
    // can never actually be thrown here. A `@throws` tag would suppress this finding too, but
    // it would also make every caller of `garmentLeaf()` responsible for catching or declaring
    // an exception this method can never really raise — a worse trade than expecting the
    // finding once, at its source.
    // @mago-expect analysis:unhandled-thrown-type
    public static function garmentLeaf(int $index): array
    {
        $perDepartment = \count(self::GARMENT_TYPES) * \count(self::CUTS);
        $department = self::DEPARTMENTS[intdiv($index, $perDepartment) % \count(self::DEPARTMENTS)] ?? 'Women';

        $withinDepartment = $index % $perDepartment;
        $garment = intdiv($withinDepartment, \count(self::CUTS));
        $type = self::GARMENT_TYPES[$garment] ?? 'Dresses';
        $one = self::GARMENT_SINGULARS[$garment] ?? 'Dress';
        $many = self::GARMENT_PLURALS[$garment] ?? 'Dresses';
        $cut = self::CUTS[$withinDepartment % \count(self::CUTS)] ?? 'Maxi';

        return ['path' => [$department, $type, $cut . ' ' . $many], 'name' => $cut . ' ' . $one];
    }

    /**
     * The Brand/Season/Occasion leaf at `$index`, cycling through the three side branches in turn.
     * @return array{path: list<string>, name: string}
     */
    public static function sideLeaf(int $index): array
    {
        $brands = \count(self::BRANDS);
        $seasons = \count(self::SEASONS);

        if ($index < $brands) {
            $brand = self::BRANDS[$index] ?? 'Aurelia';

            return ['path' => ['Brand', $brand], 'name' => $brand . ' Signature Shirt'];
        }

        if ($index < ($brands + $seasons)) {
            $season = self::SEASONS[$index - $brands] ?? 'Resort';

            return ['path' => ['Season', $season], 'name' => $season . ' Edit Blouse'];
        }

        $occasion = self::OCCASIONS[$index - $brands - $seasons] ?? 'Party';

        return ['path' => ['Occasion', $occasion], 'name' => $occasion . ' Edit Jacket'];
    }
}
