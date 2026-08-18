<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

final readonly class CartLine
{
    // @mago-expect lint:excessive-parameter-list
    // One field per cart line concept (identity, name, quantity, pricing); no natural
    // sub-object to extract without adding indirection for its own sake.
    public function __construct(
        public string $lineId,
        public string $variantId,
        public string $name,
        public int $quantity,
        public float $unitPrice,
        public float $lineTotal,
    ) {}
}
