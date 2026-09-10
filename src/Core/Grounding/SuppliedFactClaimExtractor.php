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
 * `including` is dropped from the marker list outright. It was responsible for four of the eleven
 * false positives measured over the 104 real replies and for none of the true ones — every
 * invention used `included`, `supplied with`, `comes with`, `rated for` or `im Lieferumfang`. A
 * shop enumerating what a category contains is the ordinary shape of a helpful answer.
 *
 * Bare `enthält` is dropped for the same reason and it is the sharper case: German has no separate
 * word for "is included in the delivery", so `enthält` is simply "contains". The measured false
 * positive was the assistant describing its own tools — *"prüft, ob der Warenkorb des Kunden
 * Artikel enthält"*. The one real German finding said *"im Lieferumfang enthalten ist"*, which
 * `im lieferumfang` matches, so nothing measured is lost.
 *
 * The remaining three exclusions — a question, the reply denying its own claim, and a subject that
 * is the answer rather than the product — are {@see ClaimStands}, shared with
 * {@see PerformanceClaimExtractor}. The second of those was found out of sample and is the most
 * important of the three; that class carries the measurement.
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
    public function __construct(
        private ListedFactClaims $listed = new ListedFactClaims(),
    ) {}

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
            '/(.{0,%3$d}?)((?:\b(?:%1$s)\b|\b[\p{L}]+-rated\b)'
            . '(?:[\s,]+[\p{L}\p{N}][\p{L}\p{N}\-]*){0,%2$d})(?=([^.!?\n]{0,300}([.!?])?))/isu',
            ClaimPhrase::GRAMMAR,
            ClaimPhrase::WORDS_AFTER,
            ClaimStands::CHARS,
        );

        if (preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER) === false) {
            return [];
        }

        $claims = [];

        foreach ($matches as $match) {
            if (ClaimStands::at($match[1] ?? '', $match[4] ?? '')) {
                $claims[ClaimPhrase::tidied($match[2] ?? '')] = true;
            }
        }

        // The other grammar, and one this pattern structurally cannot reach: a marker followed by
        // a colon and a bullet list. See {@see ListedFactClaims} for the reply that went unreported.
        foreach ($this->listed->in($text) as $claim) {
            $claims[$claim] = true;
        }

        unset($claims['']);

        return array_map(strval(...), array_keys($claims));
    }
}
