<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * Drops a product family's parent card when the family's own variants are already in the set.
 *
 * Shopware's product search returns a family parent alongside its children, so the DAL gateway
 * hands back both. Measured on the live shop, 2026-08-21, *"what bib shorts do you sell?"*:
 *
 * | Card | Options | Stock | Add to cart |
 * |---|---|---|---|
 * | Bib Shorts | Black · L | In stock (7) | yes |
 * | Bib Shorts | Black · M | Low stock (5) | yes |
 * | Bib Shorts | — | In stock (12) | no, plus a note explaining the figure covers every variant |
 *
 * The third row is the same product named a third time, carrying an aggregate nobody can buy and a
 * disclosure answering a question the two rows above already settled. A shopper reads it as a
 * duplicate at best and a third product at worst.
 *
 * **This is a narrowing, not a rewrite.** The parent is removed only when a card whose `parentId`
 * is that parent is present in the same set, so a parent that is genuinely all the shop can say —
 * variant resolution found nothing to narrow to, and the note is the honest answer — survives
 * untouched. That case is the whole reason {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource::Parent}
 * and its disclosure exist (D4), and this filter must never be the thing that silences it.
 *
 * Applied before the model sees the result, not merely before rendering: a redundant candidate
 * costs the model a decision as well as the shopper a row.
 */
final class RedundantParentFilter
{
    /**
     * @param list<ProductCard> $cards
     *
     * @return list<ProductCard> the same cards in the same order, minus superseded parents
     */
    public static function apply(array $cards): array
    {
        $representedParents = [];

        foreach ($cards as $card) {
            if ($card->parentId !== null) {
                $representedParents[$card->parentId] = true;
            }
        }

        if ($representedParents === []) {
            return $cards;
        }

        return array_values(array_filter(
            $cards,
            // `StockSource::Parent` rather than "has no parentId": a standalone product also has
            // none, and removing one because an unrelated family's variant is present would delete
            // the answer to "do you sell bottle cages?".
            static fn(ProductCard $card): bool => (
                StockSource::Parent !== $card->stockSource
                || !isset($representedParents[$card->id])
            ),
        ));
    }
}
