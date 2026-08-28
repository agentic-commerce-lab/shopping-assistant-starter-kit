<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Deterministic, code-computed reason codes for why a returned product was shown — the retrieval
 * mechanics the model has no visibility into today, never a personalization or preference signal
 * (there is no shopper profile anywhere in this codebase, and this class must not become one).
 *
 * Reuses the `reasonCode` convention {@see \Swag\AssistantStarterKit\Core\Policy\BlocklistFilter}
 * already established, rather than inventing a new claim shape. No audit is needed for these values
 * ({@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit} gains nothing new here): they are
 * closed, code-based signals the pipeline computed and handed over, not open claims the model could
 * misstate — the one exception, `in_stock`, is already covered by the existing
 * {@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit::unbackedAvailabilityClaims()} audit.
 *
 * Deliberately excludes anything already guaranteed by construction: `QueryBuilder` applies the
 * model's own `priceMax`/`priceMin` as hard filters, so "this fits the stated budget" is true for
 * every result and needs no reason code.
 *
 * Known limitation, stated rather than hidden: when the model provides `options` alongside multiple
 * `terms`, {@see \Swag\AssistantStarterKit\Core\Tool\VariantResolver} can swap the returned card's id
 * to a resolved variant that no longer appears in any of {@see \Swag\AssistantStarterKit\Core\Retrieval\MergedCandidates::byTerm()}'s
 * per-term windows, so {@see self::firstMatchingTerm()} silently finds no match and emits no
 * `matched_term:*` code for that card. This is a degradation — a card that simply gets no term
 * reason — never a false claim, since nothing incorrect is stated either way.
 */
final class MatchReasons
{
    private function __construct() {}

    /**
     * @param list<ProductCard>                $returned
     * @param array<string, list<ProductCard>> $candidatesByTerm each search term's own candidate
     *                                                            window, keyed by the term the model
     *                                                            sent — see {@see \Swag\AssistantStarterKit\Core\Retrieval\MergedCandidates::byTerm()}
     * @param int                               $totalMatched     the true match count BEFORE
     *                                                             {@see \Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier}
     *                                                             narrows it down to `$returned` — i.e.
     *                                                             `\count($survivors)` at the
     *                                                             `search_products` call site, never
     *                                                             `\count($returned)`. `only_match`
     *                                                             must reflect whether the shop genuinely
     *                                                             had one match, not whether a `limit`
     *                                                             or diversification happened to narrow
     *                                                             many real matches down to one shown
     *                                                             card — the same tool reply's own
     *                                                             `matched` field already reports the
     *                                                             true count, so `only_match` must agree
     *                                                             with it.
     *
     * @return array<string, list<string>> reason codes keyed by product id
     */
    public static function of(array $returned, array $candidatesByTerm, int $totalMatched): array
    {
        $reasons = [];
        $multiTerm = \count($candidatesByTerm) > 1;

        foreach ($returned as $card) {
            $codes = [];

            $term = $multiTerm ? self::firstMatchingTerm($card->id, $candidatesByTerm) : null;

            if ($term !== null) {
                $codes[] = 'matched_term:' . $term;
            }

            if ($card->isInStock()) {
                $codes[] = 'in_stock';
            }

            if (1 === $totalMatched) {
                $codes[] = 'only_match';
            }

            $reasons[$card->id] = $codes;
        }

        return $reasons;
    }

    /** @param array<string, list<ProductCard>> $candidatesByTerm */
    private static function firstMatchingTerm(string $id, array $candidatesByTerm): ?string
    {
        foreach ($candidatesByTerm as $term => $cards) {
            if (self::containsId($cards, $id)) {
                return $term;
            }
        }

        return null;
    }

    /** @param list<ProductCard> $cards */
    private static function containsId(array $cards, string $id): bool
    {
        foreach ($cards as $card) {
            if ($card->id === $id) {
                return true;
            }
        }

        return false;
    }
}
