<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * Enforces a stated price range against the price the shopper is actually shown.
 *
 * ## Why the database filter is not enough
 *
 * The `price` range in a {@see ProductQuery} becomes a SQL `RangeFilter` over `product.price`, whose
 * accessor resolves currency and gross/net and **nothing else**. Every price that depends on who is
 * asking — customer-group prices, Rule Builder prices, Shopware Commercial's B2B Individual Pricing
 * and Custom Pricing — is applied to the *loaded entity*, after the query, by subscribers on
 * `sales_channel.product.loaded`. Commercial's own indexer writes those into
 * `b2b_components_individual_pricing_computed_cache`, never into `product.price` or
 * `product.cheapest_price_accessor`, so no column the filter could read carries them. Switching the
 * filter to `cheapestPrice` does not help for the same reason.
 *
 * **Measured 2026-09-01** on a Commercial shop, as a B2B customer with a +50% surcharge asking for
 * "jerseys under 100 euros": three cards came back at 123.17, 74.85 and 105.32 EUR, under the
 * model's own sentence *"There are quite a few jerseys under 100 euros"*, and the one it recommended
 * was the 105.32 one. The prose and the card contradicted each other in the same reply. Grounding
 * was not at fault — the prices rendered are the shopper's real prices, server-side, exactly as
 * designed. The candidate set was simply chosen with someone else's prices.
 *
 * ## What this fixes, and what it does not
 *
 * It removes cards that **break** the stated range. That is the shopper-visible half of the defect
 * and it is safe unconditionally: a product outside an explicit constraint was never a valid answer,
 * so dropping it cannot make a reply worse, and where list price equals the shopper's price — every
 * guest, and every shop with no price rules — it drops nothing at all.
 *
 * It does **not** recover products the SQL filter wrongly excluded. Under a *discount* a product
 * priced above the ceiling in the database can be inside it for this shopper, and no post-filter can
 * see a row the query never returned. Fixing that means not pre-filtering on price at all when the
 * context can move prices, and paying for it with a wider candidate window; it is written up as
 * remaining work in the B2B design's section 7.5 rather than guessed at here.
 */
final class StatedBudget
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $cards
     *
     * @return list<ProductCard>
     */
    public static function keep(array $cards, ProductQuery $query): array
    {
        $range = self::priceRange($query);

        // Returned by identity, not rebuilt: the no-constraint path is the common one and must cost
        // nothing, and `assertSame` in the tests locks that in.
        if ($range === null) {
            return $cards;
        }

        $bounds = PriceRange::of($range);

        return array_values(array_filter(
            $cards,
            // 0.00 is what ProductCard reports when the price could not be read at all — see
            // DalProductCardMapper::fallbackPrice(), which returns null for a product the price
            // calculator never touched (rulings R48, R49). Such a product is exempt from a floor:
            // dropping it would hide a real article behind a defect in its price data.
            static fn(ProductCard $card): bool => $bounds->contains($card->price, $card->price === 0.0),
        ));
    }

    /**
     * The bounds of the query's own `price` clause, or null when it has none.
     *
     * Read back off the query rather than taken from the {@see ShopperIntent} on purpose: this way
     * the range enforced here is, by construction, the same range that was sent to the database. A
     * second source for the same number is a second thing that can drift.
     *
     * @return array<array-key, mixed>|null
     */
    private static function priceRange(ProductQuery $query): ?array
    {
        foreach ($query->filters as $clause) {
            if (
                $clause instanceof FilterClause
                && $clause->field === 'price'
                && $clause->operator === FilterOperator::Range
                && \is_array($clause->value)
                && $clause->value !== []
            ) {
                return $clause->value;
            }
        }

        return null;
    }
}
