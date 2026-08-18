<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

final readonly class CartSummary
{
    /** @param list<CartLine> $lineItems */
    public function __construct(
        public array $lineItems = [],
        public float $total = 0.0,
        public string $currency = 'EUR',
        public int $itemCount = 0,
        public string $checkoutUrl = '/checkout/confirm',
    ) {}
}
