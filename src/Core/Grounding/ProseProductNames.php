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
 *
 * ## One name, several variants: `$preferredIds`
 *
 * Reported from a live shop on 2026-09-02: a search showed **Trail Jersey (Blue, M)**, the shopper
 * asked for size L, size L was added to the cart — and the card rendered beside the confirmation was
 * size M again. Shopware names a variant after its parent, so every variant of a family carries the
 * *same* `name`, and a name is therefore not an identity. Two of them were registered that turn:
 * Blue/M, replayed at turn start by {@see \Swag\AssistantStarterKit\Core\Prompt\RecentCardsContext},
 * and Blue/L, registered by {@see \Swag\AssistantStarterKit\Core\Tool\AddToCartTool} when it added
 * it. Both answer to "Trail Jersey", so this class resolved the name to whichever was registered
 * first — the turn-old one — and the shopper was shown a card for a variant the turn had not touched,
 * with that variant's price and stock, beside prose about the one it had.
 *
 * So a name resolves to ONE id, and when several ids answer to it, `$preferredIds` decides which:
 * the caller passes {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer::lastRetrievedBatch()},
 * the cards the turn's most recent tool call actually returned. Registration order remains the
 * tie-break for a name no preferred id answers to.
 *
 * Still one card per name, which is the pre-existing behaviour the masking already produced: a reply
 * offering two sizes of one product renders the acted-on one rather than both. Widening that means
 * matching option values in the prose, which is a different job than deciding what a *name* points
 * at.
 */
final class ProseProductNames
{
    private function __construct() {}

    /**
     * @param array<string, string> $namesById    product id => the product's name
     * @param list<string>          $preferredIds the ids to resolve an ambiguous name to — several
     *                                            variants of one family share a name, so this is
     *                                            what decides which of them a name points at. See
     *                                            the class docblock and {@see ProductNameIndex}.
     *
     * @param list<string> $excludedIds ids this reply has ruled out — see {@see ContradictedVariants}
     *
     * @return list<string> the ids whose name appears in the prose, at most one per name
     */
    public static function idsNamedIn(
        string $prose,
        array $namesById,
        array $preferredIds = [],
        array $excludedIds = [],
    ): array {
        if (trim($prose) === '') {
            return [];
        }

        $index = new ProductNameIndex($namesById, $excludedIds);
        $found = [];
        $remaining = $prose;

        foreach ($index->namesLongestFirst() as $name) {
            $at = stripos($remaining, $name);

            if ($at === false) {
                continue;
            }

            $id = $index->idFor($name, $preferredIds);

            if ($id !== null) {
                // Keyed by WHERE the reply says it, not by the order names are searched in. Searching
                // has to run longest-name-first or `Chain` matches inside `Wet Chain Lube 100ml`;
                // rendering in that order puts the longest name's card first, which has nothing to do
                // with what the reply is about. Measured on staging 2026-09-02: *"show me the trail
                // jersey in black, size M"* answered correctly and rendered **Thermal Jersey Long
                // Sleeve** above the Trail Jersey, because its name is longer — so the first card, and
                // its add button, belonged to a product the shopper had not asked for.
                //
                // The position is taken from `$remaining` rather than the original prose: earlier
                // names are already blanked there, and blanking preserves offsets because it replaces
                // each matched name with the same number of BYTES. `stripos()` counts bytes, so a
                // name carrying an umlaut would shift every later offset if this counted characters.
                $found[$at] = $id;
            }

            // Blanked rather than removed, so two names cannot become adjacent and form a third.
            $remaining = str_ireplace($name, str_repeat(' ', \strlen($name)), $remaining);
        }

        ksort($found);

        return array_values($found);
    }
}
