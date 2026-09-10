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
                'description' => 'A wind- and shower-proof nylon shell cut for a riding position, with a dropped tail, a zipped rear pocket and elasticated cuffs. Uninsulated, so it goes over a jersey or a base layer. Water-repellent rather than waterproof: it turns a shower, not an hour of steady rain.',
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
                'description' => 'A waterproof nylon shell with taped seams that folds into its own rear pocket, light enough to carry on days it may not be needed. Uninsulated. Being fully waterproof costs breathability, so on a hard climb it holds sweat in: a layer to put on and take off.',
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
                'description' => 'An unlined windproof nylon layer for cool starts and warm afternoons, with a half zip and one rear pocket. Uninsulated and not water-repellent: it stops wind chill and nothing else, so rain goes straight through. It packs down to roughly the size of a fist.',
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
                'description' => 'A brushed-back polyester long sleeve jersey with a full-length zip, three rear pockets and a zipped valuables pocket. The brushed inner face is the insulation. Breathable but not windproof, so it wants a shell over it much below ten degrees.',
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
                'description' => 'A regular-fit short sleeve polyester jersey in the club colours, with a full-length zip, three rear pockets and gripper elastic at the hem. Regular fit rather than race fit, so it does not cling. Uninsulated and unlined: a summer jersey that needs layers when it cools.',
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
                'description' => 'Full-length thermal bibs in brushed polyester with a seat pad rated for long winter rides, ankle zips and reflective tabs at the heel. Insulated but neither windproof nor waterproof, so driving rain and a hard headwind both get through and want a shell over the top.',
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
                'description' => 'Bib shorts in breathable polyester with a mesh pocket on each thigh, for riders who would rather not load a jersey pocket. Laser-cut leg grippers and a mid-density pad. The pockets take a phone or a bar; loaded much heavier than that they sag and rub.',
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
                'description' => 'A sleeveless polyester mesh base layer that moves sweat off the skin without adding warmth, with flatlock seams that sit flat under a jersey. A summer layer: the open mesh does nothing against wind chill, where a brushed long-sleeve cut does the opposite job.',
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
                'description' => 'Mid-height socks in a merino blend that stay comfortable wet or dry, since merino still insulates damp where a synthetic stops. Sold as one pair. Merino wants a cool wash and no tumble dryer, and it wears through at the heel sooner than a polyester sock.',
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
                'description' => 'Three pairs of lightweight polyester socks with mesh panels over the instep, sold as one pack of three, with a mid-height cuff. The mesh is what keeps them cool and also what makes them the wrong sock for a cold wet ride, where a wool blend does better.',
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
                'description' => 'Insulated full-finger gloves with a windproof back, a wiping panel on the thumb and a long cuff that tucks under a sleeve. Windproof but not waterproof: they hold warmth in cold dry air and wet through in sustained rain, after which they stay cold.',
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
                'description' => 'A light full-finger glove for shoulder-season riding, in breathable polyester with a padded palm and a touchscreen-friendly index finger. Uninsulated and not windproof, so they take the edge off a cool morning and are not a winter glove.',
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
                'description' => 'Neoprene overshoes with a reinforced sole cut-out for cleat and heel, and a rear zip with a storm flap. Neoprene insulates even when soaked, which is the point of it. Sold as one pair. Cut for road shoes, so they pull tight over a bulky trail sole.',
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
                'description' => 'Neoprene covers for the toe box only, for mornings that do not warrant full overshoes, with a cut-out for a road cleat. Sold as one pair. They stop wind over the toes and leave the rest of the shoe open, so they do little once water runs down the leg.',
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
                'description' => 'A vented aero road helmet certified to EN 1078, with an in-mould polycarbonate shell, an adjustable rear cradle and a washable pad set. Sized by head circumference: S 51-55, M 55-59, L 59-63 cm. No visor, no mirror and no light are supplied or fitted.',
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
                'description' => 'Deeper coverage at the back of the head and larger vents than the road shell, certified to EN 1078, with an in-mould shell and an adjustable cradle. Sized M 55-59 and L 59-63 cm. EN 1078 is the only rating it carries: there is no separate trail or downhill certification.',
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
                'description' => 'A small-shell helmet certified to EN 1078, with a pinch-free buckle and an adjustable cradle. One size, S, for head circumferences of 48 to 52 cm. Measure before buying, because the cradle takes up slack rather than a size gap and a loose helmet does not protect.',
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
                'description' => 'A stretch nylon cover that closes the vents of a helmet in heavy rain, with an elasticated edge that pulls under the shell. One size, cut to fit an adult road or gravel shell. It is not protective equipment and does nothing for impact: it keeps rain off a head.',
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
