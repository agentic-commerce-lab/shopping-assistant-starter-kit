<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * One member of a bundle: what it is, how many of it, and whether the shopper may decline it.
 *
 * **A composition fact, not a figure.** `quantity` is the bundle's own `bundle_item.quantity` — how
 * many of this member the bundle contains — and never a stock level, a price or a cart quantity.
 * Nothing here lets the model quote a number it did not earn, which is the line
 * {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary} draws and this stays on the safe
 * side of.
 *
 * **`required` is load-bearing, and the two halves of a bundle disagree about it.** Shopware
 * Commercial derives a bundle's stock from its **required** items only, and sums **every** item —
 * optional ones included — into its price (`BundlePriceCalculator::sumCheapestPrices()` filters on
 * nothing; verified against a live bundle whose 73.57 was 83.60 less 12%, where the required items
 * alone came to 45.58). So a card that cannot tell the two apart quotes a maximum as though it were
 * the price. This flag is what lets the answer say which items the figure covers.
 *
 * The member's own price is deliberately absent. Commercial calculates the bundle's price as one
 * figure and the shop charges that; per-item prices here would invite the model to add them up and
 * quote a total nobody calculated.
 */
final readonly class BundleItem
{
    public function __construct(
        public string $name,
        public int $quantity,
        public bool $required,
    ) {}
}
