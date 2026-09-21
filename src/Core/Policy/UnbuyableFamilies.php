<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Policy;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\StockedFamilyLookup;

/**
 * Drops a family whose every variant is sold out, when the merchant asked for sold-out products to
 * be left out.
 *
 * ## The failure it exists for, seen on staging rather than in a test
 *
 * 2026-09-21, `hideOutOfStock` on, asked *"Habt ihr lange Fahrradhandschuhe?"*: all six sold-out
 * variants of "Long Finger Gloves" were correctly withheld, and the family came back as a card —
 * picture, price and all — for a product nobody could buy in any size. The local catalogue had **no
 * such family**, which is why 2,217 green tests said nothing about it.
 *
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalDiscoveryFilters} is not wrong to let the
 * parent through. A parent row's `stock` is its own column and says nothing about its children —
 * measured, 259 of 2,900 families read 0 while every variant was in stock — so judging it there
 * would bury products a shopper can buy five sizes of. The question *"can anything in this family
 * be bought?"* is about the children, and a criteria filter is not holding them. So it is asked
 * here, once per result, after retrieval.
 *
 * ## What it deliberately does not touch
 *
 * **A variant or a standalone product.** Both carry their own stock, so retrieval already decided
 * about them. Re-deciding here would be a second opinion on a settled question, taken without the
 * family.
 *
 * **Anything at all when the setting is off.** A shop that shows products it has to reorder shows
 * families it has to reorder. Nothing here is a correctness rule of its own — it is the same
 * merchant decision, applied to the one row shape that could not answer for itself.
 *
 * **A gateway that cannot answer.** Null lookup means the result is left exactly as it was, which
 * is what every shop did before this class existed. A correctness fix that empties a reply on a
 * missing capability is worse than the defect.
 */
final class UnbuyableFamilies
{
    /**
     * @param list<ProductCard> $cards
     *
     * @return array{cards: list<ProductCard>, removed: list<string>}
     */
    public function apply(array $cards, ?StockedFamilyLookup $lookup, CatalogScope $scope): array
    {
        if (!$scope->hideOutOfStock || $lookup === null) {
            return ['cards' => $cards, 'removed' => []];
        }

        $familyIds = [];

        foreach ($cards as $card) {
            if ($card->stockSource === StockSource::Parent) {
                $familyIds[] = $card->id;
            }
        }

        // No families, no question, no query. Most searches never reach the gateway a second time.
        if ($familyIds === []) {
            return ['cards' => $cards, 'removed' => []];
        }

        $buyable = $lookup->familiesWithStock($familyIds, $scope);

        $kept = [];
        $removed = [];

        foreach ($cards as $card) {
            if ($card->stockSource === StockSource::Parent && !\in_array($card->id, $buyable, strict: true)) {
                $removed[] = $card->id;

                continue;
            }

            $kept[] = $card;
        }

        return ['cards' => $kept, 'removed' => $removed];
    }
}
