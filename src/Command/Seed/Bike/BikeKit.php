<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * Everything carried rather than fitted, plus the care products: bags, bottles, cages, locks, racks, computers and mounts from Northbound Supply, lubricants and cleaners from Cinder & Co.
 *
 * One of four product files. Split because the project caps a source file at 400 lines — a rule this
 * data would otherwise quietly break, and one worth keeping here: the four shelves are reviewed by
 * different questions, and somebody checking whether the tyre range makes sense should not have to
 * scroll past the socks.
 *
 * @phpstan-import-type ProductSpec from BikeProducts
 */
final class BikeKit
{
    private function __construct() {}

    /** @return list<ProductSpec> */
    public static function all(): array
    {
        return [
            // Northbound Supply — Components/Cockpit
            [
                'number' => 'bk-bar-tape-cork',
                'name' => 'Bar Tape Cork',
                'description' => 'Cork-backed tape with a light cushion, supplied as two 2 m rolls with bar plugs and finishing tape. It wraps any drop or flat bar. Cork grips better than plastic when damp and wears faster, and there is no gel layer, so road buzz comes through more than it does through a gel-backed tape.',
                'price' => 19.9,
                'stock' => 26,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Components/Cockpit',
                'properties' => [
                    'Material' => ['Cork'],
                    'Mounting' => ['Handlebar'],
                ],
                'variants' => ['Colour' => ['Black', 'Blue', 'Olive', 'Tan', 'White']],
            ],
            [
                'number' => 'bk-bar-tape-gel',
                'name' => 'Bar Tape Gel',
                'description' => 'A thicker gel-backed tape for riders who feel the road too much, supplied as two rolls with bar plugs and finishing tape. The gel layer adds roughly 3 mm under the palm, which quiets buzz on long days and makes the bar noticeably fatter in the hand.',
                'price' => 26.0,
                'stock' => 14,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Components/Cockpit',
                'properties' => [
                    'Material' => ['Gel'],
                    'Mounting' => ['Handlebar'],
                ],
                'variants' => ['Colour' => ['Black', 'White']],
            ],

            // Northbound Supply — Accessories/Locks
            [
                'number' => 'bk-lock-folding',
                'name' => 'Folding Lock',
                'description' => 'Six hardened steel links that fold flat into a supplied bracket, and the bracket bolts to a pair of bottle bosses. Two keys are included. It reaches around a frame and a slim stand but not a wide post. No cut-resistance time and no security rating are published for it.',
                'price' => 69.0,
                'stock' => 8,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Accessories/Locks',
                'properties' => [
                    'Material' => ['Steel'],
                    'Mounting' => ['Bottle bosses'],
                ],
            ],
            [
                'number' => 'bk-lock-chain-90',
                'name' => 'Chain Lock 90cm',
                'description' => 'A 90 cm hardened steel chain in a fabric sleeve, long enough to reach an awkward stand, supplied with two keys and no bracket. The Frame mounting value means it is carried wrapped around the frame or seatpost. No cut-resistance time and no security rating are published for it.',
                'price' => 44.0,
                'stock' => 11,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Accessories/Locks',
                'properties' => [
                    'Material' => ['Steel'],
                    'Mounting' => ['Frame'],
                ],
            ],
            [
                'number' => 'bk-lock-frame-compact',
                'name' => 'Frame Lock Compact',
                'description' => 'A rear-wheel frame lock that bolts to the seatstays and drops a hardened steel shackle through the spokes, supplied with two keys and the mounting hardware. It immobilises the wheel for a quick stop and cannot secure the bike to anything. No security rating is published for it.',
                'price' => 39.0,
                'stock' => 0,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Accessories/Locks',
                'properties' => [
                    'Material' => ['Steel'],
                    'Mounting' => ['Frame'],
                ],
            ],
            [
                'number' => 'bk-lock-cable-combination',
                'name' => 'Cable Lock Combination',
                'description' => 'A 1.2 m braided steel cable in a plastic sleeve, closed by a four-digit resettable combination, so there is no key to lose. A frame clip is supplied. Intended for low-risk parking within sight: a cable this thin is cut quickly, and no security rating is published for it.',
                'price' => 19.9,
                'stock' => 24,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Accessories/Locks',
                'properties' => [
                    'Material' => ['Steel'],
                    'Mounting' => ['Frame'],
                ],
            ],

            // Northbound Supply — Accessories/Computers & Mounts
            [
                'number' => 'bk-mount-phone',
                'name' => 'Phone Mount',
                'description' => 'A quarter-turn phone mount on a stem plate, supplied with the plate, two adhesive phone adapters and the bolts. The plate fits stems from 80 to 130 mm, and a case with its own quarter-turn socket works in place of the adapters. No weather protection is claimed for the phone.',
                'price' => 34.0,
                'stock' => 9,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Accessories/Computers & Mounts',
                'properties' => [
                    'Material' => ['Plastic'],
                    'Mounting' => ['Stem'],
                ],
            ],

            // Northbound Supply — Accessories/Racks
            [
                'number' => 'bk-rack-rear-pannier',
                'name' => 'Rear Pannier Rack',
                'description' => 'An alloy rear rack rated to 25 kg, supplied with its struts and bolts. It needs rack eyelets on the seatstays and dropouts and has no seatpost-clamp option, so a frame without eyelets will not take it. The platform carries a top bag and panniers hang from the side rails.',
                'price' => 59.0,
                'stock' => 7,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Accessories/Racks',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Terrain' => ['Commuting'],
                    'Mounting' => ['Frame'],
                ],
            ],
            [
                'number' => 'bk-rack-front-mini',
                'name' => 'Front Mini Rack',
                'description' => 'A small alloy front platform for a bag or a basket, supplied with its struts and bolts. It bolts to fork eyelets and a mid-blade mount, so a fork without both will not take it. Rated to 5 kg, which is a day bag rather than a front pannier load.',
                'price' => 49.0,
                'stock' => 5,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Accessories/Racks',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Terrain' => ['Commuting'],
                    'Mounting' => ['Fork'],
                ],
            ],

