<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * Moves the units nobody can buy today to the end of a result list, and nothing else.
 *
 * ## Why this is not a DAL sorting
 *
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalCriteriaBuilder}'s docblock has claimed since
 * it was written that *"ranking's in-stock bias sorts a sold-out unit last"*. That bias lived only in
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureQueryFilter} — every eval run had it,
 * and no real shop ever did. Against a live catalogue a sold-out unit ranked exactly as high as an
 * available one.
 *
 * The obvious repair, `FieldSorting('stock', DESCENDING)` on the criteria, is wrong twice over. It
 * reorders by QUANTITY, so the warehouse-full least relevant match outranks the one the shopper
 * asked for; and it runs before the term score, so it replaces relevance rather than adjusting it.
 * A **stable partition in PHP** keeps the gateway's own order inside each group and only moves what
 * cannot be bought. It costs nothing worth measuring: the list is the retrieval window, tens of
 * cards, never the catalogue.
 *
 * ## Two things it deliberately does not do
 *
 * **A stated sort wins outright.** A shopper who asked for the cheapest gets price order with
 * nothing in front of it — promoting an available unit answers *"the cheapest one that happens to be
 * in stock"* to a question about the cheapest. The card still carries its stock figure, so
 * {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary} can still mark it `soldOut` and the
 * reply can say so. {@see \Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureQueryFilter} has
 * recorded the same reasoning for its own bias since it was written.
 *
 * **A family parent never sinks.** Its `stock` is its own column and not the sum of its children:
 * measured 2026-09-21 against a 118,232-row shop, 259 of 2,900 families read 0 on the parent row
 * while every variant underneath was in stock. Sinking those buries a product a shopper can buy
 * five sizes of. Whether a family can be bought is a question about its variants, and this class
 * is not holding them.
 */
final class SoldOutLast
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $cards in the gateway's own order
     *
     * @return list<ProductCard>
     */
    public static function apply(array $cards, ?PriceSort $sort): array
    {
        if ($sort !== null) {
            return $cards;
        }

        $buyable = [];
        $soldOut = [];

        foreach ($cards as $card) {
            if (self::sinks($card)) {
                $soldOut[] = $card;

                continue;
            }

            $buyable[] = $card;
        }

        return [...$buyable, ...$soldOut];
    }

    private static function sinks(ProductCard $card): bool
    {
        return $card->stockSource !== StockSource::Parent && !$card->isInStock();
    }
}
