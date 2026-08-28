<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

final readonly class CartSummary
{
    // @mago-expect lint:excessive-parameter-list
    // One field per summary concept (lines, total, currency, item count, checkout url, notices);
    // no natural sub-object to extract without adding indirection for its own sake.
    /**
     * @param list<CartLine>   $lineItems
     * @param list<CartNotice> $notices where this cart differs from what was requested
     */
    public function __construct(
        public array $lineItems = [],
        public float $total = 0.0,
        public string $currency = 'EUR',
        public int $itemCount = 0,
        public string $checkoutUrl = '/checkout/confirm',
        public array $notices = [],
    ) {}
}
