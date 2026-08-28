<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;

/**
 * Picks the advanced price that applies at a given quantity.
 *
 * `SalesChannelProductEntity::getCalculatedPrices()` is not a list of ranges, and reading it as
 * one is the defect this class exists to prevent. `ProductPriceCalculator::calculateAdvancePrices()`
 * sorts the source rows by `quantityStart` and stores each price with
 * `quantity = quantityEnd ?? quantityStart` — so a **bounded** tier carries its END and a final
 * **open-ended** tier carries its START. For a 1–9 / 10–49 / 50+ price that is `[9, 49, 50]`, and
 * "the first entry whose quantity is at least 120" matches nothing at all.
 *
 * Ends are therefore never read. Entry *i* begins one unit above entry *i-1*'s stored quantity,
 * entry 0 begins at one, and the applicable entry is the last one that has begun. A quantity above
 * a bounded final tier selects that tier rather than nothing, which is both the safe answer and the
 * one Shopware's own listing card reaches for when it takes `calculatedPrices.last`.
 *
 * Shopware has no helper for this — `PriceCollection` offers `sum()`, `getHighestTaxRule()` and no
 * quantity lookup — so it is written once, here, rather than at each call site.
 */
final readonly class DalApplicablePrice
{
    public function forQuantity(PriceCollection $tiers, int $quantity): ?CalculatedPrice
    {
        $entries = array_values($tiers->getElements());

        if ($entries === []) {
            return null;
        }

        // A quantity below one is a caller error, not a reason to hand a shopper a card with no
        // price on it. The smallest real order is one unit, so that is what it prices.
        $quantity = max(1, $quantity);

        $selected = $entries[0];
        $previous = $entries[0];

        foreach ($entries as $index => $entry) {
            if ($index === 0) {
                continue;
            }

            if (($previous->getQuantity() + 1) > $quantity) {
                break;
            }

            $selected = $entry;
            $previous = $entry;
        }

        return $selected;
    }
}
