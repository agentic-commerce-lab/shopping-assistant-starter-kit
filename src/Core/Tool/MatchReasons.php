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
 */
final class MatchReasons
{
    private function __construct() {}

    /**
     * @param list<ProductCard>                $returned
     * @param array<string, list<ProductCard>> $candidatesByTerm each search term's own candidate
     *                                                            window, keyed by the term the model
     *                                                            sent — see {@see \Swag\AssistantStarterKit\Core\Retrieval\MergedCandidates::byTerm()}
     *
     * @return array<string, list<string>> reason codes keyed by product id
     */
    public static function of(array $returned, array $candidatesByTerm): array
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

            if (1 === \count($returned)) {
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
