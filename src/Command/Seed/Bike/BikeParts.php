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
                'description' => 'Two syringes, the fittings for the common lever and caliper ports, tubing and a catch bag, in a case. Brake fluid is not included, and the kit cannot tell you which one a brake takes: mineral oil and DOT are not interchangeable, so check the lever before buying fluid.',
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
                'description' => 'A drop-in steel gauge that shows when a chain has stretched past service, marked at 0.5 and 0.75 percent. It reads any derailleur chain from 8 to 12 speed. It measures the chain only, so a worn cassette still has to be judged by how a fresh chain sits on it.',
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
                'description' => 'A ratcheting torque wrench covering 2 to 14 Nm, supplied in a case with the 3, 4, 5 and 6 mm hex and T25 bits that carbon parts are torqued with. Set by turning the collar to the figure. It cannot be set below 2 Nm, so it will not do a small computer-mount bolt.',
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
                'description' => 'A splined steel lockring tool with a guide pin, cut for the twelve-notch pattern both common road and mountain cassettes use. It takes a 24 mm socket or a bench wrench, neither supplied, and it needs a chain whip to hold the cassette while the lockring turns.',
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
                'description' => 'Three glass-filled nylon levers that clip together into one block for a saddle bag. Nylon is chosen over steel because it will not gouge a rim bed or nick a tubeless seal. Stiff enough for a tight tubeless tyre, though a very tight bead can still snap one.',
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
                'description' => 'A three-size steel spoke key for truing at the roadside, cut for 3.2, 3.45 and 3.96 mm nipples. Which slot fits can be found by feel in the dark. A roadside tool rather than a workshop one: no dishing gauge, and a badly buckled wheel wants a truing stand.',
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
                'description' => 'A nickel-plated steel chain supplied with one quick link, so it can be fitted without a chain tool. Pick the speed to match the cassette, since a 12-speed chain is narrower than an 11. It comes at 116 links and is shortened to fit; the plating slows rust rather than stopping it.',
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
                'description' => 'A wide-range 11-34 steel cassette for hills without a triple, on a standard freehub body. Pick the speed to match the chain and shifter. A 34-tooth largest sprocket needs a rear derailleur rated to wrap it, which a short-cage road derailleur will not do.',
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
                'description' => 'Two reusable steel quick links, so a chain can be split and rejoined on the road without a chain tool. Pick the speed to match the chain, as the widths differ. Reusable rather than unlimited: a link opened several times wants replacing rather than another cycle.',
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
                'description' => 'A narrow-wide 40-tooth alloy ring for single-chainring drivetrains, where the alternating tooth profile is what holds the chain without a guide. Four-bolt on the 104 mm pattern, supplied without bolts. A single-ring ring only: it will not shift as half of a double.',
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
                'description' => 'A threaded bottom bracket with sealed cartridge bearings, a steel race and alloy cups, for a 68 or 73 mm English-threaded shell. Supplied with the plastic sleeve and no tool. Sealed means not serviceable: when the bearings feel rough the unit is replaced, not regreased.',
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
                'description' => 'A replaceable alloy hanger for frames built to the universal standard, supplied with its bolts. It is deliberately the softest part of the rear end, so it bends in a crash instead of the frame. A frame using its own proprietary hanger will not take it, so check the shape first.',
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
                'description' => 'A 90 mm forged alloy stem with 6 degrees of rise and a four-bolt faceplate, supplied with its bolts. It clamps a 31.8 mm bar and a 1 1/8 inch steerer. Forged rather than machined, so it is a little heavier than a carbon stem and far less fussy about clamp torque.',
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
                'description' => 'The same forged alloy stem one length longer at 100 mm, with the same 6 degrees of rise, four-bolt faceplate and bolts. It clamps a 31.8 mm bar and a 1 1/8 inch steerer. The extra centimetre stretches the reach without moving the bar height or the saddle.',
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
                'description' => 'A flared drop bar in carbon, 420 mm at the hoods and 460 mm at the drops, with a 31.8 mm clamp. The flare turns the drops outward for control on loose ground. Carbon wants a torque wrench at the stem, and a bar that has been crashed is replaced rather than inspected.',
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
                'description' => 'A wide alloy drop bar, 440 mm at the hoods with a compact 125 mm drop and a 31.8 mm clamp. The shallow drop keeps the lower position usable for riders who never reach a deep one. Alloy rather than carbon: heavier, and far more tolerant of a clamp done up by feel.',
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
                'description' => 'Alloy spacers in 2.5, 5, 10 and 20 mm with a top cap and its bolt, for a 1 1/8 inch steerer. Stacked under or over the stem to set bar height. Spacers can only use steerer that is already there: raising the bar past the cut length means a new fork, not more spacers.',
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
                'description' => 'A two-bolt alloy seatpost with 20 mm of setback, supplied with its clamp hardware. Pick the diameter to match the frame, since a post even half a millimetre under will slip however hard it is clamped. The two bolts set tilt finely, and no shim is included.',
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
                'description' => 'A 100 mm alloy dropper post with an under-bar lever and internal cable routing, supplied with the lever, cable and housing. Pick the diameter to match the frame. Internal routing needs a frame port for the cable, so an externally routed frame will not take this post.',
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
                'description' => 'A short-nose road saddle on carbon rails, 143 mm wide with a full-length relief channel. The short nose suits a low, rotated-forward position. Carbon rails are oval, so they need a seatpost clamp shaped for them: a clamp cut for round rails will crush them.',
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
                'description' => 'A wider gel saddle for upright riding, 175 mm across, with steel rails and a gel layer over the shell. The width supports a pelvis that is sitting up rather than rotated forward. It is the wrong shape for a low road position, where the same width chafes the thighs.',
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
                'description' => 'A 2.4-inch GPS computer with turn-by-turn routing and a twenty-hour battery, supplied with an out-front mount, a stem mount and a USB-C cable. The case is waterproof. It pairs with sensors and records to its own storage; no sensors are included and there is no mobile connection.',
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
                'description' => 'A magnet-free speed and cadence sensor pair that broadcasts over both common protocols, supplied with two coin cells, the rubber mounts and zip ties. Both cases are waterproof. They send to a computer or a phone and store nothing themselves, so an unrecorded ride is lost.',
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
                'description' => 'An alloy out-front mount for 31.8 mm bars, supplied with a 25.4 mm shim and its bolts. It carries a quarter-turn computer ahead of the bar, where the screen is in view without looking down. Rated for a computer and a small light, not for a camera or a phone.',
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
