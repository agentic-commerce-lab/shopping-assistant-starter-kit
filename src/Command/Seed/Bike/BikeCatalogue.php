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
     * **A material that is true of an entire product type is left out rather than recorded.** Every
     * cycling helmet is EPS and every tyre is rubber, so `Polystyrene` and `Rubber` could not
     * discriminate anywhere — and a live answer proved what that costs: asked for a helmet, the
     * assistant told the shopper three of five candidates were "made of polystyrene". True, identical,
     * and useless for choosing.
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
                'Cotton',
                'Gel',
                'Leather',
                'Merino',
                'Neoprene',
                'Nylon',
                'Plastic',
                'Polyester',
                'Stainless steel',
                'Steel',
            ],
            'Terrain' => ['Road', 'Gravel', 'Trail', 'Commuting'],
            'Mounting' => ['Bottle bosses', 'Handlebar', 'Stem', 'Seatpost', 'Frame', 'Rear rack', 'Fork'],
        ];
    }

    /**
     * Descriptive properties for products **this shop already had**, keyed by product number.
     *
     * ## Why the seeder reaches outside its own catalogue
     *
     * It gave 85 `bk-*` products properties and left the shop's own 16 `sk-*` products with none,
     * which made the catalogue two-class in a way that surfaces directly in answers. Asked for a
     * helmet, the assistant described three `bk-*` helmets by season, terrain and breathability and
     * two `sk-*` ones as *"available in White"* — the shopper cannot tell that the difference is in
     * the data rather than in the products.
     *
     * The sharper case: `sk-101 Trail Helmet` is the most trail-specific helmet in the shop — extended
     * rear shell, adjustable visor, 22 vents — and had **no properties at all**. Any narrowing on
     * `Terrain=Trail` would have dropped exactly the right answer. That is the argument against
     * filtering on attributes in a catalogue whose attributes are incomplete, and the argument for
     * completing them.
     *
     * ## Why `fx-*` is not here
     *
     * Those mirror `tests/Fixtures/catalog.json` verbatim — including the prompt-injection description
     * on `fx-017` and the deliberate mis-categorisation of `fx-021` (see
     * `docs/demo-catalog/README.md`). They are test material, and enriching them would destroy what
     * they test. `bk-*` is absent for a different reason: those carry their properties in
     * {@see BikeProducts}, and a second source would let the two disagree.
     *
     * ## What these are derived from
     *
     * Each product's own description, which is where every one of these facts already was. Nothing is
     * invented: `sk-111`'s "warm when it is cold, breathable when it is not" is why it is Insulated
     * and Breathable, and `sk-105`'s "fully waterproof roll-top" is why it is Waterproof.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function existingProductProperties(): array
    {
        return [
            // Helmets — the pair the gloves-and-helmet answer turned on.
            'sk-101' => [
                'Season' => ['All-season'],
                'Weather protection' => ['Breathable'],
                'Terrain' => ['Trail', 'Gravel'],
            ],
            'sk-102' => [
                'Season' => ['Winter', 'All-season'],
                'Weather protection' => ['Waterproof'],
                'Terrain' => ['Commuting'],
            ],

            // Lights — both weatherproof, distinguished by where they mount.
            'sk-103' => [
                'Season' => ['All-season'],
                'Weather protection' => ['Waterproof'],
                'Mounting' => ['Handlebar'],
            ],
            'sk-104' => [
                'Season' => ['All-season'],
                'Weather protection' => ['Waterproof'],
                'Mounting' => ['Seatpost'],
            ],

            // Bag — "fully waterproof roll-top" is the description's own claim.
            'sk-105' => [
                'Weather protection' => ['Waterproof'],
                'Material' => ['Nylon'],
                'Terrain' => ['Gravel', 'Commuting'],
                'Mounting' => ['Handlebar'],
            ],

            // Tools — material is all a tool honestly offers here.
            'sk-107' => ['Material' => ['Steel']],
            'sk-108' => ['Material' => ['Alloy'], 'Mounting' => ['Frame']],

            // Sealant — a tubeless consumable, so terrain rather than material.
            'sk-110' => ['Season' => ['All-season'], 'Terrain' => ['Gravel', 'Road']],

            // Apparel — the layer that is warm and the layer that is not.
            'sk-111' => [
                'Season' => ['All-season'],
                'Insulation' => ['Insulated'],
                'Weather protection' => ['Breathable'],
                'Material' => ['Merino'],
            ],
            'sk-112' => [
                'Season' => ['Summer'],
                'Insulation' => ['Uninsulated'],
                'Weather protection' => ['Breathable'],
                'Material' => ['Polyester'],
            ],
            'sk-121' => ['Season' => ['Summer'], 'Material' => ['Cotton']],

            // Contact points and components.
            'sk-114' => ['Mounting' => ['Handlebar'], 'Terrain' => ['Trail', 'Gravel']],
            'sk-115' => ['Material' => ['Carbon'], 'Mounting' => ['Frame'], 'Terrain' => ['Road', 'Gravel']],
            'sk-116' => ['Material' => ['Steel'], 'Mounting' => ['Seatpost'], 'Terrain' => ['Gravel']],
            'sk-117' => ['Season' => ['Summer'], 'Terrain' => ['Road']],
            'sk-119' => ['Material' => ['Alloy'], 'Terrain' => ['Road', 'Gravel']],
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
