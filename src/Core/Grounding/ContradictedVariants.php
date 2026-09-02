<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Retrieved variants a reply has ruled out by naming other option values of the same group.
 *
 * ## The defect this exists for
 *
 * Measured on the staging shop, 2026-09-02:
 *
 * ```
 * > hast du das in M und L?
 *   reply: Ich habe das Club Jersey sowohl in Größe M als auch in Größe L gefunden.
 *   CARD:  Club Jersey · Colour: Red · Size: XL
 * ```
 *
 * The card is a size the sentence rules out. Every variant of a family answers to the same name, so
 * {@see ProseProductNames} resolves that name to ONE id — and until this existed, which one came down
 * to registration order. A shopper who clicks that card buys a size nobody discussed.
 *
 * ## The rule, and why it is this narrow
 *
 * A card is contradicted when the reply mentions values of one of its option groups and the card's own
 * value is not among them. Naming M and L rules out XL; naming no size at all rules out nothing.
 *
 * It deliberately does NOT try to pick the *best* card, or to render one card per value the reply
 * mentions. Both were considered: rendering all of them turns "which colour would you like — White,
 * Red or Blue?" into three cards, which is a different feature with its own risks. This only removes
 * cards the reply has already excluded, so the worst case it can produce is the card that was there
 * before, or none.
 *
 * ## What counts as "mentioned"
 *
 * {@see MentionedOptionValues} owns that question, including the word boundaries single-letter sizes
 * need. A value the shopper's language spells differently ("Rot" for `Red`) simply does not match, and
 * an unmentioned group excludes nothing — so the failure mode is the card that was there before.
 */
final class ContradictedVariants
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $cards every card this turn retrieved
     *
     * @return list<string> the ids a name may not be resolved to for this reply
     */
    public static function in(string $prose, array $cards): array
    {
        $mentioned = MentionedOptionValues::of($prose, $cards);
        $excluded = [];

        foreach ($cards as $card) {
            if (self::isRuledOut($card, $mentioned)) {
                $excluded[] = $card->id;
            }
        }

        return array_values(array_unique($excluded));
    }

    /**
     * Whether the reply names other values of one of this card's own option groups.
     *
     * A group the reply never mentions rules nothing out — that is what keeps the rule narrow, and why
     * a reply naming no size at all leaves every size standing.
     *
     * @param array<string, list<string>> $mentioned
     */
    private static function isRuledOut(ProductCard $card, array $mentioned): bool
    {
        foreach ($card->options as $group => $value) {
            $named = $mentioned[(string) $group] ?? [];

            if ($named !== [] && !\in_array(mb_strtolower($value), $named, true)) {
                return true;
            }
        }

        return false;
    }
}