            // Northbound Supply — Bags
            [
                'number' => 'bk-bag-frame-2l',
                'name' => 'Frame Bag 2L',
                'description' => 'A two-litre frame bag in water-repellent nylon that straps to the top tube and head tube with four hook-and-loop straps, all supplied. The zip is not sealed, so it is water-repellent rather than waterproof, and a phone wants a dry bag inside it in real rain.',
                'price' => 44.0,
                'stock' => 12,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Bags',
                'properties' => [
                    'Weather protection' => ['Water-repellent'],
                    'Material' => ['Nylon'],
                    'Terrain' => ['Gravel'],
                    'Mounting' => ['Frame'],
                ],
                'variants' => ['Colour' => ['Black', 'Olive']],
            ],
            [
                'number' => 'bk-bag-saddle-1l',
                'name' => 'Saddle Bag 1L',
                'description' => 'A one-litre saddle bag in water-repellent nylon, sized for a tube, levers and a multi-tool, supplied with its saddle-rail and seatpost straps. It fits under any saddle with exposed rails. The zip is not sealed, so it turns spray rather than sealing rain out.',
                'price' => 29.0,
                'stock' => 19,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Bags',
                'properties' => [
                    'Weather protection' => ['Water-repellent'],
                    'Material' => ['Nylon'],
                    'Mounting' => ['Seatpost'],
                ],
                'variants' => ['Colour' => ['Black', 'Tan']],
            ],
            [
                'number' => 'bk-bag-top-tube',
                'name' => 'Top Tube Bag',
                'description' => 'A bolt-on top tube bag in water-repellent nylon, sized for a phone and a bar, supplied with its bolts. It needs a pair of top-tube bosses behind the head tube and there are no straps in the box, so a frame without those bosses will not take it.',
                'price' => 34.0,
                'stock' => 14,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Bags',
                'properties' => [
                    'Weather protection' => ['Water-repellent'],
                    'Material' => ['Nylon'],
                    'Mounting' => ['Frame'],
                ],
                'variants' => ['Colour' => ['Black', 'Olive']],
            ],

            // Northbound Supply — Bottles
            [
                'number' => 'bk-bottle-insulated-600',
                'name' => 'Insulated Bottle 600ml',
                'description' => 'A 600 ml double-walled bottle that keeps a cold drink cold for a few hours, in BPA-free plastic with a silicone valve. It fits a standard bottle cage. The double wall makes it fatter than a plain bottle, so a tight side-load cage or a small frame may not clear it.',
                'price' => 24.9,
                'stock' => 20,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Bottles',
                'properties' => [
                    'Insulation' => ['Insulated'],
                    'Material' => ['Plastic'],
                    'Mounting' => ['Bottle bosses'],
                ],
                'variants' => ['Colour' => ['Black', 'Blue', 'White']],
            ],

