<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * The sentences in which a reply says what a product **comes with** or what it is **rated as**.
 *
 * ## The gap this is the front half of
 *
 * Every other audit in this directory works against a closed vocabulary — a currency figure, a facet
 * value, a normalised period — and that is what made the most expensive class of invention invisible.
 * Read out of a trace export of 34 real conversations: the card layer was **already honest**,
 * `validate` reporting `inventedProductIds: []` on all 104 turns. Every failure worth fixing lived in
 * the prose beside the card, in claims no vocabulary can enumerate:
 *
 * | Reply | Why nothing fired |
 * |---|---|
 * | "using **the included bracket** — no extra hardware is needed" | `bracket` is not a facet value |
 * | "both are **rated for high-security use**" | neither is `high-security` |
 * | "it did return **trail-rated helmets**" | `Trail` is a facet value; `trail-rated` is not |
 * | "often **10 minutes or more with standard tools**" | not a period the shop grants, not a price |
 *
 * The shop's whole description for that lock was one sentence — *"A 90 cm chain in a fabric sleeve,
 * for locking to awkward stands."* No bracket, no material, no rating, no resistance time.
 *
 * ## Why a marker, and not a noun
 *
 * There is no list of things a shop might include in a box, so this cannot ask "did the reply mention
 * a bracket". It asks the answerable question instead: **did the reply use the grammar of a
 * scope-of-delivery or certification claim**, and if so, what did it attach that grammar to. The
 * marker is the closed set; the thing claimed is whatever followed it.
 *
 * Hyphenated `…-rated` is its own branch because it inverts the word order — `trail-rated` puts the
 * claim's content in front of the marker, and the forward window would read straight past it.
 *
 * ## Three shapes that wear the grammar without making the claim
 *
 * All three were false positives measured by replaying this class over the 104 real replies, and
 * each is excluded here rather than downstream, because a claim that was never made cannot be
 * supported or contradicted by anything.
 *
 * | Reply | Why it is not a delivery claim |
 * |---|---|
 * | "**Handlebar & stem** (including bar tape or grips)" | `including` enumerates a category |
 * | "The shop's **card includes** any technical specifications" | the subject is the card, not the product |
 * | "Diese **Produktbeschreibung enthält** zwei Ebenen" | the subject is the description |
 *
 * `including` is dropped from the marker list outright. It was responsible for four of the eleven
 * false positives and for none of the true ones — every measured invention used `included`,
 * `supplied with`, `comes with`, `rated for` or `im Lieferumfang`. A shop enumerating what a
 * category contains is the ordinary shape of a helpful answer, which is the same argument
 * {@see PropertyNegation} makes about no-match replies.
 *
 * Bare `enthält` is dropped for the same reason and it is the sharper case: German has no separate
 * word for "is included in the delivery", so `enthält` is simply "contains". The measured false
 * positive was the assistant describing its own tools — *"prüft, ob der Warenkorb des Kunden Artikel
 * enthält"* — where the subject is a cart and no meta-subject noun is in front of it. The one real
 * German finding said *"im Lieferumfang enthalten ist"*, which `im lieferumfang` matches, so nothing
 * measured is lost.
 *
 * The subject test reads the two words in front of the marker. It is narrow on purpose: *"the lock's
 * box includes a bracket"* has to survive it, so the list is nouns that name the *answer* rather
 * than the product — a description, a card, the shop's data. Bare `shop` is deliberately absent,
 * because "the shop supplies it with a bracket" is a real claim.
 *
 * ## A question offers, it does not state
 *
 * *"Would you like me to include it in your cart?"* is the grammar of a delivery claim and asserts
 * nothing. {@see PropertyMention::stands()} draws the same distinction for the same reason and it is
 * just as load-bearing here: without it every offer of help becomes a finding, and ruling R85 says
 * what a warning that fires on correct behaviour is worth.
 *
 * ## What it cannot see
 *
 * The window looks **forward**, so a claim that puts its content behind the marker — *"EN 1078
 * certified"* rather than *"certified to EN 1078"* — is missed. This is a floor and not a guarantee,
 * exactly as {@see PassageAudit::unsupportedPeriods()} says of its own check. The honest summary is
 * the same: the primary control on this class of error is the model and the description it was
 * given, and this is what notices when neither was enough.
 */
