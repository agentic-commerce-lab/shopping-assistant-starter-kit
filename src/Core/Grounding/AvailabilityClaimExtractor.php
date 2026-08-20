<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * Finds **positive availability claims** in a shopper-facing reply — sentences asserting that a
 * product can be had.
 *
 * ## Why this exists
 *
 * The prose audit covered currency figures only, and a live turn against the real catalogue replied
 * *"Yes, the Trail Jersey is available in Blue, size M"* beside a rendered card reporting
 * **stock 0**. Nothing fired. For a sold-out item that is worse than an unbacked price: it is the
 * expectation D4 exists to prevent, arriving through the prose instead of the stock field. The
 * follow-up turn in the same conversation deferred correctly, so the behaviour is not even stable —
 * it cannot be worked around, only detected.
 *
 * ## Why it is deliberately narrow
 *
 * Ruling R85 was a fresh lesson in the opposite failure: `no_unbacked_price_in_prose` fires when a
 * model merely restates the shopper's own budget, and **a safety assertion that fires on correct
 * behaviour trains people to ignore it.** That is expensive on exactly the assertions that must never
 * be doubted. So this extractor answers one narrow question — *does the reply assert that something
 * is available?* — and everything ambiguous is treated as no claim:
 *
 * | Prose | Result |
 * |---|---|
 * | "the jersey **is available** in Blue" | claim |
 * | "yes, **we have** it in M" | claim |
 * | "it **is in stock**" | claim |
 * | "it is **not available**" / "**no longer available**" | **no** claim — a negated assertion |
 * | "it is **out of stock**" / "**sold out**" | **no** claim |
 * | "you can see its **current stock status** on the product card" | **no** claim — a deferral |
 * | "**would you like me to** add it to your cart?" | **no** claim |
 *
 * Negation is checked in a short window before the phrase rather than anywhere in the sentence: "we
 * do not have the blue one, but the black **is available**" contains both, and the claim is real.
 *
 * English only, per D11.
 */
final readonly class AvailabilityClaimExtractor
{
    /**
     * Phrases that assert availability. Kept as explicit alternatives rather than one clever
     * pattern, because each is a decision someone may need to revisit in isolation.
     */
    private const CLAIM_PATTERNS = [
        '(?:is|are|it\'s|its|they\'re)\s+(?:currently\s+|still\s+)?available',
        '(?:is|are|it\'s|its|they\'re)\s+(?:currently\s+|still\s+)?in\s+stock',
        '(?:we|i)\s+(?:do\s+)?(?:have|stock|carry)\s+(?:it|them|that|this|the|a|an|\d)',
        '(?:we|i)\s+(?:have|stock|carry)\s+(?:got\s+)?(?:it|them|that|this)',
        'in\s+stock\s+(?:now|right\s+now|and\s+ready)',
        '(?:available|in\s+stock)\s+(?:right\s+now|now|today)',
        'yes,?\s+(?:we|i)\s+(?:do\s+)?(?:have|stock|carry)',
        'ready\s+to\s+ship',
    ];

    /**
     * Words that, appearing shortly before a claim phrase, negate it.
     *
     * The window matters: a reply can legitimately negate one product and assert another in the same
     * sentence, and the assertion is still a claim.
     */
    private const NEGATIONS = [
        'not',
        "n't",
        'no',
        'never',
        'unavailable',
        'out of stock',
        'sold out',
        'no longer',
        'unfortunately',
        'cannot',
        "can't",
        'unable',
    ];

    /** How many characters before a claim phrase are searched for a negation. */
    private const NEGATION_WINDOW = 40;

    /**
     * @return list<string> the matched claim phrases, in the order they appear
     */
    public function extract(string $prose): array
    {
        $normalised = mb_strtolower($prose);
        $claims = [];

        foreach (self::CLAIM_PATTERNS as $pattern) {
            // The preceding window is captured as part of the match rather than located by byte
            // offset: `PREG_OFFSET_CAPTURE` makes the offset's type unprovable, and a regex that
            // carries its own context is easier to reason about than index arithmetic. Greedy
            // `.{0,N}` takes as much preceding text as it can, which is exactly the window wanted.
            $window = \sprintf('/(.{0,%d})(%s)/su', self::NEGATION_WINDOW, $pattern);

            if (preg_match_all($window, $normalised, $matches, \PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $preceding = $match[1] ?? '';
                $claim = $match[2] ?? '';

                if ($claim !== '' && !$this->isNegated($preceding)) {
                    $claims[] = $claim;
                }
            }
        }

        return array_values(array_unique($claims));
    }

    /**
     * Whether the text immediately before a claim phrase negates it.
     *
     * The window is short on purpose: a reply can honestly deny one variant and assert another in the
     * same sentence, and the assertion is still a claim that has to be checked against a card.
     */
    private function isNegated(string $preceding): bool
    {
        foreach (self::NEGATIONS as $negation) {
            if (str_contains($preceding, $negation)) {
                return true;
            }
        }

        return false;
    }
}
