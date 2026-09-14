<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * The shop's own department for a product, as the model is allowed to hear it.
 *
 * ## Why a category crosses this seam when a property group name may not
 *
 * The system prompt forbids showing a shopper a field name or the words the shop groups its filters
 * under, because those are internal: "Mounting: Frame" is a facet, not a sentence. A department is
 * the opposite kind of string — it is the shop's own navigation, the words already printed in the
 * menu the shopper clicked to get here, and `browse_categories` hands the same names to the model
 * already. Saying "die eine ist aus den Fahrradteilen" quotes the shop back to itself.
 *
 * ## The failure it exists for
 *
 * Measured 2026-09-14 on a catalogue holding a product called exactly "Innensechskantschraube" in
 * both the motorcycle and the bicycle department. Asked *"ich brauche schrauben"*, the assistant
 * returned cards from a **different department on each of three runs** and never once said that a
 * choice had been made. It could not: nothing in a tool result named a department, and a bolt's name
 * carries no clue about the vehicle it belongs to — unlike a tyre, where "Ganzjahresreifen" gives
 * the answer away and the same journey therefore passes.
 *
 * So this is not a new behaviour, it is the missing half of an existing one. The retrieval already
 * spreads across departments as a side effect of {@see FamilyDiversifier} promoting distinct
 * families; what nobody could do was say so.
 *
 * ## Only the top level
 *
 * `categoryPath` is the full ancestry — "Fahrradteile > Komponenten > Kleinteile > Schrauben &
 * Muttern" — and all of it but the first element describes WHAT the product is, which the name
 * already says better. The first element is the only one that answers the question an ambiguous term
 * raises: which world is this for. Emitting the rest would cost tokens on every product to repeat
 * the obvious, and would invite the model to recite a path at a shopper.
 */
final class Departments
{
    private function __construct() {}

    /**
     * The summary fragment to merge in: the department, or nothing at all.
     *
     * **Absent rather than empty**, like `soldOut` and `documents` beside it. A shop whose gateway
     * records no category path — which today is every real Shopware shop, since
     * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalProductCardMapper} still hardcodes an
     * empty one — says nothing rather than saying "unknown", and the model behaves exactly as it did
     * before. That is what makes this safe to ship ahead of the DAL half.
     *
     * @param list<string> $categoryPath the product's ancestry, outermost first
     *
     * @return array{department?: string}
     */
    public static function keyFor(array $categoryPath): array
    {
        $department = trim($categoryPath[0] ?? '');

        return $department === '' ? [] : ['department' => $department];
    }
}
