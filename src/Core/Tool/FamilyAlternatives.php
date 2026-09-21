<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FamilyVariantLookup;

/**
 * Every sold-out card in one result, answered in **one read per family**.
 *
 * The search path, and the one that matters. Traced against staging 2026-09-21, a shopper asking for
 * one sold-out size produced a single `search_products` call whose result carried the family's sizes
 * and nothing about which of them could be bought — so the model recommended a different product,
 * the only thing in the result whose availability it could see. That is not disobedience; it was the
 * one honest answer available to it.
 *
 * Split from {@see AvailableAlternatives} rather than suppressed: that class is measured against a
 * cyclomatic budget summed across its methods, and batching is a different job from deciding what
 * one card may be offered. It owns the rule; this owns doing it once per family.
 */
final class FamilyAlternatives
{
    private function __construct() {}

    /**
     * Keyed by the card's POSITION, because the caller merges these into summaries built from the
     * same list. A flat list would attach one family's sizes to another family's product the moment
     * a result holds two.
     *
     * @param list<ProductCard> $cards
     *
     * @return array<int, array{alternatives?: list<array<string, string>>, alternatives_truncated?: true}>
     */
    public static function forAll(array $cards, ?FamilyVariantLookup $lookup, CatalogScope $scope): array
    {
        if ($lookup === null) {
            return [];
        }

        // Only a sold-out variant has a question to answer; reading a family for a card that is in
        // stock is a round trip spent on noise. Filtered first so the loop below has one job.
        $asking = array_filter(
            $cards,
            static fn(ProductCard $card): bool => AvailableAlternatives::familyOf($card) !== null,
        );

        $keys = [];
        $siblings = [];

        foreach ($asking as $index => $card) {
            $parentId = (string) AvailableAlternatives::familyOf($card);
            $siblings[$parentId] ??= $lookup->variantsOf($parentId, $scope);

            $key = AvailableAlternatives::fromSiblings($card, $siblings[$parentId]);
            $keys += $key === [] ? [] : [$index => $key];
        }

        return $keys;
    }
}