final readonly class SuppliedFactClaimExtractor
{
    /**
     * The grammar of "this comes with" and "this is rated as", in both languages the assistant
     * answers in.
     *
     * Ordered longest-first within each family so `comes complete with` is not matched as `comes
     * with` and left pointing at the wrong words.
     */
    private const MARKERS = 'comes complete with|comes with|supplied with|ships with|bundled with|delivered with|includ(?:es|ed)|geliefert mit|im lieferumfang|certified(?:\s+(?:to|for|as))?|rated(?:\s+(?:to|for|as))?|approved(?:\s+(?:to|for))?|compliant with|zertifiziert(?:\s+(?:nach|für))?';

    /**
     * How many words after the marker are read as the thing being claimed.
     *
     * Four covers every measured case — `the included bracket` needs two, `rated for high-security
     * use` needs three — and stopping there is what keeps the claim's own noun from being diluted by
     * the rest of the sentence. A wider window would drag in words the shop's prose happens to
     * contain and report the claim as supported on the strength of them.
     */
    private const WORDS_AFTER = 4;

    /**
     * Nouns that make the sentence a statement about the *answer* rather than about the product.
     *
     * Matched as substrings, so `beschreibung` covers `Produktbeschreibung`.
     */
    private const META_SUBJECTS = [
        'description',
        'beschreibung',
        'card',
        'karte',
        'data',
        'daten',
        'information',
        'katalog',
        'catalogue',
        'liste',
        'list',
        'results',
        'suche',
        'search',
        'message',
        'nachricht',
        'answer',
        'antwort',
        'reply',
    ];

    /**
     * How many words in front of the marker are read to find the sentence's subject.
     */
    private const WORDS_BEFORE = 2;

    /**
     * @return list<string> the claim phrases, first appearance order, each reported once
     */
    public function extract(string $text): array
    {
        // Group 1 is the subject window, group 2 the phrase. Groups 3 and 4 are LOOKAHEADS and
        // consume nothing — the same construction and the same reason as
        // PropertyMention::assertedIn(): a consumed tail would swallow a later claim in the same
        // sentence and it would never be examined.
        $pattern = \sprintf(
            '/((?:[\p{L}\p{N}\x{2019}\'-]+[\s]+){0,%3$d})((?:\b(?:%1$s)\b|\b[\p{L}]+-rated\b)'
            . '(?:[\s,]+[\p{L}\p{N}][\p{L}\p{N}\-]*){0,%2$d})(?=([^.!?\n]{0,300}([.!?])?))/iu',
            self::MARKERS,
            self::WORDS_AFTER,
            self::WORDS_BEFORE,
        );

        if (preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER) === false) {
            return [];
        }

        $claims = [];

        foreach ($matches as $match) {
            if (self::states($match[1] ?? '', $match[4] ?? '')) {
                $claims[self::tidied($match[2] ?? '')] = true;
            }
        }

        unset($claims['']);

        return array_map(strval(...), array_keys($claims));
    }

    /**
     * Whether this occurrence of the grammar actually states something about a product.
     */
    private static function states(string $before, string $terminator): bool
    {
        // A question offers, it does not state. See the class docblock.
        if ($terminator === '?') {
            return false;
        }

        $subject = mb_strtolower($before);

        foreach (self::META_SUBJECTS as $meta) {
            if (str_contains($subject, $meta)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The phrase as a merchant should read it in the trace.
     *
     * The window stops after a fixed number of words, so it routinely ends mid-thought on a
     * conjunction or a filler — `comes with a frame mount and`. Those words are already discarded as
     * noise by {@see ClaimTokens}, so this changes nothing about what is flagged; it only stops the
     * `claims.audit` payload from reading like a truncation bug to the person it is written for.
     */
    private static function tidied(string $phrase): string
    {
        return (string) preg_replace(
            '/(?:[\s,]+(?:and|or|that|which|while|where|when|but|so|if|would|will|should|can|could|may|might'
            . '|often|usually|typically|used|using|also|both|very|more|most'
            . '|und|oder|wenn|die|der|das|is|are|the|a|an))+$/iu',
            '',
            trim($phrase),
        );
    }
}
