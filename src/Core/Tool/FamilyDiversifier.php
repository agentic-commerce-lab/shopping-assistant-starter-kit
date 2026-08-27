<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Picks a family-diverse subset of the narrowed shortlist instead of a plain relevance-ranked prefix.
 *
 * ## The failure it fixes
 *
 * Relevance ranking has no notion of "one product vs. its size run": same-family variants share a name
 * and description, so they rank adjacently. Measured live, `docs/superpowers/reports/
 * 2026-08-27-fashion-catalogue-seeded.md`: *"show me dresses"* and *"show me yoga clothes"* both
 * returned cards that were *"multiple variants of the same families"* rather than a spread of styles,
 * even though the search matched over a thousand dresses. See
 * `docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md` for the full measurement.
 *
 * ## The algorithm (spec decision F2)
 *
 * Two passes over `$survivors` in its existing relevance order. Pass one keeps the first card of every
 * distinct family until `$limit` cards are kept or `$survivors` runs out — this is the diversifying
 * pass. Pass two, only if pass one filled fewer than `$limit`, appends the remaining (already-
 * represented-family) cards in their original order until the limit is reached. A shopper never sees
 * fewer cards than a plain `array_slice($survivors, 0, $limit)` would have shown — diversifying never
 * makes the shortlist smaller, only more varied.
 *
 * A single-family input still produces the plain-prefix result, but not because pass one keeps
 * everything: only the first card is a first-occurrence (`$firstPass` holds one element), and pass
 * two's backfill immediately recovers the rest from `$leftover` in their original order — so the
 * two-pass split is invisible in the output, even though it's exercised internally.
 *
 * ## Where this runs, and why it must be here
 *
 * After variant resolution, the blocklist, and {@see \Swag\AssistantStarterKit\Core\Grounding\RedundantParentFilter}
 * — never earlier. Diversifying before those would risk selecting a "diverse" set that then loses
 * members to blocklisting or redundant-parent removal, undermining the diversity work before the
 * shopper ever sees it (spec decision F4). This class performs the actual narrowing to the model's
 * requested limit; {@see \Swag\AssistantStarterKit\Core\Tool\SearchProductsTool} no longer slices
 * separately — see that class and {@see \Swag\AssistantStarterKit\Core\Retrieval\CandidateInterleave}'s
 * docblocks, both updated alongside this class for exactly that reason.
 */
final class FamilyDiversifier
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $survivors relevance-ranked, already fully resolved and filtered
     *
     * @return list<ProductCard> at most `$limit` cards, drawn only from `$survivors`
     */
    public static function of(array $survivors, int $limit): array
    {
        $seenFamilies = [];
        $firstPass = [];
        $leftover = [];

        foreach ($survivors as $card) {
            $key = self::familyKey($card);

            if (isset($seenFamilies[$key])) {
                $leftover[] = $card;

                continue;
            }

            $seenFamilies[$key] = true;
            $firstPass[] = $card;
        }

        if (\count($firstPass) >= $limit) {
            return \array_slice($firstPass, offset: 0, length: $limit);
        }

        $needed = $limit - \count($firstPass);

        return [...$firstPass, ...\array_slice($leftover, offset: 0, length: $needed)];
    }

    /**
     * What counts as "one family" for diversification: a family of variants shares `parentId`; a
     * standalone product (no parent) is its own family of one, keyed by its own id.
     *
     * **Deliberately not** the same notion {@see \Swag\AssistantStarterKit\Core\Grounding\RedundantParentFilter}
     * or {@see TruncatedFamilies::groupByFamily()} use — both of those exclude a standalone product from
     * "family" on purpose, because grouping it would invent a family the catalogue does not have for
     * their purposes (superseded-parent removal, truncation disclosure). Here, two different standalone
     * products are still two different things worth showing separately, so each needs its own key
     * (spec decision F3).
     */
    public static function familyKey(ProductCard $card): string
    {
        return $card->parentId ?? $card->id;
    }
}
