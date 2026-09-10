<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * Whether a sentence that *looks* like a claim actually makes one.
 *
 * Shared by {@see SuppliedFactClaimExtractor} and {@see PerformanceClaimExtractor}, which find two
 * different grammars and then need the identical three tests — so the tests live here rather than in
 * each of them. Two copies of this would drift, and the duplication detector would be right to say
 * so.
 *
 * It is the open-vocabulary counterpart of {@see PropertyMention::stands()}, which asks the same
 * question about a closed-vocabulary facet value, and the three reasons a claim does not stand are
 * the same three reasons in both places.
 *
 * ## 1 · A question offers, it does not state
 *
 * *"Would you like me to include it in your cart?"* is the grammar of a delivery claim and asserts
 * nothing. Without this every offer of help becomes a finding.
 *
 * ## 2 · A reply denying its own claim is a reply behaving correctly
 *
 * **The one this file exists for, and it was found out of sample.** The detector was written against
 * a corpus of 104 replies where the model *invented* rather than declined, so a correct refusal never
 * appeared in the material used to tune it. Fifteen fresh replies were then generated against a live
 * shop on 2026-09-10, and two were flagged:
 *
 * - *"The shop **does not list** any additional items or accessories **included in the scope of
 *   delivery** for the Trail Jersey, so you receive the jersey itself."*
 * - *"…but there is **no mention of an included** repair kit, so I do not have that detail."*
 *
 * Both are the assistant doing exactly what it should. Firing on them is the failure ruling R85
 * describes — a control that goes off on correct behaviour teaches everyone to ignore it — and it
 * would have been the detector's most common finding in production, where refusals are the intended
 * behaviour and inventions are the exception.
 *
 * The window is {@see self::CHARS} characters because a denial governs from further back than a
 * subject does: *"the shop does not list any additional items or accessories included in the scope
 * of delivery"* puts its `not` fifty-five characters from the marker. {@see PropertyNegation::before()}
 * trims that to the clause, so a denial cannot reach across a comma into an unrelated claim.
 *
 * ## 3 · The subject may be the answer rather than the product
 *
 * *"The shop's **card includes** any technical specifications"* and *"Diese
 * **Produktbeschreibung enthält** zwei Ebenen"* describe the reply, not the goods. This test reads
 * only {@see self::SUBJECT_CHARS} characters, deliberately far fewer than the negation test: widened
 * to the whole clause it would start excluding real claims because some unrelated noun appeared in
 * the same sentence. Bare `shop` is deliberately absent from the list, because *"the shop supplies it
 * with a bracket"* is a real claim.
 */
final readonly class ClaimStands
{
    /**
     * How many characters before the claim are captured, for the negation test.
     *
     * Eighty, matching {@see PropertyMention}'s own window. Clause trimming rather than this number
     * is what keeps it honest.
     */
    public const CHARS = 80;

    /**
     * How much of that window the subject test reads.
     */
    private const SUBJECT_CHARS = 24;

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

    private function __construct() {}

    /**
     * @param string $before     the raw text in front of the claim, at most {@see self::CHARS} of it
     * @param string $terminator the sentence's own terminator, or an empty string if it had none
     */
    public static function at(string $before, string $terminator): bool
    {
        if ($terminator === '?') {
            return false;
        }

        $window = mb_strtolower($before);

        if (PropertyNegation::before($window)) {
            return false;
        }

        $subject = mb_substr($window, -self::SUBJECT_CHARS);

        foreach (self::META_SUBJECTS as $meta) {
            if (str_contains($subject, $meta)) {
                return false;
            }
        }

        return true;
    }
}
