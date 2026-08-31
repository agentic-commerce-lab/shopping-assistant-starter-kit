<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * The demo shop's catalogue, written out rather than generated.
 *
 * **Why not the fashion seeder.** `SeedFashionCatalogueCommand` builds ~15,200 units by combining a
 * taxonomy with adjective lists, which is exactly right for what it was built for — measuring
 * retrieval at scale, where the names only have to be distinct. It is exactly wrong here: this shop
 * is a bicycle shop with five invented brands and a hand-built taxonomy, and filling it with
 * "Bodycon Bag 3316" would make every manual test a test of a shop nobody would ever run.
 *
 * So every product below is one somebody could plausibly buy here, priced in the range the existing
 * catalogue already occupies (8–120 EUR for parts and apparel, up to ~650 for a wheelset), attached
 * to the brand that already carries that kind of product:
 *
 * | Brand | Carries |
 * |---|---|
 * | Ferrolane | metal and mechanical parts — brakes, drivetrain, cockpit, tools |
 * | Kestrel Works | apparel, helmets, saddles |
 * | Northbound Supply | bags, bottles, cages, locks, racks |
 * | Halyard Tyres | tyres, tubes, tubeless |
 * | Cinder & Co. | lubricants and cleaning |
 *
 * **It attaches to the shop, it does not rebuild it.** Categories are declared as `Parent/Child`
 * where the parent is a top-level category the shop already has ({@see self::EXISTING_PARENTS}), and
 * variant axes reuse the shop's own `Colour`, `Size` and `Brake system` groups wherever the value
 * already exists. That matters beyond tidiness: the assistant's `CatalogVocabulary` puts the shop's
 * facet values in front of the model, so a seed that invented a parallel `Color` group would teach it
 * two spellings for one thing.
 *
 * **Stock is deliberately uneven, zeroes included.** Ruling R75 and the `variant_stock` journey are
 * about what the assistant says when something is sold out; a catalogue where everything is available
 * exercises none of it.
 *
 * @phpstan-import-type ProductSpec from BikeProducts
 */
final class BikeCatalogue
{
    /** Keeps every seeded product number clear of the shop's own `fx-*` and `sk-*` ranges. */
    public const NUMBER_PREFIX = 'bk-';

    /**
     * Top-level categories this shop already has, and the only places a seeded category may attach.
     *
     * @var list<string>
     */
    public const EXISTING_PARENTS = [
        'Accessories',
        'Apparel',
        'Brakes',
        'Components',
        'Maintenance',
        'Tyres',
    ];

    /**
     * Categories the shop already has that a seeded product may be filed under.
     *
     * `Restricted` and `Merch` are deliberately absent. `Restricted` is this shop's blocklist
     * demonstration — a product seeded into it would be excluded from every search, which reads as a
     * retrieval bug rather than as the seeding mistake it is — and `Merch` is the branded-goods
     * shelf, which a chain lube does not belong on.
     *
     * @var list<string>
     */
    public const EXISTING_CATEGORIES = [
        'Accessories',
        'Apparel',
        'Bags',
        'Base Layers',
        'Bottles',
        'Brakes',
        'Cages',
        'Components',
        'Gloves',
        'Grips',
        'Helmets',
        'Jerseys',
        'Lights',
        'Maintenance',
        'Mudguards',
        'Saddles',
        'Seatposts',
        'Shorts',
        'Tyres',
    ];

    /**
     * @var list<string>
     */
    public const EXISTING_MANUFACTURERS = [
        'Ferrolane',
        'Kestrel Works',
        'Northbound Supply',
        'Halyard Tyres',
        'Cinder & Co.',
    ];

    private function __construct() {}

    /**
     * New categories, as `Parent/Child` => `Parent`.
     *
     * Only children are seeded: the parents exist, and re-creating one would leave the shop with two
     * "Apparel" nodes and a navigation nobody can explain.
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            'Components/Drivetrain' => 'Components',
            'Components/Cockpit' => 'Components',
            'Components/Wheels' => 'Components',
            'Apparel/Jackets' => 'Apparel',
            'Apparel/Socks' => 'Apparel',
            'Apparel/Overshoes' => 'Apparel',
            'Accessories/Locks' => 'Accessories',
            'Accessories/Computers & Mounts' => 'Accessories',
            'Accessories/Racks' => 'Accessories',
            'Maintenance/Tools' => 'Maintenance',
            'Maintenance/Lubricants & Cleaners' => 'Maintenance',
            'Tyres/Tubes' => 'Tyres',
            'Brakes/Pads' => 'Brakes',
            'Brakes/Rotors' => 'Brakes',
        ];
    }

    /**
     * Every property group this seed writes options into — the axes products vary by, and the
     * attributes they are described with.
     *
     * One map because both kinds are the same thing to the shop and to the seeder: a property group
     * with options, resolved against what the shop already has by
     * {@see BikeSeedContext::optionIds()} and written by {@see BikeTaxonomyPlan::propertyGroups()}.
     * The two halves are declared separately because they are read by different questions — see
     * {@see self::variantGroups()} and {@see self::descriptiveGroups()}.
     *
     * @return array<string, list<string>>
     */
    public static function propertyGroups(): array
    {
        return [...self::variantGroups(), ...self::descriptiveGroups()];
    }

