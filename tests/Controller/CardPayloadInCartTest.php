<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Controller\CardPayload;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * Whether a card already sits in the shopper's cart, and how many.
 *
 * ## Why the card has to say this
 *
 * The add button's succeeded state used to live **only** in the click handler's DOM mutation
 * (`markAdded()`), so a card rebuilt from this payload always read "Add to cart" — whatever the
 * cart held. Three shopper-visible consequences, one cause:
 *
 * 1. The assistant adds a product through `add_to_cart` and re-renders the same card to confirm
 *    *which variant* went in. The confirmation said "Add to cart".
 * 2. The button was not merely mislabelled, it was live: clicking it added a **second** one.
 * 3. A re-hydrated conversation rebuilt every card from scratch, so even a card the shopper had
 *    clicked themselves reverted.
 *
 * ## Why the quantity rather than a boolean
 *
 * Same reason `stock` is a figure and not "available": "In cart" and "In cart (3)" answer different
 * questions, and a client cannot recover the second from the first. The figure is the **cart's**,
 * read off the line — never the quantity anyone requested. Shopware corrects a requested quantity
 * against `minPurchase`, `purchaseSteps` and available stock (see {@see \Swag\AssistantStarterKit\Core\Tool\CartCorrectionNote}),
 * so the two disagree routinely and only one of them is true.
 */
#[CoversClass(CardPayload::class)]
final class CardPayloadInCartTest extends TestCase
{
    private function card(string $id): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: 'fx-017',
            name: 'YUASA Batterie YTZ10S',
            description: null,
            price: 89.90,
            currency: 'EUR',
            stock: 7,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }

    private function line(string $variantId, int $quantity): CartLine
    {
        return new CartLine(
            lineId: 'line-' . $variantId,
            variantId: $variantId,
            name: 'YUASA Batterie YTZ10S',
            quantity: $quantity,
            unitPrice: 89.90,
            lineTotal: 89.90 * $quantity,
        );
    }

    /**
     * @param list<CartLine> $lines
     *
     * @return array<string, mixed>
     */
    private function payloadOf(ProductCard $card, array $lines): array
    {
        $payloads = (new CardPayload())->of([$card], new CartSummary(lineItems: $lines));
        self::assertCount(1, $payloads);

        return $payloads[0] ?? [];
    }

    public function testAProductTheCartHoldsReportsItsQuantity(): void
    {
        $payload = $this->payloadOf($this->card('fx-017-black-m'), [
            $this->line('fx-017-black-m', 3),
        ]);

        self::assertSame(3, $payload['inCart']);
    }

    public function testAProductTheCartDoesNotHoldReportsZero(): void
    {
        $payload = $this->payloadOf($this->card('fx-017-black-m'), []);

        self::assertSame(0, $payload['inCart']);
    }

    /**
     * The exact variant, never its family.
     *
     * A shopper holding Black/M has not bought Black/L, and a card for Black/L that claimed
     * otherwise would be the same class of lie as quoting a parent's aggregate stock for a variant
     * — the one D4 exists to prevent. The ids differ by one character precisely because that is how
     * a prefix or parent match would slip through unnoticed.
     */
    public function testASiblingVariantInTheCartDoesNotCount(): void
    {
        $payload = $this->payloadOf($this->card('fx-017-black-m'), [
            $this->line('fx-017-black-l', 2),
        ]);

        self::assertSame(0, $payload['inCart']);
    }
}
