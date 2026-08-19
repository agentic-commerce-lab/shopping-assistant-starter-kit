<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Policy;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * BlocklistFilter is a compliance control, not a convenience.
 *
 * Blocked products must never reach the model's context, so they are removed
 * here, before prompting, rather than merely being flagged for the model to
 * reason about. A card is removed when its own id or its parent id is on the
 * blocked-product list, or when any entry of its category path is blocked.
 *
 * Checking `parentId` matters: a variant-bearing product has no sellable unit
 * keyed by its own parent id, so a merchant blocking that parent id must still
 * remove every variant card, not just a card that happens to share the id.
 */
final class BlocklistFilter
{
    /**
     * @param list<ProductCard> $cards
     *
     * @return array{cards: list<ProductCard>, removed: list<string>}
     */
    public function apply(array $cards, CatalogScope $scope): array
    {
        $kept = [];
        $removed = [];

        foreach ($cards as $card) {
            if ($this->isBlocked($card, $scope)) {
                $removed[] = $card->id;

                continue;
            }

            $kept[] = $card;
        }

        return ['cards' => $kept, 'removed' => $removed];
    }

    private function isBlocked(ProductCard $card, CatalogScope $scope): bool
    {
        if (\in_array($card->id, $scope->blockedProductIds, strict: true)) {
            return true;
        }

        if (null !== $card->parentId && \in_array($card->parentId, $scope->blockedProductIds, strict: true)) {
            return true;
        }

        foreach ($card->categoryPath as $category) {
            if (\in_array($category, $scope->blockedCategoryIds, strict: true)) {
                return true;
            }
        }

        return false;
    }
}
