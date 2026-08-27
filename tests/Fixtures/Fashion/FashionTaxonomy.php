<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

/**
 * The shape of the fashion shop's category tree, and the arithmetic that turns an index into a path.
 *
 * Split from {@see FashionCatalogGenerator} because mago sums cyclomatic complexity across a class's
 * methods against a threshold of ten, and the generator needs its budget for the volume. The split is
 * also the better boundary: this class knows what the shop sells, the generator knows how much of it
 * there is.
 *
 * ## The one thing this file must never contain
 *
 * **The word "wedding", anywhere.** Trap `fw-occasion-word` is the premise of the whole measurement:
 * a shopper says a word that is in no product name, no property value and no category name, and the
 * only route from that word to a product runs through names they never typed. `Occasion & Party` and
 * `Black Tie` are what a real shop calls this; nothing here is named after the occasion itself.
 * `FashionTrapPresenceTest` asserts it over the encoded catalogue rather than trusting this comment.
 */
final class FashionTaxonomy
{
    /** Top-level departments that carry the garment tree. */
    public const DEPARTMENTS = ['Women', 'Men', 'Kids'];

    /**
     * Garment types: the category name, the noun one product of that type is named after, and the
     * plural a sub-category is named after.
     *
     * **Three parallel lists rather than one list of shapes.** Deriving the forms is what produces
     * "Suits & Tailorings" and "Dresss" — a real shop's names are irregular, and that irregularity is
     * this fixture's realism. Parallel flat lists rather than a list of `array{type, one, many}`
     * because mago's analyzer cannot narrow a nested shape through the bounds-safe
     * `$list[$i] ?? $list[0]` accessor, while it narrows a `list<string>` through it cleanly — which is
     * the pattern `LargeCatalogGenerator::noun()` already relies on.
     *
     * {@see FashionTaxonomyTest} asserts the three stay the same length, which is the cost of the
     * parallel form paid in one assertion.
     *
     * @var list<string>
     */
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

    /** What one product of each {@see self::GARMENT_TYPES} entry is called. @var list<string> */
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

    /** What a sub-category of each {@see self::GARMENT_TYPES} entry is called. @var list<string> */
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

    /** Cuts, which name both a sub-category and the product in it. */
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

    /** A `Brand` branch, because a real fashion shop's tree is not only garment-shaped. */
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

    /**
     * The shop's own occasion taxonomy — and the trap.
     *
     * Ten occasions, none of them the one the shopper in this measurement asks about. That omission is
     * deliberate and realistic: a merchant's occasion nodes are the ones they merchandise, and an
     * assistant that can only pattern-match a word fails here even though the shop plainly sells what
     * is being asked for.
     */
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

    /** How many garment leaves the tree has: 3 departments × 14 types × 22 cuts. */
    public static function leafCount(): int
    {
        return \count(self::DEPARTMENTS) * \count(self::GARMENT_TYPES) * \count(self::CUTS);
    }

    /** The `Brand` / `Season` / `Occasion` leaves, which take one product each. */
    public static function sideLeafCount(): int
    {
        return \count(self::BRANDS) + \count(self::SEASONS) + \count(self::OCCASIONS);
    }

    /**
     * One garment leaf, addressed by index rather than by seeded choice.
     *
     * **Deterministic distribution, not a random one.** Assigning products to leaves with the seeded
     * sequence leaves some leaves empty, and a leaf with no products does not exist in this fixture at
     * all — the tree is derived from the products in it. The node count would then depend on the seed
     * in a way that looks like a bug in whoever reads the constant. Modulo gives every leaf three or
     * four products and makes the count exact.
     *
     * @return array{path: list<string>, name: string}
     */
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

        return [
            'path' => [$department, $type, $cut . ' ' . $many],
            'name' => $cut . ' ' . $one,
        ];
    }

    /**
     * One `Brand` / `Season` / `Occasion` leaf, in that order, for `$index` below
     * {@see self::sideLeafCount()}.
     *
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
