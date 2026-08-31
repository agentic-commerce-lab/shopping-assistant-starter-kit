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
 * **English and German, both on every reply.** D11 made this English-only, and that was correct
 * while the prompt closed with a flat `Answer in English.` It stopped being correct when the reply
 * started following the shopper's own language: the same sentence in German passed unflagged beside
 * the same stock-0 card. There is no per-turn language to select on — the model decides it from the
 * shopper's words, not from a setting the server can read first — so both sets run unconditionally.
 * A missed claim is the expensive direction, and a dozen extra alternatives cost nothing.
 *
 * German negates a verb phrase from behind (*"wir führen das leider nicht"*), which the preceding
 * window cannot see. Those patterns are listed in {@see self::TRAILING_NEGATED_PATTERNS} and checked
 * in both directions; the adjectival ones negate from the front like English and keep the single
 * window, because widening them would make an honest claim vanish whenever the next clause happened
 * to deny a different variant.
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
        // German, adjectival: "nicht" precedes, exactly as English negation does.
        //
        // The copula and the adjective are routinely separated — "ist in Blau verfügbar", "ist
        // derzeit noch lieferbar" — so up to three words may sit between them. Those words are
        // TEMPERED: the lookahead refuses to let a negation be one of them, because a negation
        // swallowed INTO the match is a negation the preceding window can no longer see, and
        // "ist in Blau nicht verfügbar" would be reported as a claim. `\p{L}` rather than `\w`
        // so an umlaut in the filler does not end the run.
        //
        // `\b` after the adjective is load-bearing: without it "die Verfügbarkeit siehst du auf
        // der Karte" — the deferral the prompt actually asks for — matches on its own prefix.
        '(?:ist|sind)\s+(?:(?!nicht\b|kein|leider)[\p{L}]+\s+){0,3}verfügbar\b',
        '(?:ist|sind)\s+(?:(?!nicht\b|kein|leider)[\p{L}]+\s+){0,3}lieferbar\b',
        '(?:ist|sind)\s+(?:(?!nicht\b|kein|leider)[\p{L}]+\s+){0,3}(?:auf|am)\s+lager\b',
        'sofort\s+(?:lieferbar|verfügbar)\b',
    ];

    /**
     * Claim phrases that are also checked for a negation AFTER them.
     *
     * Only the German verb-first shapes, and only those. *"Wir führen das leider nicht"* asserts and
     * then withdraws in a way no preceding window can see. English is deliberately left alone: it
     * negates in front of the verb, so a trailing window would buy nothing there and would cost real
     * claims — *"the jersey is available, but we don't have the bib"* denies a different product, and
     * the first half is still a claim that has to be checked against a card.
     */
    private const TRAILING_NEGATED_PATTERNS = [
        '(?:wir|ich)\s+(?:haben|habe|führen|führe)\s+(?:es|ihn|sie|das|dies(?:es|en|e)?|den|die|der|ein(?:e|en)?|noch|so\s+etwas|\d)',
        'ja,?\s+(?:wir|ich)\s+(?:haben|habe|führen|führe)',
    ];

    /** How many characters before a claim phrase are searched for a negation. */
    private const NEGATION_WINDOW = 40;

    /**
     * How much text after a claim is read, for {@see self::TRAILING_NEGATED_PATTERNS}.
     *
     * **A clause, not a character count.** German puts both the negation and the participle at the
     * end of the clause, so the distance between *"wir führen das"* and the *"nicht"* that cancels it
     * is however long the product's name happens to be — which is every real sentence. A fixed
     * 30-character window missed *"Wir führen das Trail Jersey in Blau leider nicht."* while catching
     * the toy example *"Wir führen das leider nicht."*, which is the worst possible combination: it
     * looked correct in a test and failed on the shop.
     *
     * Sentence-ending punctuation bounds it, so a denial in the NEXT sentence — about a different
     * product — still cannot reach back and cancel this claim.
     *
     * **The trade this makes, stated rather than discovered later.** *"Wir haben das Trail Jersey,
     * aber nicht in Größe M"* is now read as negated, and it does contain a positive claim about the
     * jersey. That is a missed claim, and it is the lesser cost: the alternative was firing on the
     * correct reply shape, and a check nobody believes catches nothing at all.
     */
    private const TRAILING_CLAUSE = '[^.!?\n]{0,200}';

    /**
     * @return list<string> the matched claim phrases, in the order they appear
     */
    public function extract(string $prose): array
    {
        $normalised = mb_strtolower($prose);

        return array_values(array_unique([
            ...$this->matching($normalised, self::CLAIM_PATTERNS, trailingClause: ''),
            ...$this->matching($normalised, self::TRAILING_NEGATED_PATTERNS, self::TRAILING_CLAUSE),
        ]));
    }

    /**
     * `$trailingClause` empty means "read nothing after the phrase", which is how the English
     * patterns are run — a branchless way of saying it, rather than a boolean flag and two `if`s
     * asking the same question the caller already answered. `(?=())` matches the empty string, so
     * the third group is always present and always harmless.
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function matching(string $normalised, array $patterns, string $trailingClause): array
    {
        $claims = [];

        foreach ($patterns as $pattern) {
            // The preceding window is captured as part of the match rather than located by byte
            // offset: `PREG_OFFSET_CAPTURE` makes the offset's type unprovable, and a regex that
            // carries its own context is easier to reason about than index arithmetic. Greedy
            // `.{0,N}` takes as much preceding text as it can, which is exactly the window wanted.
            //
            // The trailing window is a LOOKAHEAD rather than a third captured group, so it consumes
            // nothing: consumed, a greedy 30 characters would swallow whatever follows and a second
            // claim in the same reply would never be matched at all.
            $window = \sprintf('/(.{0,%d})(%s)(?=(%s))/su', self::NEGATION_WINDOW, $pattern, $trailingClause);

            if (preg_match_all($window, $normalised, $matches, \PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $claim = $match[2] ?? '';

                if ($claim === '' || AvailabilityNegation::before($match[1] ?? '')) {
                    continue;
                }

                // Checked separately from the preceding window rather than concatenated with it:
                // the rest of the clause can disarm a claim in two different ways — by negating it,
                // or by revealing it was the perfect tense all along — and only one of those two
                // questions makes sense to ask of the text BEFORE the phrase.
                if (AvailabilityNegation::after($match[3] ?? '')) {
                    continue;
                }

                $claims[] = $claim;
            }
        }

        return $claims;
    }
}
