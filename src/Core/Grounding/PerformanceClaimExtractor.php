<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * The sentences in which a reply says **how long a product holds out, or how far it goes**.
 *
 * ## The claim this exists for
 *
 * The single most expensive line in the trace export of 34 real conversations, and the one
 * {@see SuppliedFactClaimExtractor} is blind to by construction:
 *
 * > **Shopper:** *"how long would it take to break it. my last lock was cut within 3 minutes"*
 * > **Gustav:** *"…would typically take much longer to cut than 3 minutes — **often 10 minutes or
 * > more with standard tools**"*
 *
 * The shop's whole description for that lock was *"A 90 cm chain in a fabric sleeve, for locking to
 * awkward stands."* No time, no tool, no standard. A shopper can buy a lock on that sentence and be
 * wrong about the one thing a lock is for — which is why this is the highest-severity class on the
 * board and not merely the most annoying.
 *
 * It carries none of the grammar that class keys on: no `supplied with`, no `included`, no
 * `rated for`. So it needed the other half of the pair.
 *
 * ## A figure next to a physical verb
 *
 * The rule is a measure — *10 minutes*, *four hours*, *800 lumens* — standing within a clause of a
 * verb about **withstanding or performing**: cutting, resisting, lasting, running, holding.
 * {@see self::VERBS} is a closed set of those, and it is what separates this from every other
 * number a reply contains.
 *
 * **What is deliberately not in that set: `take`, `arrive`, `ship`, `deliver`, `return`.** A period
 * about the merchant's *business* — delivery windows, revocation deadlines, warranty terms — belongs
 * to {@see PassageAudit::unsupportedPeriods()}, which checks it against the shop's documents and
 * normalises fourteen days against two weeks to do it. Reading those here would double-report them
 * and, worse, report them against the wrong source of truth: a product description has no business
 * stating a returns deadline.
 *
 * The overlap that remains is handled by the input rather than by the pattern. {@see DescriptionAudit}
 * passes the retrieved passages alongside the descriptions, so a figure the merchant's own document
 * states is supported here as well — which is why *"the sale lasts 3 days"* does not fire when a
 * passage says so.
 *
 * ## What it cannot see
 *
 * A comparative with no figure at all — *"far harder to cut than a cable lock"* — states something
 * unverifiable and contains no measure, so nothing matches. That is the honest floor, the same one
 * {@see PassageAudit} names for itself: this catches a reply that invents a *number*, not one that
 * invents a *ranking*.
 */
final readonly class PerformanceClaimExtractor
{
    /**
     * Verbs about withstanding or performing, in both languages the assistant answers in.
     *
     * A stem list rather than whole words, so `cut` reaches `cutting` and `resist` reaches
     * `resistance`, without inflating the pattern with every form.
     */
    private const VERBS =
        'cut|resist|withstand|surviv|endur|last|run|hold|defeat|break|saw|drill|pick|'
            . 'schneid|widersteh|aushalt|halt|durchhalt|knack';

    /**
     * Units a product claim is measured in.
     *
     * Time, distance, mass, force, light and pressure &mdash; every measure the catalogue actually
     * uses, and nothing that would make a price or a product count look like a performance figure.
     */
    private const UNITS =
        'second|sekunde|minute|minuten|hour|stunde|stunden|day|tag|tage|'
            . 'week|woche|wochen|month|monat|monate|year|jahr|jahre|'
            . 'km|kilometre|kilometer|mile|meile|metre|meter|'
            . 'kg|kilo|gram|gramm|newton|nm|lumen|lux|bar|psi|watt|volt';

    /**
     * How far from the figure a verb may sit and still govern it.
     *
     * Wide enough for *"would take much longer to cut than 3 minutes"*, where `cut` is nineteen
     * characters ahead of the figure, and bounded by the clause rather than by this number: the
     * pattern's own character class stops at sentence punctuation.
     */
    private const REACH = 60;

    /**
     * @return list<string> the claim phrases, first appearance order, each reported once
     */
    public function extract(string $text): array
    {
        $numbers = NumberWords::numberPattern();

        // Two orders, because both occur: the verb ahead of the figure ("longer to cut than 3
        // minutes") and the figure ahead of the verb ("10 minutes to cut"). Group 1 is the window
        // ClaimStands reads, group 2 the phrase, and groups 3 and 4 sit inside a LOOKAHEAD so they
        // consume nothing — a second claim in the same sentence is still examined, the construction
        // PropertyMention explains. Every alternative in the phrase is non-capturing, including the
        // one NumberWords supplies, which is what keeps the terminator at 4.
        $pattern = \sprintf(
            '/(.{0,%4$d}?)((?:\b(?:%2$s)[\p{L}]*\b[^.!?;\n]{0,%5$d}?(?:%1$s|\d{1,4})\s*(?:%3$s)s?\b)'
            . '|(?:(?:%1$s|\d{1,4})\s*(?:%3$s)s?\b[^.!?;\n]{0,%5$d}?\b(?:%2$s)[\p{L}]*\b))'
            . '(?=([^.!?\n]{0,300}([.!?])?))/isu',
            $numbers,
            self::VERBS,
            self::UNITS,
            ClaimStands::CHARS,
            self::REACH,
        );

        if (preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER) === false) {
            return [];
        }

        $claims = [];

        foreach ($matches as $match) {
            if (ClaimStands::at($match[1] ?? '', $match[4] ?? '')) {
                $claims[trim((string) ($match[2] ?? ''))] = true;
            }
        }

        unset($claims['']);

        return array_map(strval(...), array_keys($claims));
    }
}
