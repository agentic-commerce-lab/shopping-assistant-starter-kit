<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

/**
 * How a reply NAMES the readings of an ambiguous word, as opposed to which products it showed.
 *
 * Split from {@see CompetingGroups} at this project's complexity gate, and the boundary is real:
 * that class reads structure — a journey's configuration, a turn's cards — while this one reads
 * prose, which is the half that needs a vocabulary and a boundary rule.
 *
 * **It exists because "did it ask a question" was not enough.** Measured 2026-09-11: asked *"ich
 * brauche schrauben"*, `google/gemini-3.8-flash` rendered three motorcycle bolts, no bicycle ones,
 * and asked *"Wofür genau möchten Sie die Schrauben verwenden oder welche Größe benötigen Sie?"* —
 * a real question that cannot resolve the ambiguity it was asked in front of. A question only
 * counts when it could plausibly settle which reading was meant.
 */
final class GroupVocabulary
{
    private function __construct() {}

    /**
     * Which groups the PROSE names, by any of their own words.
     *
     * A reply that says "für Motorrad oder Fahrrad?" has put the ambiguity in front of the shopper
     * in words, which is as good as putting it in front of them as cards.
     *
     * @param array<string, list<string>> $groups
     *
     * @return list<string>
     */
    public static function mentionedIn(string $prose, array $groups): array
    {
        $found = [];

        foreach ($groups as $name => $words) {
            foreach ($words as $word) {
                if (self::contains($prose, $word)) {
                    $found[] = $name;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Whether the prose names the DIMENSION rather than its values — "für welches Fahrzeug?".
     *
     * The generic form resolves the ambiguity just as well as naming every reading, and a reply
     * should not have to enumerate the catalogue to pass.
     *
     * @param list<string> $axis
     */
    public static function asksTheAxis(string $prose, array $axis): bool
    {
        foreach ($axis as $word) {
            if (self::contains($prose, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case-insensitive, and bounded at the start only.
     *
     * German compounds are the reason: a reply resolving this ambiguity writes "Fahrradschrauben"
     * and "Motorradteile" as often as it writes the bare noun, so a match that required a word
     * boundary on the right would miss exactly the phrasings that count.
     */
    private static function contains(string $prose, string $word): bool
    {
        return preg_match(\sprintf('/(?<![\p{L}])%s/ui', preg_quote($word, '/')), $prose) === 1;
    }
}
