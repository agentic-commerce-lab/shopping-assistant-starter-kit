<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The one tax rule a seeded catalogue prices against: its id **and** its rate.
 *
 * The two travel together because a payload needs both and they are one fact about one row. The
 * `taxId` goes on the product; the rate is what {@see SizeFamily::grossPrice()} derives `net` from,
 * since the seeded figure is a gross price. Passing only the id is what let this catalogue store
 * `net == gross` — a pair that cannot both be true at any non-zero rate, invisible on a gross-display
 * storefront and glaring the moment B2B pricing touched it. See that method for the measurement.
 *
 * This is deliberately **not** the kind of grouping {@see \Swag\AssistantStarterKit\Command\Seed\Bike\ShopTaxonomy}'s
 * docblock argues against. A category id and a manufacturer id share nothing but the database they
 * came from; a tax rule's id and its rate are two columns of the same row, and every caller that
 * wants one wants the other.
 */
final readonly class SeedTax
{
    public function __construct(
        public string $id,
        public float $rate,
    ) {}
}
