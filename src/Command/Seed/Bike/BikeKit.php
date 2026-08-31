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
                'description' => 'Cork-backed tape with a light cushion, supplied with plugs and finishing tape.',
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
                'description' => 'A thicker gel-backed tape for riders who feel the road too much.',
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
                'description' => 'A hardened folding lock that carries on the bottle bosses.',
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
                'description' => 'A 90 cm chain in a fabric sleeve, for locking to awkward stands.',
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
                'description' => 'A rear-wheel frame lock for quick stops.',
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
                'description' => 'A light combination cable for low-risk parking.',
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
                'description' => 'A quarter-turn phone mount with a stem plate.',
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
                'description' => 'An alloy rear rack rated to 25 kg.',
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
                'description' => 'A small front platform for a bag or a basket.',
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
                'description' => 'A two-litre frame bag that straps to the top tube and head tube.',
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
                'description' => 'A one-litre saddle bag for a tube, levers and a multi-tool.',
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
                'description' => 'A bolt-on top tube bag sized for a phone and a bar.',
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
                'description' => 'A double-walled bottle that keeps a drink cold for a few hours.',
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
                'description' => 'A carbon cage that holds a bottle over rough ground.',
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
                'description' => 'A side-entry cage for small frames.',
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
                'description' => 'A wet-weather chain lube that stays put in rain.',
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
                'description' => 'A dry lube for dusty conditions, reapplied more often.',
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
                'description' => 'A citrus degreaser for drivetrains, safe on paint.',
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
                'description' => 'A pH-neutral wash that will not strip frame finishes.',
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
                'description' => 'A finishing polish that leaves a surface dirt struggles to hold.',
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
