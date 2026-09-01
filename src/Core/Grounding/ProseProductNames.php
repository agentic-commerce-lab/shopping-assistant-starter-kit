<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * Which of the retrieved products a reply actually names.
 *
 * ## Why the ids were not enough
 *
 * {@see \Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor} chose what to render by looking
 * for product *ids* in the reply, falling back to "render the whole last tool batch" when it found
 * none. It always found none: the prompt forbids the model from describing how its answer is
 * displayed, so it names products in words, never by id.
 *
 * That was harmless while the model listed everything it had found — prose and cards agreed by
 * accident. Once the prompt asked for one recommendation and at most two alternatives, the two
 * diverged. Measured live on 2026-09-01 against the demo shop: asked for helmets after a conversation
 * about trails, the reply named three and the widget rendered six, with Kids Helmet, Commuter Helmet
 * and Helmet Rain Cover appearing as cards nobody had mentioned.
 *
 * ## Longest name first, then mask
 *
 * The catalogue contains a product called `Chain`, alongside `Wet Chain Lube 100ml`, `Chain Wear
 * Indicator` and `Chain Lock 90cm`. A plain substring search would put a chain in front of a shopper
 * who asked about lubricant — the exact class of wrong card this exists to remove. So names are tried
 * longest first and every match is blanked out of the working copy before shorter names are tried,
 * which leaves a short name matching only where it stands on its own.
 *
 * ## What it deliberately does not try to be
 *
 * **Not a grounding control.** Nothing here decides what may be *claimed*; it decides which cards
 * accompany a claim. Every figure on those cards is still rendered by {@see FactRenderer} from the
 * server's own record, and a name that matches nothing simply yields no card — the caller keeps its
 * existing fallback. So the failure mode is a card too many or too few, never a false statement.
 *
 * **Not word-boundary aware beyond the masking.** A product named `Tape` would match inside `Taped`.
 * Left alone deliberately: the fix would be a per-name regex with escaping, and the cost of being
 * wrong is one extra card on a shop that names a product after a common word fragment.
 */
final class ProseProductNames
{
    private function __construct() {}

    /**
     * @param array<string, string> $namesById product id => the product's name
     *
     * @return list<string> the ids whose name appears in the prose
     */
    public static function idsNamedIn(string $prose, array $namesById): array
    {
        if (trim($prose) === '') {
            return [];
        }

        $found = [];
        $remaining = $prose;

        foreach (self::longestFirst($namesById) as $id => $name) {
            if (stripos($remaining, $name) === false) {
                continue;
            }

            $found[] = $id;
            // Blanked rather than removed, so two names cannot become adjacent and form a third.
            $remaining = str_ireplace($name, ' ', $remaining);
        }

        return $found;
    }

    /**
     * The names worth searching for, longest first.
     *
     * Blank names are dropped: every string contains the empty string, so one would match every reply
     * and render a card for a product the shop failed to name.
     *
     * @param array<string, string> $namesById
     *
     * @return array<string, string>
     */
    private static function longestFirst(array $namesById): array
    {
        $usable = array_filter($namesById, static fn(string $name): bool => trim($name) !== '');

        uasort($usable, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $usable;
    }
}
