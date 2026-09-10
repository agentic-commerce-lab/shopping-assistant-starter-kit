<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * How many more PRODUCTS a search could still show, stated outright.
 *
 * ## The subtraction the model was being left to do
 *
 * Both numbers were already in the tool result before this class existed. `total` means "how many
 * are in `products`" and `matched` means "how many the search actually found" — two fields, one of
 * them called total, meaning the opposite of a total. Neither is renamed here: ruling T3's argument
 * against redefining a field the model has learned holds. So the difference is handed over already
 * done.
 *
 * ## Counted in products, not in rows, and that is the whole design
 *
 * **The first version of this class subtracted raw counts, and it would have made the worst session
 * in the corpus worse.** Session `07b4c0f7`: a shopper asked for lights, then *"more please"*, then
 * *"That are the same ones"*. Retrieval had 9 survivors across **4 families**, and
 * {@see FamilyDiversifier} returns one card per family — so 4 cards was everything the shop had to
 * offer for that query, at any limit the model asked for. A raw subtraction would have reported
 * `withheld: 5` and invited exactly the promise the shopper was already pushing back on: five more
 * lights that do not exist as separate products.
 *
 * Those five are size and colour variants of the four families already on screen, and
 * {@see TruncatedFamilies} discloses them in the right terms — as option values of a product, not as
 * products. So this class counts **distinct product identities**: a card's `parentId` when it has
 * one, its own id when it does not. Three of sixteen helmets is not thirteen hidden helmets; it is
 * two more helmet models and eleven sizes of the three already shown.
 *
 * ## A floor when the window filled up
 *
 * `matched` is a floor rather than a census whenever the candidate window saturated (ruling T4), and
 * this is derived from the same survivor list, so it inherits that. It sits beside `more: true` in
 * exactly that case, which is what says so.
 *
 * ## Only when there is one
 *
 * A field reading `withheld: 0` on every complete answer is context the model pays to read on the
 * turns where it means nothing, and it invites a sentence about withholding on a reply that withheld
 * nothing. The idiom this file already follows is that the reply discloses what it does not
 * contain — {@see TruncatedFamilies}, {@see TermContribution}, {@see SearchProductsTool::NO_MATCH_NOTE}.
 */
final class WithheldCount
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $survivors what retrieval found, after the redundant-parent filter
     * @param list<ProductCard> $returned  what the shopper will actually see
     *
     * @return array{withheld?: int} spread into the tool result, empty when nothing is withheld
     */
    public static function replyFor(array $survivors, array $returned): array
    {
        $withheld = \count(self::products($survivors)) - \count(self::products($returned));

        if ($withheld <= 0) {
            return [];
        }

        return ['withheld' => $withheld];
    }

    /**
     * The distinct products a card list represents, keyed so a count is a count.
     *
     * A card with a `parentId` is one member of that product; a card without one is its own. This is
     * the same identity {@see TruncatedFamilies} groups by, minus its exclusion of standalone
     * products — that class asks "which families lost members", which a standalone cannot, and this
     * one asks "how many products are there", which it can.
     *
     * @param list<ProductCard> $cards
     *
     * @return array<string, true>
     */
    private static function products(array $cards): array
    {
        $products = [];

        foreach ($cards as $card) {
            $products[$card->parentId ?? $card->id] = true;
        }

        return $products;
    }
}
