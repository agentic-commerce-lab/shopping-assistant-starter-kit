<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * One line of an order, as the card's row shows it.
 *
 * **All four fields reach the model since 2026-09-24.** {@see \Swag\AssistantStarterKit\Core\Tool\GetOrderTool}
 * returned line NAMES only, on D3's reasoning that "you ordered three of them" is a figure a model can
 * state wrongly — until a tester asked to order the same again and got one of each, because no
 * quantity had ever been handed over. D3 was relaxed for the shopper's own orders; the card is still
 * rendered by the server from this object, beside whatever the model said.
 *
 * `$name` is the label Shopware stored on the line at the time of the order, not the product's name
 * today. That is deliberate: a product renamed since is still the thing the shopper bought, and the
 * order is a record rather than a catalogue lookup.
 */
final readonly class OrderLine
{
    public function __construct(
        public string $name,
        public int $quantity,
        public float $unitPrice,
        public float $lineTotal,
    ) {}
}
