<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * The vocabulary that takes an availability claim back, and the two directions it can come from.
 *
 * Split out of {@see AvailabilityClaimExtractor} (cyclomatic-complexity) rather than suppressed, and
 * the seam is the right one anyway: finding the phrase that asserts availability and deciding whether
 * the text around it cancels the assertion are two jobs, revised for different reasons — one by a
 * reply that should have been flagged, the other by a reply that should not have been.
 */
final class AvailabilityNegation
{
    /**
     * Words that, appearing shortly before a claim phrase, negate it.
     *
     * The window matters: a reply can legitimately negate one product and assert another in the same
     * sentence, and the assertion is still a claim.
     */
    private const NEGATIONS = [
        'not',
        'no',
        'never',
        'unavailable',
        'out\s+of\s+stock',
        'sold\s+out',
        'unfortunately',
        'cannot',
        'unable',
        // German. `kein` is written out with its inflections rather than stemmed, for the reason
        // NumberWords gives for the same choice: a stem would also fold in `keinesfalls` and, worse,
        // anything else beginning with those four letters.
        'nicht',
        'kein(?:e[mnrs]?)?',
        'leider',
        'ausverkauft',
        'vergriffen',
    ];

    /**
     * Words that turn a `haben`-phrase into a REPORT of the assistant's own search rather than an
     * assertion about stock.
     *
     * **German builds the perfect tense with the same auxiliary the claim uses.** *"Ich habe das
     * Trail Jersey gefunden"* and *"Wir haben das Trail Jersey"* open identically and mean opposite
     * things, and the word that separates them is the participle at the end of the clause. Measured
     * against the running shop on 2026-08-31: the reply the system prompt explicitly asks for —
     * *"Ich habe das Trail Jersey in Blau in der Größe M gefunden."* — was flagged as an availability
     * claim beside a card reporting stock 0. Ruling R85 in its purest form, and it would have fired
     * on nearly every German turn.
     *
     * An explicit list rather than a general `ge…t` / `ge…en` shape, for the reason
     * {@see self::CLAIM_PATTERNS} gives for its own explicitness — and because the general shape is
     * wrong: it matches `gegen`, an ordinary preposition.
     */
    private const REPORTING_PARTICIPLES = [
        'gefunden',
        'herausgefunden',
        'gesucht',
        '(?:heraus|raus)gesucht',
        'gesehen',
        'angesehen',
        'nachgesehen',
        'entdeckt',
        'ausgewählt',
        'zusammengestellt',
        'angezeigt',
        'aufgelistet',
        'geprüft',
    ];

    /**
     * Matched on word boundaries rather than as bare substrings, which is **not** a tidy-up.
     *
     * `str_contains($window, 'no')` was safe while only English was matched. It is not safe beside
     * German prose: `no` is inside `noch` and `nochmal`, two of the commonest words in the language,
     * so an honest claim would be silently negated by the sentence before it — a false negative in
     * the one detector that cannot afford any, introduced by the change meant to make it work in
     * German. See `AvailabilityClaimExtractorGermanTest`'s last case, which is that regression.
     *
     * `n't` sits outside the alternation because it cannot carry a leading `\b`: in `don't` it
     * follows a letter, so the boundary never matches. It is unambiguous as a bare substring anyway,
     * which is why it needed no boundary in the first place.
     */
    private const NEGATION_PATTERN = '/\b(?:%s)\b|n\'t/u';

    private function __construct() {}

    /** Whether the text immediately BEFORE a claim phrase negates it. */
    public static function before(string $window): bool
    {
        return self::matchesAny($window, self::NEGATIONS);
    }

    /**
     * Whether the rest of the clause after a claim phrase takes the claim back — either by negating
     * it, or by supplying the participle that makes the whole thing a report of the assistant's own
     * search rather than a statement about stock.
     */
    public static function after(string $clause): bool
    {
        return self::matchesAny($clause, [...self::NEGATIONS, ...self::REPORTING_PARTICIPLES]);
    }

    /**
     * One alternation rather than a loop of `str_contains`, so every entry gets the word boundaries
     * {@see self::NEGATION_PATTERN} explains.
     *
     * @param list<string> $words
     */
    private static function matchesAny(string $window, array $words): bool
    {
        return preg_match(\sprintf(self::NEGATION_PATTERN, implode('|', $words)), $window) === 1;
    }
}
