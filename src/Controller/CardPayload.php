<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Tool\BoundedProperties;
use Swag\AssistantStarterKit\Core\Tool\CartCorrectionNote;

/**
 * Serialises rendered cards for the wire.
 *
 * Split out of {@see AssistantController} (cyclomatic-complexity) rather than suppressed. It is also
 * the place where D3 arrives at the HTTP boundary: **every figure here comes from a rendered
 * `ProductCard`**, and nothing is parsed out of the model's prose. A field added that reads anything
 * else reopens the gap ruling R47 closed.
 *
 * `stockSource` is exposed rather than left implicit. It says whether a stock figure belongs to the
 * variant the shopper asked about or to its parent, which is the difference between an honest answer
 * and the one that cancels orders (D4) — a client cannot infer it and should not have to.
 */
final readonly class CardPayload
{
    /**
     * `$cart` is REQUIRED, and deliberately has no empty default.
     *
     * A default would let a call site render cards without answering "is this already in the
     * cart?", which is exactly the omission that shipped: the succeeded state lived only in the
     * click handler's DOM mutation, so every card rebuilt from this payload read "Add to cart" —
     * including the confirmation card the assistant renders after its own `add_to_cart`, whose
     * button then really did add a second one. Both call sites hold a gateway; neither has an
     * excuse. See {@see \Swag\AssistantStarterKit\Tests\Controller\CardPayloadInCartTest}.
     *
     * @param list<ProductCard> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function of(array $cards, CartSummary $cart): array
    {
        return array_map(
            static fn(ProductCard $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'description' => $card->description,
                'price' => $card->price,
                'currency' => $card->currency,
                // The quantity the price above assumes, and whether cheaper tiers exist. Both come
                // from the rendered card, like every other figure here — nothing is parsed out of the
                // model's prose (ruling R47).
                'priceQuantity' => $card->priceQuantity,
                'hasVolumePricing' => $card->hasVolumePricing,
                // The figure German law requires beside a price, for anything sold by volume or
                // weight — absent for the rest, which is most of a catalogue. Already divided by
                // the gateway so the card cannot disagree with the product page it links to.
                'basePrice' => $card->basePrice === null
                    ? null
                    : [
                        'price' => $card->basePrice->price,
                        'referenceUnit' => $card->basePrice->referenceUnit,
                        'unit' => $card->basePrice->unit,
                    ],
                'stock' => $card->stock,
                'stockSource' => $card->stockSource->value,
                'inStock' => $card->isInStock(),
                'deliveryTime' => $card->deliveryTime,
                'url' => $card->url,
                'imageUrl' => $card->imageUrl,
                // The shop's own department for this product, so a card can disclose an ambiguity
                // the prose may not mention. Empty for a catalogue that records no path.
                'department' => $card->categoryPath[0] ?? null,
                'options' => $card->options,
                'properties' => BoundedProperties::of($card->properties),
                // The one place a document's URL crosses a boundary, and deliberately the HTTP one
                // rather than the model's. The shop renders this link exactly as it renders `url`
                // and `imageUrl`; ToolProductSummary hands the model the titles alone, so nothing
                // the model writes can invent, alter or misattribute the address.
                //
                // One row per DOCUMENT, not per file — see CardDocuments for the live rendering
                // that made a card offer the Dutch variant of a datasheet and hide the English one.
                'documents' => CardDocuments::of($card->documents),
                // How many of THIS variant the cart already holds; 0 for everything else.
                //
                // The cart's own figure, read off the line rather than remembered from whatever
                // quantity was requested — Shopware corrects a request against `minPurchase`,
                // `purchaseSteps` and available stock, so the two disagree routinely and only one
                // of them is true. Same rule as every other field here: the server states it, the
                // client renders it, nothing is inferred from prose (ruling R47).
                //
                // Delegated to `CartCorrectionNote::lineQuantity()` rather than looped again here,
                // for the reason `AddToCartTool::existingLineQuantity()` delegates to it: one
                // "find the line for this variant" search, one place to fix.
                'inCart' => CartCorrectionNote::lineQuantity($cart, $card->id),
            ],
            $cards,
        );
    }
}
