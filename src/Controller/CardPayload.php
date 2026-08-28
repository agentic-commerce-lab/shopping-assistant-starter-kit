<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Tool\BoundedProperties;

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
     * @param list<ProductCard> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function of(array $cards): array
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
                'stock' => $card->stock,
                'stockSource' => $card->stockSource->value,
                'inStock' => $card->isInStock(),
                'deliveryTime' => $card->deliveryTime,
                'url' => $card->url,
                'imageUrl' => $card->imageUrl,
                'options' => $card->options,
                'properties' => BoundedProperties::of($card->properties),
            ],
            $cards,
        );
    }
}
