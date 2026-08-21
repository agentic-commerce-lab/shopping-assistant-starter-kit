<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * Whose stock figure a card is carrying.
 *
 * The distinction is D4 at the data layer: a figure that belongs to the exact unit a shopper can
 * buy is an answer, and a figure that belongs to a family of units is a number they did not ask
 * for. The storefront hangs a disclosure and the absence of one-click purchase on this, which is
 * why the three cases below must stay three.
 *
 * **Correction, 2026-08-21.** There were only two cases, and {@see self::Parent} carried both
 * "this is a product family whose variant was not resolved" and "this is a plain product that has
 * no variants at all". Measured on the live shop, that conflation cost every simple product its
 * add-to-cart button and printed *"Stock shown for the product, not this variant"* beside products
 * with no variants — `fx-007`, `fx-008` and `fx-017` in one reply. The stock figure of a product
 * with no variants is its own; nothing about it is unresolved, and there is nothing for a shopper
 * to choose before buying it.
 */
enum StockSource: string
{
    /** A child row. Its price and stock are its own, and it is the unit a shopper buys. */
    case Variant = 'variant';

    /**
     * A family parent standing in for children nobody has picked from yet. Its stock is the
     * family's aggregate — Bib Shorts reports 12 while Black/M has 5 and Black/L has 7 — so it
     * must never be presented as a buyable unit's figure.
     */
    case Parent = 'parent';

    /**
     * A product with no variants. Its stock is its own, exactly like a variant's, and it is
     * directly buyable — the difference from {@see self::Variant} is only that there was never
     * anything to resolve.
     */
    case Product = 'product';

    /**
     * Classifies one catalogue row from the two facts that decide it, and nothing else.
     *
     * @param ?string $parentId   the row's parent, or null when it is a top-level product
     * @param ?int    $childCount how many variants the row has; null when unread, which is
     *                            treated as none — an unknown count is not evidence of children,
     *                            and reading it as such would put every simple product back behind
     *                            the unresolved-variant disclosure
     */
    public static function forProductRow(?string $parentId, ?int $childCount): self
    {
        if ($parentId !== null) {
            return self::Variant;
        }

        return ($childCount ?? 0) > 0 ? self::Parent : self::Product;
    }
}
