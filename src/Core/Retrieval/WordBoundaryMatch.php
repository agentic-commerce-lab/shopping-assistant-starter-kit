<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Drops a hit whose only trace of the shopper's words is a fragment sitting inside an unrelated one.
 *
 * ## The failure this exists for
 *
 * Reported from the staging shop, 2026-09-03. A shopper asked for *"a helmet with a cat print"*:
 *
 * > "The search found no helmets with a cat print, though it did return **Chain Wear Indicator**,
 * > which matched 'cat print' but is not a helmet."
 *
 * `cat` is inside "Indi**cat**or", and that is the whole of the match.
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalCriteriaBuilder} hands the term to
 * `Criteria::setTerm()`, which on the product repository does **not** use Shopware's product keyword
 * index. It goes through `CriteriaQueryBuilder` to the generic `EntityScoreQueryBuilder`, which emits
 * one `ContainsFilter` per token — `name LIKE '%cat%'`. Measured against the local shop, for proof
 * that this is the mechanism and not a guess:
 *
 * ```
 * probe --search="oat"  ->  Wrap Coat, Maxi Coat, Bodycon Coat, Tailored Coat, … (10 hits)
 * ```
 *
 * ## Why a word edge rather than a minimum token length
 *
 * Shopware's own product search already draws exactly this line.
 * `ProductSearchTermInterpreter::slop()` builds `keyword LIKE 'ct%'` patterns against the keyword and
 * `reversed LIKE 'tc%'` patterns against its reverse — prefixes of the word and prefixes of its
 * reverse, which is prefix-or-suffix and never a strict infix. So the storefront's own search box
 * would not have returned the Chain Wear Indicator either, and an assistant matching more loosely
 * than the shop it speaks for is the anomaly this removes.
 *
 * A minimum token length was the other candidate and it leaks: `print` is five characters and sits
 * inside "s**print**er".
 *
 * **Both edges count.** The suffix half is not symmetry for its own sake — it is German compounds. A
 * shopper searching `helm` has to reach a `Fahrradhelm`, and `schloss` a `Fahrradschloss`.
 *
 * ## Where it sits, and why that is the useful place
 *
 * {@see RetrievalPass} applies it to every card list it can return, so an infix-only hit reads as
 * "this search found nothing" — which hands the turn to the relaxations that already exist
 * ({@see UnmatchedOptionRetry}, {@see RelaxedTermRetry}) and, failing those, to
 * {@see \Swag\AssistantStarterKit\Core\Tool\NoMatchOrientation}. The shopper gets "the search found
 * nothing, here is what the shop does sell" instead of a chain gauge presented as a near-miss helmet.
 *
 * ## What it deliberately does not do
 *
 * **It never drops a card it cannot explain.** {@see ProductCard} is an allowlist and carries neither
 * the product number, the EAN, the manufacturer nor the custom search keywords — all of which the DAL
 * does search. A card holding no trace of the term at all therefore matched a field this class cannot
 * see, and is kept. Only a card that visibly contains a token, and contains it at no word edge, is
 * dropped.
 *
 * **It is not a relevance ranker.** It removes hits the shop's own search would not have produced;
 * ordering what remains is still the gateway's job.
 */
final class WordBoundaryMatch
{
    /**
     * Below this, a token cannot be the reason the gateway returned anything.
     *
     * Shopware drops shorter tokens before it searches (`shopware.dbal.token_minimum_search_length`),
     * and {@see \Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureTermMatcher} mirrors it at the
     * same three characters. Judging a card by a token the search never used would be inventing a
     * reason to drop it — and a one-letter token sits at a word edge somewhere in almost every name,
     * which would make this class a no-op in the other direction.
     */
    private const MIN_TOKEN_LENGTH = 3;

    private function __construct() {}

    /**
     * @param list<ProductCard> $cards
     *
     * @return list<ProductCard>
     */
    public static function keep(array $cards, ?string $term): array
    {
        $tokens = self::tokens($term);

        // Nothing to judge with — no term, or a term of only short words. Returning an empty list
        // here would turn "we cannot tell" into "we found nothing", which is the opposite of what
        // this class is for.
        if ($tokens === []) {
            return $cards;
        }

        return array_values(array_filter($cards, static fn(ProductCard $card): bool => self::admits($card, $tokens)));
    }

    /**
     * Name and description only, matching what {@see \Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureTermMatcher}
     * reads — and note that the real DAL searches the name and not the description, since
     * `ProductDefinition` gives `description` no `SearchRanking` flag. Reading the description anyway
     * errs towards keeping a card, which is the only direction this class is allowed to err in.
     *
     * @param list<string> $tokens
     */
    private static function admits(ProductCard $card, array $tokens): bool
    {
        $haystack = mb_strtolower($card->name . ' ' . ($card->description ?? ''));
        $traced = false;

        foreach ($tokens as $token) {
            if (!str_contains($haystack, $token)) {
                continue;
            }

            $traced = true;

            if (self::atAWordEdge($haystack, $token)) {
                return true;
            }
        }

        // See the class docblock: no trace of any token means the match came from a field the card
        // does not carry, so this is a hit worth keeping rather than one worth explaining.
        return !$traced;
    }

    /**
     * Opening a word or closing one. Anything else is a fragment.
     *
     * `$word` rather than `$token`, which is what it is called everywhere else here: mago's
     * `sensitive-parameter` rule reads a parameter named `token` as a credential and demands
     * `#[SensitiveParameter]`. Marking a search word secret would be a lie in an attribute.
     */
    private static function atAWordEdge(string $haystack, string $word): bool
    {
        $quoted = preg_quote($word, '/');

        return preg_match('/(?<![\p{L}\p{N}])' . $quoted . '|' . $quoted . '(?![\p{L}\p{N}])/u', $haystack) === 1;
    }

    /** @return list<string> */
    private static function tokens(?string $term): array
    {
        if ($term === null) {
            return [];
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term)) ?: [];

        return array_values(array_filter(
            $parts,
            static fn(string $part): bool => mb_strlen($part) >= self::MIN_TOKEN_LENGTH,
        ));
    }
}
