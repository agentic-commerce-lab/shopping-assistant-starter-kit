<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode;

/**
 * A gateway that can describe its own category tree.
 *
 * **Deliberately a separate interface rather than a method on {@see CommerceGatewayInterface}**, for the
 * reason {@see BatchProductLookup}, {@see FamilyVariantLookup} and {@see MatchCountReader} all give:
 * that one is `@api`, so adding a method breaks every gateway a merchant has written.
 *
 * ## Why it exists — and it is not the reason first proposed
 *
 * The original design argued the model could not find occasion wear without a view of the tree.
 * Measured 2026-08-26/27, that was largely false: it reformulates "wedding" into "occasion dress" on its
 * own.
 *
 * The real gap is what happens when a search finds **nothing**. Measured on the local shop, which sells
 * cycling gear and has zero products matching dress, suit, gown, formal, wedding, bridal or tuxedo:
 *
 * > "The search for wedding attire came up empty. Could you share more details about what you are
 * > looking for, such as a specific style, item type, colour, or size, so I can try different search
 * > terms for you?"
 *
 * That sends the shopper hunting for words that cannot succeed. The assistant is boxed in: the absence
 * rule correctly forbids *"we don't sell that"*, because an empty search is not proof of absence — so
 * the only move left is to imply a better phrasing exists. It has no way to say what the shop DOES
 * have, because nothing tells it.
 *
 * That is what this closes. Not "help the model guess search words", but **let it orient a shopper
 * whose search found nothing**, honestly and without claiming absence.
 *
 * ## What an implementation owes the caller
 *
 * `$scope` must be honoured, both halves: a blocked category is not returned, and when
 * `includeCategoryIds` is non-empty only categories within that set are. Describing the tree must not
 * become a way to enumerate what the merchant chose to hide.
 *
 * **No figures.** {@see CategoryNode} carries none, and an implementation must not smuggle one into a
 * name.
 *
 * Order is part of the contract, unlike {@see FamilyVariantLookup}: return nodes sorted by name, so a
 * second call is reproducible and a test can index the result.
 */
interface CategoryTreeReader
{
    /**
     * The children of `$parentId`, or the shop's top level when it is null.
     *
     * An unknown `$parentId` returns an empty list — never the top level. A caller that mistyped an id
     * must not silently get the whole tree back.
     *
     * @return list<CategoryNode>
     */
    public function categories(?string $parentId, CatalogScope $scope): array;
}
