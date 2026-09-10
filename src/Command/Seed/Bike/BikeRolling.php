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
                'description' => 'A fast-rolling tyre with a file tread for mixed tarmac and hardpack, tubeless-ready on a folding bead. The puncture belt runs under the tread only and not up the sidewall. Pick the width to match frame clearance: a 40 will not fit a rim-brake road frame.',
                'price' => 46.0,
                'stock' => 14,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['All-season'],
                    'Terrain' => ['Road', 'Gravel'],
                ],
                'variants' => ['Size' => ['700x28', '700x32', '700x40']],
            ],
            [
                'number' => 'bk-tyre-cyclocross',
                'name' => 'Cyclocross Tyre',
                'description' => 'A knobbed tread for mud and wet grass, with blocks spaced widely enough to shed rather than pack. Tubeless-ready on a folding bead. The tread that grips mud is slow and buzzy on tarmac, so it is a poor choice for a mixed ride that is mostly road.',
                'price' => 52.0,
                'stock' => 6,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['Winter'],
                    'Terrain' => ['Trail', 'Gravel'],
                ],
                'variants' => ['Size' => ['700x32', '700x40']],
            ],
            [
                'number' => 'bk-tyre-touring-reflective',
                'name' => 'Touring Tyre Reflective',
                'description' => 'A puncture-belted touring tyre with a reflective stripe on both sidewalls, so the wheel shows up side-on to a car. Wire bead rather than folding. The belt runs bead to bead, which makes it heavy and stiff to fit, and it is not tubeless-ready.',
                'price' => 42.0,
                'stock' => 10,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['All-season'],
                    'Terrain' => ['Commuting', 'Road'],
                ],
                'variants' => ['Size' => ['700x32', '700x40']],
            ],
            [
                'number' => 'bk-tyre-city-guard',
                'name' => 'City Tyre Puncture Guard',
                'description' => 'A heavy-belted commuting tyre built for glass and grit, with a thick belt bead to bead and a reflective sidewall. Wire bead, not tubeless-ready. It is the slowest tyre here and the stiffest to fit by hand: chosen for not stopping rather than for speed.',
                'price' => 34.0,
                'stock' => 0,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['All-season'],
                    'Terrain' => ['Commuting'],
                ],
                'variants' => ['Size' => ['700x32']],
            ],
            [
                'number' => 'bk-tyre-plus-650b',
                'name' => 'Plus Tyre 650b',
                'description' => 'A high-volume 650b tyre, 47 mm across, tubeless-ready on a folding bead. The volume is what gives the comfort, so it is run at low pressure over rough ground. It fits only a frame built for 650b wheels and is not an alternative width for a 700c wheel.',
                'price' => 49.0,
                'stock' => 8,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['All-season'],
                    'Terrain' => ['Gravel', 'Trail'],
                ],
                'variants' => ['Size' => ['650x47']],
            ],
            [
                'number' => 'bk-tyre-race-25',
                'name' => 'Race Tyre',
                'description' => 'A supple, light race tyre with a high thread count and minimal puncture protection, folding bead and tubeless-ready. The thin casing is what makes it fast and what makes it vulnerable: a dry-summer road tyre rather than one for winter grit.',
                'price' => 58.0,
                'stock' => 5,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres',
                'properties' => [
                    'Season' => ['Summer'],
                    'Terrain' => ['Road'],
                ],
                'variants' => ['Size' => ['700x25', '700x28']],
            ],
            [
                'number' => 'bk-tubeless-valve-set',
                'name' => 'Tubeless Valve Set',
                'description' => 'A pair of 44 mm alloy tubeless valves with removable cores, supplied with rubber bases and lock rings. The core comes out so sealant can be injected through the valve. Valves only: rim tape and sealant are sold separately and a tubeless setup needs both.',
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
                'description' => 'A 10 m roll of 25 mm nylon tubeless rim tape, enough for two rims of most sizes. It seals the spoke holes so the tyre can hold air. Pick a width that just covers the rim bed: narrower than the bed leaks, and much wider wrinkles and leaks as well.',
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
                'description' => 'A butyl inner tube for 700c rims with a 48 mm Presta valve, long enough for a deep-section rim. Butyl holds pressure better than latex and patches easily. It covers 700x28 to 700x40; a 25 mm race tyre stretches it thin, where a thin-wall tube is the better match.',
                'price' => 7.9,
                'stock' => 60,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres/Tubes',
                'properties' => [
                    'Terrain' => ['Road'],
                ],
            ],
            [
                'number' => 'bk-tube-presta-650b',
                'name' => 'Inner Tube Presta 650b',
                'description' => 'A butyl inner tube sized for 650b rims with a Presta valve, covering 47 mm and wider tyres. Butyl holds pressure better than latex and patches easily. It will not fit a 700c wheel: a tube stretched to the wrong diameter sits unevenly and pinches on the rim.',
                'price' => 7.9,
                'stock' => 34,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres/Tubes',
                'properties' => [
                    'Terrain' => ['Gravel'],
                ],
            ],
            [
                'number' => 'bk-tube-lightweight',
                'name' => 'Lightweight Inner Tube',
                'description' => 'A thin-wall butyl tube for riders counting grams rather than punctures, for 700c rims with a Presta valve. It saves roughly 40 g a wheel against the standard tube. The thinner wall is easier to pinch while fitting and less forgiving of a low-pressure ride.',
                'price' => 13.9,
                'stock' => 21,
                'manufacturer' => 'Halyard Tyres',
                'category' => 'Tyres/Tubes',
                'properties' => [
                    'Terrain' => ['Road'],
                ],
            ],

            // Ferrolane — Brakes/Pads
            [
                'number' => 'bk-pads-organic',
                'name' => 'Organic Brake Pads',
                'description' => 'A quiet organic compound with a short bedding-in period, supplied as one pair with the retaining pin or spring. Pick disc or rim to match the brake. Organic pads bite well from cold and fade sooner on a long wet descent, which is what a metallic compound is for.',
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
                'description' => 'A metallic sintered compound that holds up in the wet and on long descents, supplied as one pair with the retaining pin. Disc only. They are noisier than organic pads, particularly cold and damp, and they wear a rotor faster in exchange for the heat resistance.',
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
                'description' => 'A harder compound for riders who would rather replace pads less often, supplied as one pair with the retaining pin or spring. Pick disc or rim to match the brake. The hardness costs initial bite and a longer bedding-in, which suits commuting more than descending.',
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
                'description' => 'A two-piece rotor with a stainless braking surface on an alloy carrier, for Centerlock hubs, supplied without a lockring. Pick the size to match the caliper mount. Centerlock and six-bolt hubs are not interchangeable, and the carrier sheds heat better than solid steel.',
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
                'description' => 'A one-piece stainless rotor for six-bolt hubs, supplied with its six bolts. Pick the size to match the caliper mount. One-piece steel runs hotter than a two-piece rotor on a long descent and costs less, and it will not fit a Centerlock hub without an adapter.',
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
                'description' => 'Stainless inner cables and compressionless outers for both brakes, supplied with ferrules and end caps. The compressionless outer is what keeps the lever feeling firm. For cable brakes only: a hydraulic brake takes a hose and fluid instead, and the two share no parts.',
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
                'description' => 'A hose, olive and barb for shortening or replacing one brake line, so one kit does one brake. The barb presses into the hose and the olive is compressed by the lever nut. Fluid is not included, and a hose that has been replaced always needs a bleed afterwards.',
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
                'description' => 'A tubeless-ready alloy 700c wheelset on sealed-bearing hubs, with a 21 mm internal rim, supplied with rim tape and tubeless valves fitted. Centerlock rotors and thru-axle only. Tyres, sealant, rotors and a cassette are not included and are all needed to ride it.',
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
                'description' => 'A wider-rim tubeless 700c wheelset built for higher-volume tyres, with a 25 mm internal rim on sealed-bearing hubs, supplied with rim tape and valves. Centerlock rotors and thru-axle only. The wide rim suits 38 mm upward and squares off the profile of a narrow tyre.',
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
                'description' => 'Twelve stainless spokes with their nipples, all one length, for a repair rather than a wheel build. Spoke length is specific to the wheel, so measure a spoke taken out of the wheel being fixed: a length 2 mm out will not come up to tension correctly.',
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
                'description' => 'A pair of alloy quick-release skewers, front and rear, with cam levers on steel shafts. For quick-release dropouts only. A frame or fork built for thru axles takes no skewer at all, since the axle threads into the dropout and is a different part entirely.',
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
                'description' => 'A rear thru axle for 12 mm by 142 mm spacing, in alloy with a folding lever. The thread pitch has to match the frame and pitch varies between makers, so check the axle being replaced. This is a rear axle: a fork takes a 15 mm or 12x100 front axle instead.',
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
