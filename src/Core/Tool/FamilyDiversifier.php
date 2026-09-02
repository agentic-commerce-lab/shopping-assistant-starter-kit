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
 * ## The algorithm
 *
 * One pass over `$survivors` in its existing relevance order, keeping the first card of every distinct
 * family, then cut to `$limit`. **The limit is a ceiling, not a quota**: three matching families
 * answer a request for five cards with three.
 *
 * ## Why there is no second pass any more (reversing spec decision F2)
 *
 * F2 originally added a backfill: if the first pass left slots free, the remaining already-represented
 * cards were appended until the limit was reached, so that *"a shopper never sees fewer cards than a
 * plain `array_slice` would have shown"*.
 *
 * Measured on the staging shop, 2026-09-02: *"I am looking for a good jacket"* matched 18 variants
 * across **three** families, the model asked for five, and the backfill spent the two spare slots on a
 * second and third Packable Rain Jacket. What reached the model was five cards of which three were
 * one jacket. Whether the shopper saw the duplicate depended on the model — one run silently
 * de-duplicated, another listed all three — which is how this was first reported as the model
 * recommending "all sizes of the same product".
 *
 * **The backfill was never buying what its rule claimed.** A duplicate card is not an extra product;
 * it is the same product with the same name and picture on a second row. And the variant it carried
 * is not lost by dropping it: {@see TruncatedFamilies} runs immediately after and discloses every
 * family that was cut, with its option values — so the size run moves from a card that reads as a
 * separate product into a disclosure that reads as an option, which is what it is.
 *
 * The case that genuinely gets thinner is a query naming one product: *"show me the Gravel Jacket"*
 * now returns one card rather than five of its variants. That is the same trade in the other
 * direction, and the disclosure covers it identically.
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
        $representatives = [];

        foreach ($survivors as $card) {
            $key = self::familyKey($card);

            if (isset($seenFamilies[$key])) {
                continue;
            }

            $seenFamilies[$key] = true;
            $representatives[] = $card;
        }

        return \array_slice($representatives, offset: 0, length: $limit);
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
