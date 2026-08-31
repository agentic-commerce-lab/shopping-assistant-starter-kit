<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * Everything worn: jackets, jerseys, shorts, base layers, socks, overshoes, gloves and helmets. All Kestrel Works, the brand this shop puts its apparel under.
 *
 * One of four product files. Split because the project caps a source file at 400 lines — a rule this
 * data would otherwise quietly break, and one worth keeping here: the four shelves are reviewed by
 * different questions, and somebody checking whether the tyre range makes sense should not have to
 * scroll past the socks.
 *
 * @phpstan-import-type ProductSpec from BikeProducts
 */
final class BikeWear
{
    private function __construct() {}

    /** @return list<ProductSpec> */
    public static function all(): array
    {
        return [
            // Kestrel Works — Apparel/Jackets
            [
                'number' => 'bk-jacket-gravel',
                'name' => 'Gravel Jacket',
                'description' => 'A wind- and shower-proof shell cut for a riding position, with a dropped tail and a zipped rear pocket.',
                'price' => 149.0,
                'stock' => 7,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Apparel/Jackets',
                'properties' => [
                    'Season' => ['Shoulder season'],
                    'Insulation' => ['Uninsulated'],
                    'Weather protection' => ['Windproof', 'Water-repellent'],
                    'Material' => ['Nylon'],
                    'Terrain' => ['Gravel'],
                ],
                'variants' => ['Size' => ['S', 'M', 'L', 'XL'], 'Colour' => ['Black', 'Olive']],
            ],
            [
                'number' => 'bk-jacket-rain-packable',
                'name' => 'Packable Rain Jacket',
                'description' => 'Folds into its own pocket and weighs little enough to carry on days it may not be needed.',
                'price' => 99.0,
                'stock' => 4,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Apparel/Jackets',
                'properties' => [
                    'Season' => ['All-season'],
                    'Insulation' => ['Uninsulated'],
                    'Weather protection' => ['Waterproof'],
                    'Material' => ['Nylon'],
                ],
                'variants' => ['Size' => ['S', 'M', 'L', 'XL'], 'Colour' => ['Black', 'Blue']],
            ],
            [
                'number' => 'bk-jacket-windbreaker',
                'name' => 'Lightweight Windbreaker',
                'description' => 'An unlined windproof layer for cool starts and warm afternoons.',
                'price' => 79.0,
                'stock' => 0,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Apparel/Jackets',
                'properties' => [
                    'Season' => ['Shoulder season', 'Summer'],
                    'Insulation' => ['Uninsulated'],
                    'Weather protection' => ['Windproof'],
                    'Material' => ['Nylon'],
                ],
                'variants' => ['Size' => ['M', 'L', 'XL'], 'Colour' => ['Black', 'White']],
            ],

            // Kestrel Works — Jerseys
            [
                'number' => 'bk-jersey-thermal-ls',
                'name' => 'Thermal Jersey Long Sleeve',
                'description' => 'A brushed-back long sleeve jersey with three rear pockets and a zipped valuables pocket.',
                'price' => 69.0,
                'stock' => 9,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Jerseys',
                'properties' => [
                    'Season' => ['Winter', 'Shoulder season'],
                    'Insulation' => ['Insulated'],
                    'Weather protection' => ['Breathable'],
                    'Material' => ['Polyester'],
                ],
                'variants' => ['Size' => ['S', 'M', 'L', 'XL'], 'Colour' => ['Black', 'Blue', 'Olive']],
            ],
            [
                'number' => 'bk-jersey-club',
                'name' => 'Club Jersey',
                'description' => 'A regular-fit short sleeve jersey in the club colours, with a full-length zip.',
                'price' => 59.0,
                'stock' => 12,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Jerseys',
                'properties' => [
                    'Season' => ['Summer'],
                    'Insulation' => ['Uninsulated'],
                    'Weather protection' => ['Breathable'],
                    'Material' => ['Polyester'],
                ],
                'variants' => ['Size' => ['S', 'M', 'L', 'XL', 'XXL'], 'Colour' => ['Blue', 'Red', 'White']],
            ],

            // Kestrel Works — Shorts
            [
                'number' => 'bk-bib-tights',
                'name' => 'Bib Tights',
                'description' => 'Full-length thermal bibs with a seat pad rated for long winter rides.',
                'price' => 109.0,
                'stock' => 5,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Shorts',
                'properties' => [
                    'Season' => ['Winter'],
                    'Insulation' => ['Insulated'],
                    'Material' => ['Polyester'],
                ],
                'variants' => ['Size' => ['S', 'M', 'L', 'XL'], 'Colour' => ['Black']],
            ],
            [
                'number' => 'bk-bib-shorts-cargo',
                'name' => 'Cargo Bib Shorts',
                'description' => 'Bib shorts with mesh leg pockets, for riders who would rather not use a jersey pocket.',
                'price' => 89.0,
                'stock' => 6,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Shorts',
                'properties' => [
                    'Season' => ['Summer', 'Shoulder season'],
                    'Insulation' => ['Uninsulated'],
                    'Weather protection' => ['Breathable'],
                    'Material' => ['Polyester'],
                ],
                'variants' => ['Size' => ['M', 'L', 'XL'], 'Colour' => ['Black', 'Olive']],
            ],

            // Kestrel Works — Base Layers
            [
                'number' => 'bk-base-layer-sleeveless',
                'name' => 'Base Layer Sleeveless',
                'description' => 'A sleeveless mesh base layer that moves sweat off the skin without adding warmth.',
                'price' => 29.9,
                'stock' => 14,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Base Layers',
                'properties' => [
                    'Season' => ['Summer'],
                    'Insulation' => ['Uninsulated'],
                    'Weather protection' => ['Breathable'],
                    'Material' => ['Polyester'],
                ],
                'variants' => ['Size' => ['S', 'M', 'L', 'XL']],
            ],

            // Kestrel Works — Apparel/Socks
            [
                'number' => 'bk-socks-merino',
                'name' => 'Merino Socks',
                'description' => 'Mid-height merino socks that stay comfortable wet or dry.',
                'price' => 16.9,
                'stock' => 22,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Apparel/Socks',
                'properties' => [
                    'Season' => ['All-season'],
                    'Insulation' => ['Insulated'],
                    'Material' => ['Merino'],
                ],
                'variants' => ['Size' => ['M', 'L'], 'Colour' => ['Black', 'Grey', 'White']],
            ],
            [
                'number' => 'bk-socks-summer-3pack',
                'name' => 'Summer Socks 3-Pack',
                'description' => 'Three pairs of lightweight mesh-panel socks.',
                'price' => 22.0,
                'stock' => 18,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Apparel/Socks',
                'properties' => [
                    'Season' => ['Summer'],
                    'Insulation' => ['Uninsulated'],
                    'Weather protection' => ['Breathable'],
                    'Material' => ['Polyester'],
                ],
                'variants' => ['Size' => ['M', 'L']],
            ],

            // Kestrel Works — Gloves
            [
                'number' => 'bk-gloves-winter',
                'name' => 'Winter Gloves',
                'description' => 'Insulated full-finger gloves with a windproof back and a wiping panel on the thumb.',
                'price' => 39.0,
                'stock' => 8,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Gloves',
                'properties' => [
                    'Season' => ['Winter'],
                    'Insulation' => ['Insulated'],
                    'Weather protection' => ['Windproof'],
                    'Material' => ['Polyester'],
                ],
                'variants' => ['Size' => ['S', 'M', 'L', 'XL'], 'Colour' => ['Black']],
            ],
            [
                'number' => 'bk-gloves-long-finger',
                'name' => 'Long Finger Gloves',
                'description' => 'A light full-finger glove for shoulder-season riding.',
                'price' => 29.9,
                'stock' => 0,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Gloves',
                'properties' => [
                    'Season' => ['Shoulder season'],
                    'Insulation' => ['Uninsulated'],
                    'Weather protection' => ['Breathable'],
                    'Material' => ['Polyester'],
                ],
                'variants' => ['Size' => ['M', 'L', 'XL'], 'Colour' => ['Black', 'Blue']],
            ],

            // Kestrel Works — Apparel/Overshoes
            [
                'number' => 'bk-overshoes-neoprene',
                'name' => 'Neoprene Overshoes',
                'description' => 'Neoprene overshoes with a reinforced sole cut-out and a rear zip.',
                'price' => 44.0,
                'stock' => 6,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Apparel/Overshoes',
                'properties' => [
                    'Season' => ['Winter'],
                    'Insulation' => ['Insulated'],
                    'Weather protection' => ['Waterproof'],
                    'Material' => ['Neoprene'],
                ],
                'variants' => ['Size' => ['M', 'L', 'XL'], 'Colour' => ['Black']],
            ],
            [
                'number' => 'bk-overshoes-toe-covers',
                'name' => 'Toe Covers',
                'description' => 'Covers just the toe box, for mornings that do not warrant full overshoes.',
                'price' => 24.9,
                'stock' => 11,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Apparel/Overshoes',
                'properties' => [
                    'Season' => ['Shoulder season'],
                    'Insulation' => ['Uninsulated'],
                    'Weather protection' => ['Windproof'],
                    'Material' => ['Neoprene'],
                ],
                'variants' => ['Size' => ['M', 'L']],
            ],

            // Kestrel Works — Helmets
            [
                'number' => 'bk-helmet-road-aero',
                'name' => 'Road Helmet Aero',
                'description' => 'A vented aero road helmet with an adjustable cradle.',
                'price' => 129.0,
                'stock' => 5,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Helmets',
                'properties' => [
                    'Season' => ['Summer'],
                    'Weather protection' => ['Breathable'],
                    'Terrain' => ['Road'],
                ],
                'variants' => ['Size' => ['S', 'M', 'L'], 'Colour' => ['Black', 'White']],
            ],
            [
                'number' => 'bk-helmet-gravel',
                'name' => 'Gravel Helmet',
                'description' => 'Deeper coverage at the back of the head and larger vents than the road shell.',
                'price' => 109.0,
                'stock' => 7,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Helmets',
                'properties' => [
                    'Season' => ['All-season'],
                    'Weather protection' => ['Breathable'],
                    'Terrain' => ['Gravel', 'Trail'],
                ],
                'variants' => ['Size' => ['M', 'L'], 'Colour' => ['Black', 'Olive']],
            ],
            [
                'number' => 'bk-helmet-kids',
                'name' => 'Kids Helmet',
                'description' => 'A small-shell helmet with a pinch-free buckle.',
                'price' => 49.0,
                'stock' => 9,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Helmets',
                'properties' => [
                    'Season' => ['All-season'],
                    'Terrain' => ['Commuting'],
                ],
                'variants' => ['Size' => ['S'], 'Colour' => ['Blue', 'Red']],
            ],
            [
                'number' => 'bk-helmet-rain-cover',
                'name' => 'Helmet Rain Cover',
                'description' => 'A stretch cover that closes a helmet\'s vents in heavy rain.',
                'price' => 14.9,
                'stock' => 26,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Helmets',
                'properties' => [
                    'Season' => ['Winter'],
                    'Weather protection' => ['Waterproof'],
                    'Material' => ['Nylon'],
                ],
            ],
        ];
    }
}
