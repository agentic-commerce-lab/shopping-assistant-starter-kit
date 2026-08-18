<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * ProductCard is an ALLOWLIST, not a filtered entity.
 *
 * Shopware products carry fields the shopper must never see — `purchasePrices`
 * above all, plus custom fields holding margin or supplier cost. Because nothing
 * crosses the gateway except this DTO, those fields cannot leak by accident.
 *
 * NEVER widen this class with a passthrough array or a raw-entity property.
 */
final readonly class ProductCard
{
    /**
     * @param array<string, string>   $options      e.g. ['Colour' => 'Blue', 'Size' => 'M']
     * @param list<string>            $categoryPath
     * @param array<string, list<string>> $properties
     */
    // @mago-expect lint:excessive-parameter-list
    // The parameter list mirrors the allowlisted shopper-facing fields exactly (see class
    // docblock); splitting it into a sub-object would defeat the point of a flat allowlist.
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $name,
        public ?string $description,
        public float $price,
        public string $currency,
        public int $stock,
        public StockSource $stockSource,
        public ?string $deliveryTime,
        public string $url,
        public ?string $imageUrl,
        public array $options = [],
        public array $categoryPath = [],
        public array $properties = [],
    ) {}

    public function isInStock(): bool
    {
        return $this->stock > 0;
    }
}
