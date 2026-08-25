<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * What a search held back about a family it truncated: how many variants exist, and which option
 * values they carry.
 *
 * ## Why this exists
 *
 * {@see ToolProductSummary}'s docblock records the argument that put option values in the reply at
 * all: *"with opaque ids, identifying one of N candidates costs N tool calls, so any family larger
 * than the call budget is unanswerable."* This is the same arithmetic one level up. Return five of
 * thirty variants with no signal that twenty-five more exist, and a family larger than the RETURN
 * limit is unanswerable — measured, 0/3 runs on both archetypes of `scale_family_beyond_window`.
 *
 * The same docblock also justified returning options on the grounds that *"the system prompt already
 * carries the catalogue's own vocabulary, including every option value."* Scale broke that: the
 * vocabulary block is capped at 1,500 characters and sends 66 of 106 values on a real
 * 10,000-product shop. This class removes the dependency rather than repairing it — the reply becomes
 * self-sufficient about options.
 *
 * ## What it must never carry
 *
 * No price, stock, delivery time or URL. The cards it reads have all four; the summaries it writes
 * have none. `ToolProductSummary` says *"Never widen this"* and the same rule holds here — see
 * `TruncatedFamiliesTest::testNoFigureEverReachesTheSummary`, which asserts it against the encoded
 * output rather than trusting the code to be read.
 *
 * @phpstan-type FamilySummary array{
 *     name: string,
 *     shown: int,
 *     variants: int,
 *     options: array<string, list<string>>,
 *     options_truncated?: bool,
 * }
 */
final class TruncatedFamilies
{
    /** The per-group value cap, owned by {@see FamilyOptionValues} and re-exposed for callers. */
    public const MAX_OPTION_VALUES = FamilyOptionValues::MAX_VALUES;

    private function __construct() {}

    /**
     * @param list<ProductCard> $survivors everything retrieval and filtering produced
     * @param list<ProductCard> $returned  the narrowed slice the model actually receives
     *
     * @return list<FamilySummary>
     */
    public static function of(array $survivors, array $returned): array
    {
        $shownPerFamily = self::countByFamily($returned);
        $summaries = [];

        foreach (self::groupByFamily($survivors) as $familyId => $members) {
            $shown = $shownPerFamily[$familyId] ?? 0;

            // Only a family that lost members has anything to disclose. One returned whole is
            // already fully described by the `products` array beside it.
            if ($shown >= \count($members)) {
                continue;
            }

            $summaries[] = self::summarise($members, $shown);
        }

        return $summaries;
    }

    /**
     * Cards keyed by the family they belong to, standalone products excluded.
     *
     * A card with no `parentId` is its own product, not a family of one — grouping it would invent a
     * family the catalogue does not have and then report it as truncated.
     *
     * @param list<ProductCard> $cards
     *
     * @return array<string, non-empty-list<ProductCard>>
     */
    private static function groupByFamily(array $cards): array
    {
        $families = [];

        foreach ($cards as $card) {
            if ($card->parentId === null) {
                continue;
            }

            $families[$card->parentId][] = $card;
        }

        return $families;
    }

    /**
     * @param list<ProductCard> $cards
     *
     * @return array<string, int>
     */
    private static function countByFamily(array $cards): array
    {
        $counts = [];

        foreach ($cards as $card) {
            if ($card->parentId === null) {
                continue;
            }

            $counts[$card->parentId] = ($counts[$card->parentId] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param non-empty-list<ProductCard> $members
     *
     * @return FamilySummary
     */
    private static function summarise(array $members, int $shown): array
    {
        ['options' => $options, 'truncated' => $truncated] = FamilyOptionValues::of($members);

        $summary = [
            // Every member of a family carries the family's name, so the first is as good as any.
            'name' => $members[0]->name,
            'shown' => $shown,
            'variants' => \count($members),
            'options' => $options,
        ];

        return $truncated ? [...$summary, 'options_truncated' => true] : $summary;
    }
}
