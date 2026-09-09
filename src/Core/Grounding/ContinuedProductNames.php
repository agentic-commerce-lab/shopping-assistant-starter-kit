<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * A retrieved product's name with another capitalised word stuck to the end of it — a name the
 * catalogue does not have, wearing one it does.
 *
 * ## The reply this exists for
 *
 * Measured through the staging endpoint on 2026-09-09, asked for a helmet accessory:
 *
 * > For a useful and cool accessory to pair with a bike helmet, I would recommend the **Road Helmet
 * > Aero Mirror**. It mounts on your helmet and gives you a clear view of traffic behind you… Here
 * > are two other options: **Gravel Helmet Visor** … **Kids Helmet Light** …
 *
 * Three products that do not exist, and the cards rendered underneath them were **Road Helmet Aero**,
 * **Gravel Helmet** and **Kids Helmet** — because {@see ProseProductNames} searched for a retrieved
 * name with `stripos()` and found each one as a prefix of the invented phrase. So a shopper saw three
 * real helmets labelled as a mirror, a visor and a lamp, each with a real price and a real add
 * button.
 *
 * That is the failure this project's whole claim is built to exclude, arriving through the one door
 * left open: `validate` reported `inventedProductIds: []` and was right — the ids were real. The
 * enforcement boundary is drawn around ids, and the shopper reads names.
 *
 * ## Why a rendering decision and not an invention detector
 *
 * {@see ProseProductNames}' docblock states its own remit: *"Nothing here decides what may be
 * claimed; it decides which cards accompany a claim… the failure mode is a card too many or too few,
 * never a false statement."* That held until a card began legitimising an invented name, which is
 * this shape exactly. So the fix belongs where the decision is: a continued name yields **no card**.
 *
 * Stated plainly, because it is a partial fix: the invented name stays in the prose. What changes is
 * that nothing beside it corroborates it, and {@see self::in()} makes the class countable, which is
 * what a real detector needs first.
 *
 * ## The rule, and what it costs
 *
 * A match counts only when the name is not immediately followed by a space and an uppercase letter.
 * Punctuation ends it — *"the Gravel Helmet. Alternatives:"* still matches — and so does a lowercase
 * continuation, which is what ordinary prose does: *"the Gravel Helmet comes in M and L"*.
 *
 * The cost is a false negative on a reply writing a bare option after a name: *"Gravel Helmet Black"*
 * loses its card. The trade is deliberate and it is asymmetric — a missing card beside a correctly
 * named product is a presentation loss, a real card beneath an invented name is the product's central
 * guarantee failing where a shopper can see it. A longer name that IS real is unaffected:
 * {@see ProseProductNames} tries names longest-first and blanks each match, so a real longer name has
 * already claimed the text before a shorter one looks at it.
 */
final class ContinuedProductNames
{
    /** How many following capitalised words are kept when reporting the phrase. */
    private const MAX_TRAILING_WORDS = 3;

    private function __construct() {}

    /**
     * Whether the occurrence of `$name` at `$at` is continued by another capitalised word.
     *
     * `$at` and the arithmetic are BYTE offsets, matching `stripos()` — the caller's own offsets are
     * bytes for the reason its docblock gives, that blanking preserves them.
     */
    public static function continuesAfter(string $prose, int $at, string $name): bool
    {
        $suffix = substr($prose, $at + \strlen($name), 12);

        return preg_match('/^ \p{Lu}/u', $suffix) === 1;
    }

    /**
     * Spread into {@see \Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor}'s existing
     * `grounding.select` record, and empty when the reply is clean.
     *
     * A field on a record that is already written rather than a stage of its own: the fact belongs
     * to the grounding decision, a turn with nothing to report should cost no row, and the caller
     * then needs no branch — which is the same shape {@see \Swag\AssistantStarterKit\Core\Tool\EverythingShown}
     * uses for the same reason.
     *
     * @param array<string, string> $namesById product id => name, as {@see ProductNames::of()} builds it
     *
     * @return array{continuedNames?: list<string>}
     */
    public static function payload(string $prose, array $namesById): array
    {
        $found = self::in($prose, $namesById);

        return $found === [] ? [] : ['continuedNames' => $found];
    }

    /**
     * The invented phrases a reply contains.
     *
     * @param array<string, string> $namesById product id => name, as {@see ProductNames::of()} builds it
     *
     * @return list<string> each a retrieved name plus what was stuck to it, deduplicated
     */
    public static function in(string $prose, array $namesById): array
    {
        $found = [];

        foreach (array_unique(array_values($namesById)) as $name) {
            foreach (self::phrasesFor($prose, $name) as $phrase) {
                if (!\in_array($phrase, $found, true)) {
                    $found[] = $phrase;
                }
            }
        }

        return $found;
    }

    /**
     * Every continued occurrence of one name, as the phrase the reply actually wrote.
     *
     * Its own method so the walk and the de-duplication do not nest — the gate's nesting bound is
     * four, and a loop inside a loop inside two conditions was five.
     *
     * @return list<string>
     */
    private static function phrasesFor(string $prose, string $name): array
    {
        if ($name === '') {
            return [];
        }

        $phrases = [];
        $at = 0;

        while (($at = stripos($prose, $name, $at)) !== false) {
            if (self::continuesAfter($prose, $at, $name)) {
                $phrases[] = self::phraseAt($prose, $at, $name);
            }

            $at += \strlen($name);
        }

        return $phrases;
    }

    private static function phraseAt(string $prose, int $at, string $name): string
    {
        $matched = substr($prose, $at, \strlen($name));
        $rest = substr($prose, $at + \strlen($name));

        $pattern = \sprintf('/^(?: \p{Lu}[\p{L}\p{N}\-]*){1,%d}/u', self::MAX_TRAILING_WORDS);

        if (preg_match($pattern, $rest, $trailing) !== 1) {
            return $matched;
        }

        return $matched . $trailing[0];
    }
}
