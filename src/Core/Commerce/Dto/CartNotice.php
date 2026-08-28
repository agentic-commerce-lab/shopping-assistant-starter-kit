<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * One difference between the cart that was asked for and the cart that exists.
 *
 * Attributed to a variant, because a cart carries errors for every line and a tool that has just
 * added one product must not explain its own result with another line's problem.
 */
final readonly class CartNotice
{
    public function __construct(
        public string $variantId,
        public CartNoticeReason $reason,
    ) {}
}
