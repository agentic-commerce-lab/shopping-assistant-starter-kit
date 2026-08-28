<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Checkout\Cart\Cart;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNotice;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;

/**
 * Turns a Shopware {@see Cart} into a {@see CartSummary}.
 *
 * Split out of {@see DalCartAdapter} so it can be tested against real `Cart` and `LineItem`
 * objects with no mocking at all. That matters here more than usual: this is the class whose
 * mistakes show a shopper a number that disagrees with the cart page they are about to open,
 * and a test needing four mocks is a test nobody trusts.
 *
 * It also carries Shopware's own complaints about the cart: {@see ProductCartProcessor} does not
 * refuse a quantity it dislikes, it silently changes it and records why as a cart error. Reading
 * only the line items would let a caller report the quantity it asked for over a cart holding
 * something else.
 */
final readonly class DalCartSummariser
{
    public function summarise(Cart $cart, string $currency, string $checkoutUrl): CartSummary
    {
        $lines = [];
        $itemCount = 0;

        foreach ($cart->getLineItems() as $item) {
            $price = $item->getPrice();

            $lines[] = new CartLine(
                lineId: $item->getId(),
                // A promotion or custom line has no referenced product. Reporting its line id as
                // a variant id would let a later blocklist check or add-to-cart act on a value
                // that is not a product at all, so it is reported empty rather than guessed.
                variantId: $item->getReferencedId() ?? '',
                name: $item->getLabel() ?? '',
                quantity: $item->getQuantity(),
                unitPrice: $price?->getUnitPrice() ?? 0.0,
                lineTotal: $price?->getTotalPrice() ?? 0.0,
            );

            $itemCount += $item->getQuantity();
        }

        return new CartSummary(
            lineItems: $lines,
            // The cart's own calculated total, never a sum of the lines: Shopware's total accounts
            // for shipping, promotions and tax mode, so re-adding the lines here would produce a
            // figure that disagrees with the cart page — and the assistant would be the one lying.
            total: $cart->getPrice()->getTotalPrice(),
            currency: $currency,
            itemCount: $itemCount,
            checkoutUrl: $checkoutUrl,
            notices: self::notices($cart),
        );
    }

    /**
     * Shopware's own complaints about this cart.
     *
     * `ProductCartProcessor` does not refuse a quantity it dislikes — it raises an under-minimum
     * line to `minPurchase`, rounds an off-step line onto its step, caps a line at available stock
     * and **removes** a line with nothing available, recording each as a cart error. Until
     * 2026-08-28 this class read only the line items, so a caller could report the quantity it had
     * asked for over a cart holding something else.
     *
     * The variant is recovered from the error id: all four of those errors are constructed with the
     * line's `referencedId` and implement `getId()` as `getMessageKey() . $id`, so stripping the key
     * leaves the variant. An error shaped otherwise yields an empty variant id rather than a wrong
     * one — an unattributed notice is still true; a misattributed one is not.
     *
     * @return list<CartNotice>
     */
    private static function notices(Cart $cart): array
    {
        $notices = [];

        foreach ($cart->getErrors() as $error) {
            $key = $error->getMessageKey();
            $id = $error->getId();

            $notices[] = new CartNotice(
                variantId: str_starts_with($id, $key) ? substr($id, \strlen($key)) : '',
                reason: CartNoticeReason::fromMessageKey($key),
            );
        }

        return $notices;
    }
}
