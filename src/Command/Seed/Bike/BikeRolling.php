<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * Everything that touches the ground or stops the bike: tyres, tubes, tubeless kit, wheelsets, brake
 * pads, rotors and lines. Halyard Tyres for the rubber, Ferrolane for the wheels and braking hardware.
 *
 * The wheelsets came across from {@see BikeParts} on 2026-08-31 — see that file for why.
 *
 * One of four product files. Split because the project caps a source file at 400 lines — a rule this
 * data would otherwise quietly break, and one worth keeping here: the four shelves are reviewed by
 * different questions, and somebody checking whether the tyre range makes sense should not have to
 * scroll past the socks.
 *
 * @phpstan-import-type ProductSpec from BikeProducts
 */
final class BikeRolling
{
    private function __construct() {}

    /** @return list<ProductSpec> */
    public static function all(): array
    {
        return [
            // Halyard Tyres — Tyres
            [
                'number' => 'bk-tyre-all-road',
                'name' => 'All-Road Tyre',
                'description' => 'A fast-rolling tyre with a file tread, for mixed tarmac and hardpack.',
                'price' => 46.0,
                'stock' => 14,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['All-season'],
                    'Material' => ['Rubber'],
                    'Terrain' => ['Road', 'Gravel'],
                ],
                'variants' => ['Size' => ['700x28', '700x32', '700x40']],
            ],
            [
                'number' => 'bk-tyre-cyclocross',
                'name' => 'Cyclocross Tyre',
                'description' => 'A knobbed tread for mud and wet grass.',
                'price' => 52.0,
                'stock' => 6,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['Winter'],
                    'Material' => ['Rubber'],
                    'Terrain' => ['Trail', 'Gravel'],
                ],
                'variants' => ['Size' => ['700x32', '700x40']],
            ],
            [
                'number' => 'bk-tyre-touring-reflective',
                'name' => 'Touring Tyre Reflective',
                'description' => 'A puncture-belted touring tyre with a reflective sidewall stripe.',
                'price' => 42.0,
                'stock' => 10,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['All-season'],
                    'Material' => ['Rubber'],
                    'Terrain' => ['Commuting', 'Road'],
                ],
                'variants' => ['Size' => ['700x32', '700x40']],
            ],
            [
                'number' => 'bk-tyre-city-guard',
                'name' => 'City Tyre Puncture Guard',
                'description' => 'A heavy-belted commuting tyre built for glass and grit.',
                'price' => 34.0,
                'stock' => 0,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['All-season'],
                    'Material' => ['Rubber'],
                    'Terrain' => ['Commuting'],
                ],
                'variants' => ['Size' => ['700x32']],
            ],
            [
                'number' => 'bk-tyre-plus-650b',
                'name' => 'Plus Tyre 650b',
                'description' => 'A high-volume 650b tyre for comfort on rough surfaces.',
                'price' => 49.0,
                'stock' => 8,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['All-season'],
                    'Material' => ['Rubber'],
                    'Terrain' => ['Gravel', 'Trail'],
                ],
                'variants' => ['Size' => ['650x47']],
            ],
            [
                'number' => 'bk-tyre-race-25',
                'name' => 'Race Tyre',
                'description' => 'A supple, light race tyre with minimal puncture protection.',
                'price' => 58.0,
                'stock' => 5,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['Summer'],
                    'Material' => ['Rubber'],
                    'Terrain' => ['Road'],
                ],
                'variants' => ['Size' => ['700x25', '700x28']],
            ],
            [
                'number' => 'bk-tubeless-valve-set',
                'name' => 'Tubeless Valve Set',
                'description' => 'A pair of 44 mm tubeless valves with removable cores.',
                'price' => 18.9,
                'stock' => 25,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Terrain' => ['Road', 'Gravel'],
                ],
            ],
            [
                'number' => 'bk-tubeless-rim-tape',
                'name' => 'Tubeless Rim Tape 25mm',
                'description' => 'A 10 m roll of 25 mm tubeless rim tape.',
                'price' => 12.9,
                'stock' => 30,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Material' => ['Nylon'],
                    'Terrain' => ['Road', 'Gravel'],
                ],
            ],

            // Halyard Tyres — Tyres/Tubes
            [
                'number' => 'bk-tube-presta-700c',
                'name' => 'Inner Tube Presta 700c',
                'description' => 'A butyl inner tube with a 48 mm Presta valve.',
                'price' => 7.9,
                'stock' => 60,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres/Tubes',
                'properties' => [
                    'Material' => ['Rubber'],
                    'Terrain' => ['Road'],
                ],
            ],
            [
                'number' => 'bk-tube-presta-650b',
                'name' => 'Inner Tube Presta 650b',
                'description' => 'A butyl inner tube sized for 650b rims.',
                'price' => 7.9,
                'stock' => 34,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres/Tubes',
                'properties' => [
                    'Material' => ['Rubber'],
                    'Terrain' => ['Gravel'],
                ],
            ],
            [
                'number' => 'bk-tube-lightweight',
                'name' => 'Lightweight Inner Tube',
                'description' => 'A thin-wall tube for riders counting grams rather than punctures.',
                'price' => 13.9,
                'stock' => 21,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres/Tubes',
                'properties' => [
                    'Material' => ['Rubber'],
                    'Terrain' => ['Road'],
                ],
            ],

            // Ferrolane — Brakes/Pads
            [
                'number' => 'bk-pads-organic',
                'name' => 'Organic Brake Pads',
                'description' => 'Quiet organic compound pads with a short bedding-in period.',
                'price' => 18.9,
                'stock' => 20,
                'manufacturer' => 'Ferrolane',
                'category' => 'Brakes/Pads',
                'properties' => [
                    'Season' => ['Summer'],
                    'Terrain' => ['Road'],
                ],
                'variants' => ['Brake system' => ['Disc', 'Rim']],
            ],
            [
                'number' => 'bk-pads-sintered',
                'name' => 'Sintered Brake Pads',
                'description' => 'A metallic compound that holds up in wet and long descents.',
                'price' => 24.0,
                'stock' => 15,
                'manufacturer' => 'Ferrolane',
                'category' => 'Brakes/Pads',
                'properties' => [
                    'Season' => ['Winter', 'All-season'],
                    'Material' => ['Steel'],
                    'Terrain' => ['Trail', 'Gravel'],
                ],
                'variants' => ['Brake system' => ['Disc']],
            ],
            [
                'number' => 'bk-pads-long-life',
                'name' => 'Long Life Brake Pads',
                'description' => 'A harder compound for riders who would rather replace pads less often.',
                'price' => 28.0,
                'stock' => 0,
                'manufacturer' => 'Ferrolane',
                'category' => 'Brakes/Pads',
                'properties' => [
                    'Season' => ['All-season'],
                    'Material' => ['Steel'],
                    'Terrain' => ['Commuting'],
                ],
                'variants' => ['Brake system' => ['Disc', 'Rim']],
            ],

            // Ferrolane — Brakes/Rotors
            [
                'number' => 'bk-rotor-centerlock',
                'name' => 'Centerlock Rotor',
                'description' => 'A two-piece rotor with an alloy carrier, for Centerlock hubs.',
                'price' => 32.0,
                'stock' => 12,
                'manufacturer' => 'Ferrolane',
                'category' => 'Brakes/Rotors',
                'properties' => [
                    'Material' => ['Alloy', 'Stainless steel'],
                    'Terrain' => ['Road', 'Gravel'],
                ],
                'variants' => ['Rotor size' => ['140 mm', '160 mm', '180 mm']],
            ],
            [
                'number' => 'bk-rotor-6-bolt',
                'name' => '6-Bolt Rotor',
                'description' => 'A one-piece steel rotor for six-bolt hubs.',
                'price' => 28.0,
                'stock' => 16,
                'manufacturer' => 'Ferrolane',
                'category' => 'Brakes/Rotors',
                'properties' => [
                    'Material' => ['Steel'],
                    'Terrain' => ['Trail', 'Gravel'],
                ],
                'variants' => ['Rotor size' => ['160 mm', '180 mm']],
            ],

            // Ferrolane — Brakes
            [
                'number' => 'bk-brake-cable-set',
                'name' => 'Brake Cable Set',
                'description' => 'Stainless inner cables and compressionless outers for both brakes.',
                'price' => 16.9,
                'stock' => 24,
                'manufacturer' => 'Ferrolane',
                'category' => 'Brakes',
                'properties' => [
                    'Material' => ['Stainless steel'],
                    'Terrain' => ['Road', 'Commuting'],
                ],
            ],
            [
                'number' => 'bk-brake-hose-kit',
                'name' => 'Hydraulic Hose Kit',
                'description' => 'A hose, olive and barb for shortening or replacing one brake line.',
                'price' => 34.0,
                'stock' => 9,
                'manufacturer' => 'Ferrolane',
                'category' => 'Brakes',
                'properties' => [
                    'Material' => ['Nylon'],
                    'Terrain' => ['Trail', 'Gravel'],
                ],
            ],

            // Ferrolane — Components/Wheels
            [
                'number' => 'bk-wheelset-alloy-700c',
                'name' => 'Alloy Wheelset 700c',
                'description' => 'A tubeless-ready alloy wheelset on sealed-bearing hubs.',
                'price' => 399.0,
                'stock' => 3,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Wheels',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Terrain' => ['Road', 'Gravel'],
                ],
            ],
            [
                'number' => 'bk-wheelset-gravel-tubeless',
                'name' => 'Gravel Wheelset Tubeless',
                'description' => 'A wider-rim tubeless wheelset built for higher-volume tyres.',
                'price' => 649.0,
                'stock' => 0,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Wheels',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Terrain' => ['Gravel', 'Trail'],
                ],
            ],
            [
                'number' => 'bk-spoke-set',
                'name' => 'Spoke Set',
                'description' => 'Twelve stainless spokes and nipples in one length.',
                'price' => 14.9,
                'stock' => 22,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Wheels',
                'properties' => [
                    'Material' => ['Stainless steel'],
                ],
            ],
            [
                'number' => 'bk-skewers-quick-release',
                'name' => 'Quick Release Skewers',
                'description' => 'A pair of alloy quick-release skewers.',
                'price' => 22.0,
                'stock' => 17,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Wheels',
                'properties' => [
                    'Material' => ['Alloy'],
                ],
            ],
            [
                'number' => 'bk-thru-axle-12x142',
                'name' => 'Thru Axle 12x142',
                'description' => 'A rear thru axle for 142 mm spacing.',
                'price' => 29.0,
                'stock' => 12,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Wheels',
                'properties' => [
                    'Material' => ['Alloy'],
                ],
            ],
        ];
    }
}
