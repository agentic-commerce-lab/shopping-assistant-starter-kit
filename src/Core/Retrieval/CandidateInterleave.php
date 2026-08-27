<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Merges the candidate windows of several search terms by taking one from each in turn.
 *
 * ## Why round-robin rather than concatenation
 *
 * Measured 2026-08-26 against a 15,218-unit fashion catalogue: asked what to wear to a wedding, the
 * assistant described dresses AND suits, and the shopper was shown five men's suits — because it had
 * searched twice and the shop renders only the most recent search. The fix is one search carrying both
 * terms; but concatenating their results reproduces the same one-sided row from a single call, because
 * the first term's family fills the limit before the second term is reached.
 *
 * Interleaving is what makes the bound shared instead of first-come: a limit of six over
 * `["occasion dress", "occasion suit"]` returns three of each **going into narrowing** — see the note
 * below on what `FamilyDiversifier` can do to that balance afterward.
 *
 * ## Order was the whole contract — until FamilyDiversifier
 *
 * `VariantResolver` substitutes in place, `BlocklistFilter` and `RedundantParentFilter` only remove —
 * neither re-sorts, so the order this class produces survives both untouched. The one exception, added
 * 2026-08-27: {@see \Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier}, the narrowing step itself,
 * deliberately reorders survivors by family. It still treats this class's per-term balance as its
 * *starting* order, and it demotes same-family duplicates behind other families' cards regardless of
 * which search term produced them — it has no notion of term at all. When one term contributes many
 * distinct families and another contributes few (or one large family with many variants), this can skew
 * the rendered set toward the richer term; `FamilyDiversifier` cannot correct for that because it never
 * sees which term a card came from. So "the order this produces is the order of the cards" is no longer
 * literally true past that point. See `FamilyDiversifier`'s own docblock for why and how.
 */
final class CandidateInterleave
{
    private function __construct() {}

    /**
     * @param list<list<ProductCard>> $perTerm one candidate window per search term, in term order
     *
     * @return list<ProductCard> deduplicated by id, first occurrence winning
     */
    public static function of(array $perTerm, int $cap): array
    {
        $merged = [];
        $seen = [];
        $depth = 0;
        $longest = self::longest($perTerm);

        while ($depth < $longest && \count($merged) < $cap) {
            foreach ($perTerm as $cards) {
                $card = $cards[$depth] ?? null;

                if (null === $card || isset($seen[$card->id]) || \count($merged) >= $cap) {
                    continue;
                }

                $seen[$card->id] = true;
                $merged[] = $card;
            }

            ++$depth;
        }

        return $merged;
    }

    /** @param list<list<ProductCard>> $perTerm */
    private static function longest(array $perTerm): int
    {
        $longest = 0;

        foreach ($perTerm as $cards) {
            $longest = max($longest, \count($cards));
        }

        return $longest;
    }
}
