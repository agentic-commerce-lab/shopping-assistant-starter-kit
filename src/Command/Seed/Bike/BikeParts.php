<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * Everything bolted to the frame: tools, drivetrain, cockpit, seatposts, saddles and computer mounts.
 * Ferrolane for the metal, Kestrel Works for the two saddles.
 *
 * **Wheels live in {@see BikeRolling}, not here.** Moved 2026-08-31 when adding descriptive properties
 * pushed this file past the 400-line cap: the wheelsets belong beside the tyres and tubes they carry
 * rather than beside the stems, and that is the shelf a reviewer checks them against anyway.
 *
 * One of four product files. Split because the project caps a source file at 400 lines — a rule this
 * data would otherwise quietly break, and one worth keeping here: the four shelves are reviewed by
 * different questions, and somebody checking whether the tyre range makes sense should not have to
 * scroll past the socks.
 *
 * @phpstan-import-type ProductSpec from BikeProducts
 */
final class BikeParts
{
    private function __construct() {}

    /** @return list<ProductSpec> */
    public static function all(): array
    {
        return [
            // Ferrolane — Maintenance/Tools
            [
                'number' => 'bk-brake-bleed-kit',
                'name' => 'Brake Bleed Kit',
                'description' => 'Syringes, fittings and tubing for a full bleed. Fluid not included.',
                'price' => 54.0,
                'stock' => 4,
                'manufacturer' => 'Ferrolane',
                'category' => 'Maintenance/Tools',
                'properties' => [
                    'Material' => ['Plastic'],
                ],
            ],
            [
                'number' => 'bk-chain-wear-indicator',
                'name' => 'Chain Wear Indicator',
                'description' => 'A drop-in gauge that shows when a chain has stretched past service.',
                'price' => 14.9,
                'stock' => 28,
                'manufacturer' => 'Ferrolane',
                'category' => 'Maintenance/Tools',
                'properties' => [
                    'Material' => ['Steel'],
                ],
            ],
            [
                'number' => 'bk-tool-torque-wrench',
                'name' => 'Torque Wrench 2-14Nm',
                'description' => 'A ratcheting torque wrench with the bits carbon parts need.',
                'price' => 89.0,
                'stock' => 6,
                'manufacturer' => 'Ferrolane',
                'category' => 'Maintenance/Tools',
                'properties' => [
                    'Material' => ['Steel'],
                ],
            ],
            [
                'number' => 'bk-tool-cassette-remover',
                'name' => 'Cassette Removal Tool',
                'description' => 'A splined cassette tool with a guide pin.',
                'price' => 24.0,
                'stock' => 14,
                'manufacturer' => 'Ferrolane',
                'category' => 'Maintenance/Tools',
                'properties' => [
                    'Material' => ['Steel'],
                ],
            ],
            [
                'number' => 'bk-tool-tyre-levers',
                'name' => 'Tyre Lever Set',
                'description' => 'Three glass-filled nylon levers that clip together.',
                'price' => 5.9,
                'stock' => 45,
                'manufacturer' => 'Ferrolane',
                'category' => 'Maintenance/Tools',
                'properties' => [
                    'Material' => ['Nylon'],
                ],
            ],
            [
                'number' => 'bk-tool-spoke-key',
                'name' => 'Spoke Key',
                'description' => 'A three-size spoke key for truing at the roadside.',
                'price' => 11.9,
                'stock' => 27,
                'manufacturer' => 'Ferrolane',
                'category' => 'Maintenance/Tools',
                'properties' => [
                    'Material' => ['Steel'],
                ],
            ],

            // Ferrolane — Components/Drivetrain
            [
                'number' => 'bk-chain',
                'name' => 'Chain',
                'description' => 'A nickel-plated chain supplied with a quick link.',
                'price' => 34.0,
                'stock' => 18,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Drivetrain',
                'properties' => [
                    'Material' => ['Steel'],
                    'Terrain' => ['Road', 'Gravel'],
                ],
                'variants' => ['Speed' => ['10-speed', '11-speed', '12-speed']],
            ],
            [
                'number' => 'bk-cassette-11-34',
                'name' => 'Cassette 11-34',
                'description' => 'A wide-range cassette for hills without a triple.',
                'price' => 79.0,
                'stock' => 7,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Drivetrain',
                'properties' => [
                    'Material' => ['Steel'],
                    'Terrain' => ['Gravel', 'Trail'],
                ],
                'variants' => ['Speed' => ['11-speed', '12-speed']],
            ],
            [
                'number' => 'bk-quick-link-2pack',
                'name' => 'Chain Quick Link (2 pack)',
                'description' => 'Two reusable quick links, so a chain can be split on the road.',
                'price' => 8.9,
                'stock' => 40,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Drivetrain',
                'properties' => [
                    'Material' => ['Steel'],
                ],
                'variants' => ['Speed' => ['11-speed', '12-speed']],
            ],
            [
                'number' => 'bk-chainring-40t',
                'name' => 'Chainring 40T',
                'description' => 'A narrow-wide 40-tooth ring for single-chainring drivetrains.',
                'price' => 54.0,
                'stock' => 6,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Drivetrain',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Terrain' => ['Gravel', 'Trail'],
                ],
            ],
            [
                'number' => 'bk-bottom-bracket-threaded',
                'name' => 'Bottom Bracket Threaded',
                'description' => 'A threaded bottom bracket with sealed cartridge bearings.',
                'price' => 39.0,
                'stock' => 11,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Drivetrain',
                'properties' => [
                    'Material' => ['Steel', 'Alloy'],
                ],
            ],
            [
                'number' => 'bk-derailleur-hanger',
                'name' => 'Derailleur Hanger Universal',
                'description' => 'A replaceable hanger for frames using the universal standard.',
                'price' => 21.0,
                'stock' => 13,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Drivetrain',
                'properties' => [
                    'Material' => ['Alloy'],
                ],
            ],

            // Ferrolane — Components/Cockpit
            [
                'number' => 'bk-stem-alloy-90',
                'name' => 'Alloy Stem 90mm',
                'description' => 'A 6-degree forged alloy stem with a four-bolt faceplate.',
                'price' => 44.0,
                'stock' => 10,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Cockpit',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Mounting' => ['Handlebar'],
                ],
            ],
            [
                'number' => 'bk-stem-alloy-100',
                'name' => 'Alloy Stem 100mm',
                'description' => 'The same forged stem, one length longer.',
                'price' => 44.0,
                'stock' => 8,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Cockpit',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Mounting' => ['Handlebar'],
                ],
            ],
            [
                'number' => 'bk-handlebar-carbon-flared',
                'name' => 'Carbon Handlebar Flared 420mm',
                'description' => 'A flared drop bar in carbon, 420 mm at the hoods.',
                'price' => 129.0,
                'stock' => 3,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Cockpit',
                'properties' => [
                    'Material' => ['Carbon'],
                    'Terrain' => ['Gravel'],
                    'Mounting' => ['Stem'],
                ],
            ],
            [
                'number' => 'bk-handlebar-alloy-440',
                'name' => 'Alloy Handlebar 440mm',
                'description' => 'A wide alloy drop bar with a compact drop.',
                'price' => 49.0,
                'stock' => 9,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Cockpit',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Terrain' => ['Gravel', 'Road'],
                    'Mounting' => ['Stem'],
                ],
            ],
            [
                'number' => 'bk-headset-spacer-kit',
                'name' => 'Headset Spacer Kit',
                'description' => 'Alloy spacers in four heights, plus a top cap.',
                'price' => 9.9,
                'stock' => 32,
                'manufacturer' => 'Ferrolane',
                'category' => 'Components/Cockpit',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Mounting' => ['Fork'],
                ],
            ],

            // Ferrolane — Seatposts
            [
                'number' => 'bk-seatpost-alloy',
                'name' => 'Alloy Seatpost',
                'description' => 'A two-bolt alloy seatpost with 20 mm of setback.',
                'price' => 49.0,
                'stock' => 10,
                'manufacturer' => 'Ferrolane',
                'category' => 'Seatposts',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Mounting' => ['Frame'],
                ],
                'variants' => ['Diameter' => ['27.2 mm', '30.9 mm', '31.6 mm']],
            ],
            [
                'number' => 'bk-seatpost-dropper',
                'name' => 'Dropper Post 100mm',
                'description' => 'A 100 mm dropper with an under-bar lever.',
                'price' => 199.0,
                'stock' => 4,
                'manufacturer' => 'Ferrolane',
                'category' => 'Seatposts',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Terrain' => ['Trail'],
                    'Mounting' => ['Frame'],
                ],
                'variants' => ['Diameter' => ['30.9 mm', '31.6 mm']],
            ],

            // Kestrel Works — Saddles
            [
                'number' => 'bk-saddle-road-carbon',
                'name' => 'Road Saddle Carbon Rails',
                'description' => 'A short-nose road saddle on carbon rails, with a full-length relief channel.',
                'price' => 149.0,
                'stock' => 5,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Saddles',
                'properties' => [
                    'Material' => ['Carbon'],
                    'Terrain' => ['Road'],
                    'Mounting' => ['Seatpost'],
                ],
            ],
            [
                'number' => 'bk-saddle-comfort-gel',
                'name' => 'Comfort Saddle Gel',
                'description' => 'A wider gel saddle for upright riding.',
                'price' => 44.0,
                'stock' => 15,
                'manufacturer' => 'Kestrel Works',
                'category' => 'Saddles',
                'properties' => [
                    'Material' => ['Gel'],
                    'Terrain' => ['Commuting'],
                    'Mounting' => ['Seatpost'],
                ],
            ],

            // Ferrolane — Accessories/Computers & Mounts
            [
                'number' => 'bk-computer-gps',
                'name' => 'GPS Computer 2.4',
                'description' => 'A 2.4-inch GPS computer with routing and a twenty-hour battery.',
                'price' => 219.0,
                'stock' => 4,
                'manufacturer' => 'Ferrolane',
                'category' => 'Accessories/Computers & Mounts',
                'properties' => [
                    'Weather protection' => ['Waterproof'],
                    'Material' => ['Plastic'],
                    'Mounting' => ['Handlebar'],
                ],
            ],
            [
                'number' => 'bk-sensor-speed-cadence',
                'name' => 'Speed & Cadence Sensor',
                'description' => 'A magnet-free sensor pair that pairs over both common protocols.',
                'price' => 44.0,
                'stock' => 13,
                'manufacturer' => 'Ferrolane',
                'category' => 'Accessories/Computers & Mounts',
                'properties' => [
                    'Weather protection' => ['Waterproof'],
                    'Material' => ['Plastic'],
                ],
            ],
            [
                'number' => 'bk-mount-out-front',
                'name' => 'Out-Front Mount Alloy',
                'description' => 'An alloy out-front mount for 31.8 mm bars.',
                'price' => 32.0,
                'stock' => 16,
                'manufacturer' => 'Ferrolane',
                'category' => 'Accessories/Computers & Mounts',
                'properties' => [
                    'Material' => ['Alloy'],
                    'Mounting' => ['Handlebar'],
                ],
            ],
        ];
    }
}
