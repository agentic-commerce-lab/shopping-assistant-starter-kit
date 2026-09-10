<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * What a bundle's quoted figure covers, when the bundle has parts a shopper may decline.
 *
 * ## The measurement
 *
 * Shopware Commercial derives a bundle's two numbers from **different sets of its items**. Stock is
 * `min(floor(item.stock / item.quantity))` over the **required** items; the price is the sum of
 * **every** item, optional ones included, less the discount — `BundlePriceCalculator` filters on
 * nothing. Measured on a live bundle 2026-09-10: €73.57 is 12.90 + 14.90 + 24.00 required **plus**
 * 14.90 + 16.90 optional, less 12%, while its stock of 14 came from the required three alone.
 *
 * So the figure on the card is the **maximum** a shopper might pay, and the card presented it as
 * the price. That is not Commercial being wrong — a bundle is offered whole and priced whole — but
 * it is a figure whose scope the shopper cannot see.
 *
 * ## Why the tool says it rather than the model working it out
 *
 * The idiom and the reasoning are {@see EverythingShown}'s. The model now receives an `optional`
 * flag per item and a price on the card, and deriving "so the price includes things I could drop"
 * from those two is exactly the cross-referencing the September 2026 review measured it failing —
 * `total` and `matched` were both in the reply and three consecutive turns still presented part of
 * a result set as the whole of it. The shop computes the fact; the reply states it.
 *
 * ## What it must never do
 *
 * **Quote a figure.** What the bundle costs without its optional items is a price Shopware did not
 * calculate: the discount's own basis changes with the items, and summing the remainder here would
 * invent the one kind of number this pipeline exists to keep out of a reply. The note names the
 * shape of the caveat and leaves every number to the card.
 */
final class BundlePriceNote
{
    /**
     * What every bundle result is told.
     *
     * **The saving clause is here because of a measured turn, not a hunch.** Deployed, and asked
     * *"What is in the Drivetrain Care Bundle and how much do I save?"*, the assistant spent four of
     * its five tool calls searching for the bundle's items one at a time to price them, and the turn
     * ended in `tool_limit_exceeded`. Σ(items) − bundle price is a figure Shopware never calculated:
     * the discount is applied to the combined price, so a saving derived by adding up items and
     * subtracting is arithmetic of the model's own — and `ProseAudit::unbackedPrices()` would flag
     * it, correctly, as a figure no card backs.
     *
     * It says the item prices are not the way rather than "you may not answer", because the shop
     * genuinely does sell those items separately and the model is not wrong to be curious; what it
     * must not do is spend the turn on it and then quote a difference.
     */
    public const NOTE =
        'One product in this reply is a bundle: a set the shop sells as a single item at a single '
            . 'price. You may say what it contains, using only the contents given with it. Do not '
            . 'look up its items one by one to work out a saving, and never state or estimate how '
            . 'much a bundle saves — the shop publishes the bundle price and has not calculated the '
            . 'difference against buying the items separately.';

    /**
     * Added to {@see self::NOTE} when a bundle in the reply has items the shopper may decline.
     *
     * Deliberately says the price **includes** the optional items rather than "may be lower without
     * them" — the first is a fact about the figure on the card, the second is an invitation to
     * estimate one that is not.
     */
    public const OPTIONAL_ITEMS =
        'That bundle contains optional items, and the price shown covers every item including the '
            . 'optional ones — it is the most the shopper would pay, not the least. You may say which '
            . 'items are optional, but not what the bundle would cost without them.';

    private function __construct() {}

    /**
     * @param list<ProductCard> $cards the cards this reply is built from
     *
     * @return array{bundle_note?: string} spread into the tool result
     */
    public static function replyFor(array $cards): array
    {
        $bundles = array_filter($cards, static fn(ProductCard $card): bool => $card->bundleItems !== []);

        if ($bundles === []) {
            // An ordinary product has no contents, so there is nothing here to qualify and no
            // saving anyone could be tempted to compute.
            return [];
        }

        return ['bundle_note' => self::NOTE . (self::anyOptional($bundles) ? ' ' . self::OPTIONAL_ITEMS : '')];
    }

    /**
     * @param array<array-key, ProductCard> $bundles
     */
    private static function anyOptional(array $bundles): bool
    {
        foreach ($bundles as $bundle) {
            foreach ($bundle->bundleItems as $item) {
                if (!$item->required) {
                    return true;
                }
            }
        }

        return false;
    }
}
