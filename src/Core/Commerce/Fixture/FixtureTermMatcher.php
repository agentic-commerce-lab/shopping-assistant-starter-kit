<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Term matching for {@see FixtureQueryFilter}: all-token matches when there are any,
 * otherwise any-token matches.
 *
 * **Why this is not a plain substring test any more.** It used to be
 * `str_contains(name) || str_contains(description)` on the whole phrase, so a shopper asking
 * about *"that blue cycling jersey in a medium"* matched **nothing** in a catalogue whose
 * product is called `Trail Jersey` — the term `"cycling jersey"` is not a substring of
 * anything. The model then retried more broadly, got the whole variant family back, and the
 * `variant_stock · beginner` eval journey failed `rendered_ids_exactly` with two extra
 * siblings. That failure was the fixture's, not the pipeline's: the identical phrasing against
 * the real Shopware catalogue resolves to exactly one card, because Shopware tokenises the
 * search term instead of matching the phrase whole.
 *
 * A fixture gateway exists so eval results transfer to production. One that cannot find a
 * product the real shop finds does not just under-report — it reports a grounding failure that
 * does not exist.
 *
 * **Why all-token-first rather than plain any-token.** Any-token alone is too broad in the
 * other direction: `"bottle cage"` would match every water bottle in the catalogue as well as
 * the cage, and `vocabulary_not_inventory` asserts exactly one product. Shopware ranks a
 * product matching every token above one matching a single token, and the caller's `limit`
 * then cuts the tail; preferring the all-token set reproduces that outcome without
 * reimplementing scoring. So:
 *
 * - `"bottle cage"` → `Alloy Bottle Cage` matches both tokens, and only it is returned.
 * - `"cycling jersey"` → nothing matches both, so the any-token pass returns the jersey family
 *   on `"jersey"`, and the shopper's own `Colour`/`Size` filters narrow it to one variant.
 *
 * This stays a fixture, not a search engine: no stemming, no synonyms, no ranking by field. It
 * is deliberately the smallest change that stops the fixture disagreeing with the shop about
 * whether a product exists.
 */
final class FixtureTermMatcher
{
    /**
     * Tokens shorter than this are ignored.
     *
     * Two characters would let `"in"` and `"a"` — which a beginner's phrasing is full of —
     * match inside unrelated words (`"riding"` contains `"in"`), turning the any-token pass
     * into "return the catalogue". Shopware has its own minimum search-term length for the
     * same reason.
     */
    private const MIN_WORD_LENGTH = 3;

    private function __construct() {}

    /**
     * @param list<ProductCard> $units
     *
     * @return list<ProductCard>
     */
    public static function filter(array $units, string $term): array
    {
        $words = self::tokenise($term);

        if ($words === []) {
            // Nothing usable to tokenise (a term of only short words, or only punctuation):
            // fall back to the whole-phrase test rather than matching everything.
            return array_values(array_filter($units, static fn(ProductCard $unit): bool => self::containsAll($unit, [strtolower(
                $term,
            )])));
        }

        $all = array_values(array_filter($units, static fn(ProductCard $unit): bool => self::containsAll(
            $unit,
            $words,
        )));

        if ($all !== []) {
            return $all;
        }

        return array_values(array_filter($units, static fn(ProductCard $unit): bool => self::containsAny(
            $unit,
            $words,
        )));
    }

    /**
     * @return list<string>
     */
    private static function tokenise(string $term): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', strtolower($term)) ?: [];

        return array_values(array_filter(
            $parts,
            static fn(string $word): bool => mb_strlen($word) >= self::MIN_WORD_LENGTH,
        ));
    }

    /** @param list<string> $words */
    private static function containsAll(ProductCard $unit, array $words): bool
    {
        foreach ($words as $word) {
            if (!self::contains($unit, $word)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $words */
    private static function containsAny(ProductCard $unit, array $words): bool
    {
        foreach ($words as $word) {
            if (self::contains($unit, $word)) {
                return true;
            }
        }

        return false;
    }

    private static function contains(ProductCard $unit, string $word): bool
    {
        return (
            str_contains(strtolower($unit->name), $word) || str_contains(strtolower($unit->description ?? ''), $word)
        );
    }
}
