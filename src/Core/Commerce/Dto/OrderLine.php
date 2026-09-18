<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * One line of an order, as the card's row shows it.
 *
 * **Three of these four fields never reach the model.**
 * {@see \Swag\AssistantStarterKit\Core\Tool\GetOrderTool} returns line NAMES and nothing else, which
 * is D3 applied where it is easiest to forget: "you ordered three of them" is a figure, and a figure
 * the model states is a figure the model can state wrongly. The quantity, the unit price and the
 * line total are rendered onto the card by the server, beside the name the model said.
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
