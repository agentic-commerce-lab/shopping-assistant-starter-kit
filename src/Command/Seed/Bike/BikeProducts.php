<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * The eighty-five products this seeder writes, as literals, across four shelf files.
 *
 * **Literals rather than a builder**, matching `FashionSeedTraps::all()` — the one other place in
 * this codebase holding hand-written catalogue data. A helper taking a product's seven fields as
 * arguments reads more compactly and is worse in the two ways that matter: it puts the fields in an
 * order a reader has to remember, and it sits over the parameter budget the rest of this project
 * holds itself to. This is a data table, so it is written as one.
 *
 * `properties` is required, not optional: it is the only thing
 * {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary} shows the model besides the name
 * and the variant axes, so a product without it is one the assistant cannot compare. See
 * {@see BikeCatalogue::descriptiveGroups()}.
 *
 * `variants` is a map of group => values, expanded to the full cross product by
 * {@see BikeVariantFamily}. A `stock` of zero means the whole family is out of stock: that is the
 * case ruling R75 and the `variant_stock` journey are about, and a catalogue where everything is
 * available cannot exercise it.
 *
 * @phpstan-type ProductSpec array{
 *     number: string,
 *     name: string,
 *     description: string,
 *     price: float,
 *     stock: int,
 *     manufacturer: string,
 *     category: string,
 *     properties: array<string, list<string>>,
 *     variants?: array<string, list<string>>,
 * }
 */
final class BikeProducts
{
    private function __construct() {}

    /** @return list<ProductSpec> */
    public static function all(): array
    {
        return [...BikeWear::all(), ...BikeRolling::all(), ...BikeParts::all(), ...BikeKit::all()];
    }
}
