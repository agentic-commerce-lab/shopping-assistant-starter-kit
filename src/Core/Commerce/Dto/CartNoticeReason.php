<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * Why Shopware's cart differs from what was asked for.
 *
 * Shopware reports these as cart errors carrying human message strings; this maps the stable
 * message keys onto a closed set so a tool can explain the difference without ever putting
 * Shopware's own English sentence in front of a shopper who is reading German.
 *
 * `Other` is not a failure of this enum. An unrecognised key still means the cart is not what was
 * requested, and saying "the shop adjusted this" is honest; inventing a specific reason is not.
 */
enum CartNoticeReason: string
{
    case MinimumQuantity = 'minimum_quantity';
    case PurchaseSteps = 'purchase_steps';
    case StockLimited = 'stock_limited';
    case OutOfStock = 'out_of_stock';
    case Other = 'other';

    public static function fromMessageKey(string $messageKey): self
    {
        return match ($messageKey) {
            'min-order-quantity' => self::MinimumQuantity,
            'purchase-steps-quantity' => self::PurchaseSteps,
            'product-stock-reached' => self::StockLimited,
            'product-out-of-stock' => self::OutOfStock,
            default => self::Other,
        };
    }
}