            // Northbound Supply — Cages
            [
                'number' => 'bk-cage-carbon',
                'name' => 'Carbon Bottle Cage',
                'description' => 'A carbon cage that holds a bottle over rough ground, supplied with two bolts. It takes the standard pair of bottle bosses and grips firmly enough for gravel and trail. Carbon does not bend back into shape, so a cage crushed in a crash is replaced rather than adjusted.',
                'price' => 39.0,
                'stock' => 9,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Cages',
                'properties' => [
                    'Material' => ['Carbon'],
                    'Terrain' => ['Gravel', 'Trail'],
                    'Mounting' => ['Bottle bosses'],
                ],
            ],
            [
                'number' => 'bk-cage-side-load',
                'name' => 'Side-Load Cage',
                'description' => 'An alloy side-entry cage for small frames, supplied with two bolts. Loading from the side clears a low top tube where a bottle cannot be lifted straight out. The alloy band can be bent to tighten or loosen its grip, though a fat insulated bottle may still not pass.',
                'price' => 22.0,
                'stock' => 18,
                'manufacturer' => 'Northbound Supply',
                'category' => 'Cages',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Mounting' => ['Bottle bosses'],
                ],
            ],

            // Cinder & Co. — Maintenance/Lubricants & Cleaners
            [
                'number' => 'bk-lube-wet-100',
                'name' => 'Wet Chain Lube 100ml',
                'description' => 'A 100 ml wet-weather chain lube that stays on the chain in rain, applied by drip bottle one link at a time. It holds more grit than a dry lube and wants a wipe-down after a filthy ride. Intended for winter and shoulder-season riding on gravel and trail.',
                'price' => 12.9,
                'stock' => 30,
                'manufacturer' => 'Cinder & Co.',
                'category' => 'Maintenance/Lubricants & Cleaners',
                'properties' => [
                    'Season' => ['Winter', 'Shoulder season'],
                    'Terrain' => ['Gravel', 'Trail'],
                ],
            ],
            [
                'number' => 'bk-lube-dry-100',
                'name' => 'Dry Chain Lube 100ml',
                'description' => 'A 100 ml dry chain lube for dusty conditions, applied by drip bottle and left to set before riding. It keeps the drivetrain far cleaner than a wet lube and washes off in rain, so it wants reapplying more often. Intended for road and dry gravel in summer.',
                'price' => 12.9,
                'stock' => 28,
                'manufacturer' => 'Cinder & Co.',
                'category' => 'Maintenance/Lubricants & Cleaners',
                'properties' => [
                    'Season' => ['Summer'],
                    'Terrain' => ['Road', 'Gravel'],
                ],
            ],
            [
                'number' => 'bk-cleaner-degreaser-500',
                'name' => 'Degreaser 500ml',
                'description' => 'A 500 ml citrus degreaser for chains, cassettes and chainrings, in a trigger spray. It lifts old lube and road grime without stripping paint or attacking seals. Rinse and dry the chain before relubricating. It is not a frame wash and will dull a polished finish.',
                'price' => 14.9,
                'stock' => 21,
                'manufacturer' => 'Cinder & Co.',
                'category' => 'Maintenance/Lubricants & Cleaners',
                'properties' => [
                    'Season' => ['All-season'],
                ],
            ],
            [
                'number' => 'bk-cleaner-bike-wash-1l',
                'name' => 'Bike Wash 1L',
                'description' => 'A one-litre pH-neutral wash that will not strip frame finishes, decals or brake surfaces, in a trigger spray. Sprayed on, left for a minute and rinsed off. It shifts mud and road film but not baked-on chain grease, which is what the degreaser is for.',
                'price' => 16.9,
                'stock' => 17,
                'manufacturer' => 'Cinder & Co.',
                'category' => 'Maintenance/Lubricants & Cleaners',
                'properties' => [
                    'Season' => ['All-season'],
                ],
            ],
            [
                'number' => 'bk-cleaner-frame-polish',
                'name' => 'Frame Polish',
                'description' => 'A finishing polish that leaves a surface dirt struggles to hold, wiped onto a clean dry frame with a cloth. Safe on paint, clearcoat and decals. Keep it off rims, rotors and pads: it is a release coating, and a slippery braking surface is exactly what it makes.',
                'price' => 11.9,
                'stock' => 0,
                'manufacturer' => 'Cinder & Co.',
                'category' => 'Maintenance/Lubricants & Cleaners',
                'properties' => [
                    'Season' => ['All-season'],
                ],
            ],
        ];
    }
}
