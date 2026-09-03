<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * The vocabulary that takes an attribute claim back, and how far back it is allowed to reach.
 *
 * ## Why this exists
 *
 * {@see PropertyClaimExtractor} matched a facet value anywhere in the prose and called it a claim.
 * Measured on the running shop on 2026-09-03: asked for a red dress in size S, the assistant replied
 * *"The search found no dresses in red or burgundy in size S"* and the audit flagged **Burgundy** —
 * so the shopper was shown *"the material and attribute details on the card below are the ones that
 * apply"* about a colour the reply had just said did not exist. `red` escaped only because the
 * shopper had used the word themselves, which is an accident of that turn rather than a rule.
 *
 * A saying-what-is-absent sentence is the ordinary shape of a no-match reply, so this was not a rare
 * edge: every property warning recorded in that shop was this false positive. Ruling R85 again — a
 * safety warning that fires on correct behaviour teaches shoppers to ignore the ones that matter.
 *
 * ## Why not {@see AvailabilityNegation}
 *
 * That class solves the same *shape* of problem and is the wrong list for this one. Its vocabulary is
 * about stock — `out of stock`, `sold out`, `ausverkauft`, `vergriffen` — and reading those as
 * cancelling an *attribute* claim would excuse a real one: *"the sold out one is merino"* asserts a
 * material, and whether the product can be bought has nothing to do with whether that material is
 * backed. It carries `REPORTING_PARTICIPLES` for the same reason and they are just as irrelevant
 * here. So this is a separate list, split for exactly the reason its sibling gives for its own
 * existence: two jobs, revised for different reasons.
 *
 * ## Three shapes, all measured on the running shop
 *
 * The first fix read only the text *before* a value, and the next live turn produced the other two:
 *
 * | Reply | Value flagged | Why it is not a claim |
 * |---|---|---|
 * | "The search found **no** dresses in red or burgundy" | `Burgundy` | denied from the front |
 * | "A search for dresses in red or burgundy in size S **found no matches**" | `Burgundy` | denied from behind |
 * | "Would you like to explore other colours like Blush, Emerald, or **Navy**?" | `Navy` | an offer, not a statement |
 *
 * The third is the one worth pausing on: naming real catalogue values as a next step is precisely
 * what the system prompt asks for, and the vocabulary is rendered into the prompt so the model *can*.
 * A question asserts nothing, so a value whose sentence ends in a question mark is not a claim.
 *
 * ## The clause boundary is the load-bearing part
 *
 * A negation cancels a value in **its own clause** and no further. A fixed character window alone
 * would read *"we have no blue, but the black one is merino"* as negating `Merino` — the `no` is
 * within thirty characters — and that reply does assert a material that has to be backed. So the
 * window stops at sentence punctuation and at a contrasting conjunction, which is where the denial
 * stops applying in both languages this assistant answers in.
 *
 * Both directions are read, each bounded to its own clause. Unbounded, a trailing look would lose
 * real claims — *"The Blue jersey is not waterproof"* asserts `Blue` while denying something else,
 * and *"it is merino, we have no silk"* asserts a material in its first clause. The trailing
 * boundary is therefore wider than the leading one by one word: `and` opens a clause going forward
 * (*"it is merino and we have no silk"*) but must never bound going backward, where it continues a
 * denied list (*"no red and burgundy"*).
 */
final class PropertyNegation
{
    /**
     * Words that, in the same clause before a value, mean the reply is saying it is *absent*.
     *
     * Deliberately smaller than its availability sibling: only words that deny the value itself.
     * `unfortunately` and `leider` are in for the shape *"unfortunately nothing in merino"* — they
     * accompany the denial that a no-match reply is made of.
     */
    private const NEGATIONS = [
        'no',
        'not',
        'none',
        'nothing',
        'neither',
        'nor',
        'never',
        'without',
        'lack(?:s|ing)?',
        'unfortunately',
        // German. `kein` is spelled out with its inflections rather than stemmed, for the reason
        // AvailabilityNegation gives for the same choice: a stem folds in `keinesfalls` and anything
        // else starting with those four letters.
        //
        // `noch` is deliberately absent. It means "still" as often as it continues a `weder … noch`,
        // and *"noch in Merino verfügbar"* is an assertion — the word boundaries below keep it out of
        // `no` anyway, which is the trap that cost AvailabilityNegation a regression test.
        'nicht',
        'kein(?:e[mnrs]?)?',
        'ohne',
        'weder',
        'leider',
    ];

    /**
     * Where a preceding window stops carrying a denial forward.
     *
     * Sentence punctuation and a contrast. A comma counts: *"no blue, but the black one is merino"*
     * puts the denial in its own clause, and so does *"kein Blau, aber das schwarze ist aus Merino"*.
     * `but` and `aber` are listed as well as the punctuation because English drops the comma often
     * enough — *"we have no blue but the black one is merino"* — that punctuation alone would miss it.
     */
    private const CLAUSE_OPENERS = ['but', 'however', 'though', 'aber', 'jedoch', 'sondern', 'dafür'];

