<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * Builds and holds the flat index of sellable units for {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway}.
 *
 * A sellable unit is either a variant (its own price, stock and options, with
 * {@see StockSource::Variant}) or, for a product carrying no variants, the product
 * itself ({@see StockSource::Product}). A variant inherits `name`, `description`,
 * `categoryPath` and `properties` from its parent and overrides `price`, `stock`,
 * `options` and `url`.
 *
 * **This index never emits {@see StockSource::Parent}**, because a family parent is not a sellable
 * unit: a product with variants contributes its variants and nothing else. The DAL gateway does
 * emit it — Shopware's search returns family parents alongside their children — which is why the
 * two must be different cases rather than one.
 *
 * @phpstan-type FixtureVariant array{
 *     id: string,
 *     options: array<string, string>,
 *     price: float|int,
 *     stock: int,
 *     minPurchase?: int,
 *     purchaseSteps?: int,
 * }
 * @phpstan-type FixtureProduct array{
 *     id: string,
 *     name: string,
 *     description: string|null,
 *     price: float|int,
 *     stock: int,
 *     url: string,
 *     categoryPath: list<string>,
 *     properties: array<string, list<string>>,
 *     variants: list<FixtureVariant>,
 *     minPurchase?: int,
 *     purchaseSteps?: int,
 * }
 */
final class FixtureIndex
{
    /** @param array<string, ProductCard> $units keyed by unit id */
    private function __construct(
        private readonly array $units,
    ) {}

    /** @param array{products: list<FixtureProduct>} $decoded */
    public static function fromDecoded(array $decoded): self
    {
        $units = [];
        foreach ($decoded['products'] as $product) {
            foreach (self::buildUnitsForProduct($product) as $unit) {
                $units[$unit->id] = $unit;
            }
        }

        return new self($units);
    }

    /**
     * @param FixtureProduct $product
     *
     * @return list<ProductCard>
     */
    private static function buildUnitsForProduct(array $product): array
    {
        if ($product['variants'] === []) {
            return [self::buildStandaloneUnit($product)];
        }

        return array_map(static fn(array $variant): ProductCard => self::buildVariantUnit(
            $product,
            $variant,
        ), $product['variants']);
    }

    /** @param FixtureProduct $product */
    private static function buildStandaloneUnit(array $product): ProductCard
    {
        return new ProductCard(
            id: $product['id'],
            parentId: null,
            name: $product['name'],
            description: $product['description'],
            price: (float) $product['price'],
            currency: 'EUR',
            stock: $product['stock'],
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: $product['url'],
            imageUrl: null,
            options: [],
            categoryPath: $product['categoryPath'],
            properties: $product['properties'],
            minPurchase: $product['minPurchase'] ?? 1,
            purchaseSteps: $product['purchaseSteps'] ?? 1,
        );
    }

    /**
     * @param FixtureProduct $product
     * @param FixtureVariant $variant
     */
    private static function buildVariantUnit(array $product, array $variant): ProductCard
    {
        return new ProductCard(
            id: $variant['id'],
            parentId: $product['id'],
            name: $product['name'],
            description: $product['description'],
            price: (float) $variant['price'],
            currency: 'EUR',
            stock: $variant['stock'],
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: \sprintf('/detail/%s', $variant['id']),
            imageUrl: null,
            options: $variant['options'],
            categoryPath: $product['categoryPath'],
            properties: $product['properties'],
            minPurchase: $variant['minPurchase'] ?? 1,
            purchaseSteps: $variant['purchaseSteps'] ?? 1,
        );
    }

    /** @return array<string, ProductCard> */
    public function units(): array
    {
        return $this->units;
    }

    public function unit(string $id): ?ProductCard
    {
        return $this->units[$id] ?? null;
    }

    /** @return list<ProductCard> */
    public function unitsByParent(string $parentId): array
    {
        return array_values(array_filter(
            $this->units,
            static fn(ProductCard $unit): bool => $unit->parentId === $parentId,
        ));
    }
}