    /**
     * Every property group a seeded product **varies by**, with every value it may use.
     *
     * `Colour`, `Size` and `Brake system` already exist in this shop; the values listed for them are
     * a superset of what is there, and the plan builder adds only the missing ones to the existing
     * group rather than creating a second one. `Rotor size`, `Diameter` and `Speed` are new — each
     * exists because a real component varies by it, and none is a synonym of a group already present.
     *
     * @return array<string, list<string>>
     */
    public static function variantGroups(): array
    {
        return [
            'Colour' => ['Black', 'Blue', 'Grey', 'Olive', 'Red', 'Tan', 'White'],
            'Size' => ['S', 'M', 'L', 'XL', 'XXL', '650x47', '700x25', '700x28', '700x32', '700x40'],
            'Brake system' => ['Disc', 'Rim'],
            'Rotor size' => ['140 mm', '160 mm', '180 mm'],
            'Diameter' => ['27.2 mm', '30.9 mm', '31.6 mm'],
            'Speed' => ['10-speed', '11-speed', '12-speed'],
        ];
    }

    /**
     * Every property group a seeded product is **described by**, rather than varies by.
     *
     * **This is what the assistant can actually compare with.**
     * {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary} hands the model a product's
     * `properties` and deliberately withholds its `description`, so an attribute that exists only in
     * the prose is an attribute the assistant cannot reason about. Before these groups existed this
     * catalogue declared variant axes only, and the demo shop could not answer *"what is the
     * difference between long finger gloves and winter gloves"* — the two products differed in their
     * descriptions and were identical in everything the model was shown.
     *
     * **Six groups, deliberately, and none of them a synonym of another.** Every group here becomes a
     * facet in the merchant's storefront and a line in the model's catalogue vocabulary
     * ({@see \Swag\AssistantStarterKit\Core\Prompt\CatalogVocabularyBudget}), so inventing a
     * seventh overlapping one costs prompt budget and teaches the model two words for one property.
     * `Season` and `Weather protection` are kept apart because they answer different questions: a
     * windproof glove is not thereby a winter glove, and that distinction is the one the gloves case
     * turns on.
     *
     * A group may offer more values than any single product uses;
     * {@see \Swag\AssistantStarterKit\Core\Tool\BoundedProperties} caps the *product*, not the
     * group.
     *
     * @return array<string, list<string>>
     */
    public static function descriptiveGroups(): array
    {
        return [
            'Season' => ['Winter', 'Shoulder season', 'Summer', 'All-season'],
            'Insulation' => ['Insulated', 'Uninsulated'],
            'Weather protection' => ['Waterproof', 'Water-repellent', 'Windproof', 'Breathable'],
            'Material' => [
                'Alloy',
                'Carbon',
                'Cork',
                'Gel',
                'Leather',
                'Merino',
                'Neoprene',
                'Nylon',
                'Plastic',
                'Polyester',
                'Polystyrene',
                'Rubber',
                'Stainless steel',
                'Steel',
            ],
            'Terrain' => ['Road', 'Gravel', 'Trail', 'Commuting'],
            'Mounting' => ['Bottle bosses', 'Handlebar', 'Stem', 'Seatpost', 'Frame', 'Rear rack', 'Fork'],
        ];
    }

    /**
     * The catalogue itself, held in {@see BikeProducts}.
     *
     * Delegated rather than inlined: the product list is eighty-five entries of data and the rest of
     * this class is the six-line taxonomy they are declared against. Keeping both here put the class
     * over mago's method budget, and the split is the right one anyway — the taxonomy is what a
     * reviewer checks against the shop, the products are what they read for plausibility.
     *
     * @return list<ProductSpec>
     */
    public static function products(): array
    {
        return BikeProducts::all();
    }
}