    /**
     * The only phrases read as a denial **after** a value: the search came back empty.
     *
     * A general negation vocabulary cannot be used in this direction, and the case that proves it is
     * *"The blue jersey is not waterproof."* — `Blue` is asserted there and something else is denied,
     * so reading any trailing `not` as cancelling would lose a real claim. That is the cost
     * {@see AvailabilityClaimExtractor::TRAILING_NEGATED_PATTERNS} refuses to pay for English, and it
     * pays it there only for German verb-final shapes with a list this narrow.
     *
     * What is left is the shape a no-match reply actually has: the value names what was looked for,
     * and the clause ends by saying nothing came back. Both languages, because the reply follows the
     * shopper's own.
     */
    private const TRAILING_ABSENCE = [
        'found\s+no',
        'found\s+nothing',
        'no\s+match(?:es)?',
        'no\s+results?',
        'nothing\s+(?:found|matched|available)',
        'returned\s+nothing',
        'gibt\s+es\s+(?:leider\s+)?(?:nicht|keine)',
        'leider\s+nicht',
        'nicht\s+(?:gefunden|verfügbar|vorhanden)',
        'keine\s+(?:treffer|ergebnisse|passenden)',
    ];

    /**
     * Clause openers that bound the window **forward only**.
     *
     * `and` opens a new clause going forward — *"it is merino and we have no silk"* asserts the
     * material — and would destroy the leading window going backward, where it continues a denied
     * list: *"no red and burgundy"* must keep the `no` in `burgundy`'s reach.
     */
    private const FORWARD_ONLY_OPENERS = ['and', 'und'];

    private const NEGATION_PATTERN = '/\b(?:%s)\b|n\'t/u';

    private function __construct() {}

    /**
     * Whether the clause after a value says the search came back empty.
     *
     * `$clause` already stops at sentence punctuation and at a comma, because the pattern's own
     * character class excludes them; what is left is to stop at a conjunction that opens a new clause
     * without punctuation, and then to look for one of {@see self::TRAILING_ABSENCE} — never the
     * general negation list, for the reason that constant gives.
     */
    public static function after(string $clause): bool
    {
        $openers = \sprintf('/\b(?:%s)\b/u', implode('|', [...self::CLAUSE_OPENERS, ...self::FORWARD_ONLY_OPENERS]));

        if (preg_match($openers, $clause, $found, \PREG_OFFSET_CAPTURE) === 1) {
            $clause = substr($clause, 0, (int) ($found[0][1] ?? 0));
        }

        return preg_match(\sprintf('/\b(?:%s)/u', implode('|', self::TRAILING_ABSENCE)), $clause) === 1;
    }

    /**
     * Whether the clause before a value denies it.
     *
     * `$window` is the raw preceding text, already lowercased by the caller; trimming to the clause
     * happens here so the rule and its vocabulary stay in one place.
     */
    public static function before(string $window): bool
    {
        return self::negated(self::lastClauseOf($window));
    }

    private static function negated(string $clause): bool
    {
        return preg_match(\sprintf(self::NEGATION_PATTERN, implode('|', self::NEGATIONS)), $clause) === 1;
    }

    /**
     * The tail of `$window` from the last clause boundary onwards.
     *
     * Punctuation is located by substring search and the conjunctions by a word-boundary pattern,
     * rather than one combined regex: a conjunction has to match on boundaries — `but` sits inside
     * `butter` and `aber` inside `haber` — while punctuation cannot carry them.
     *
     * **Byte offsets throughout, deliberately.** `PREG_OFFSET_CAPTURE` reports bytes, so pairing it
     * with `mb_substr` would cut at the wrong place in any window containing an umlaut — which is
     * every German window this exists for. Every cut here lands immediately after an ASCII
     * punctuation mark or after a whole matched conjunction, so it is always on a character boundary.
     */
    private static function lastClauseOf(string $window): string
    {
        $cut = 0;

        foreach ([';', '.', '!', '?', ',', "\n"] as $mark) {
            $at = strrpos($window, $mark);

            if ($at !== false) {
                $cut = max($cut, $at + 1);
            }
        }

        $pattern = \sprintf('/\b(?:%s)\b/u', implode('|', self::CLAUSE_OPENERS));
        $found = preg_match_all($pattern, $window, $matches, \PREG_OFFSET_CAPTURE);

        if ($found > 0) {
            // Indexed rather than destructured: the analyser types a `PREG_OFFSET_CAPTURE` pair as
            // `null|string` and refuses to unpack it, which is fair — nothing in the signature says
            // the pair is a pair.
            $last = $matches[0][$found - 1];
            $cut = max($cut, (int) ($last[1] ?? 0) + \strlen((string) ($last[0] ?? '')));
        }

        return substr($window, $cut);
    }
}
