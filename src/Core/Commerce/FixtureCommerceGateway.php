<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CartLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureFilter;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureIndex;

/**
 * In-memory {@see CommerceGatewayInterface} backed by a static JSON fixture.
 *
 * Lets every later task, and the eval suite, run against a deterministic catalog
 * with no shop and no database. Plan 2 later adds a Shopware DAL implementation
 * behind the same interface.
 */
final class FixtureCommerceGateway implements CommerceGatewayInterface
{
    /** @var array<string, CartLine> keyed by variant id */
    private array $cartLines = [];

    private function __construct(
        private readonly FixtureIndex $index,
    ) {}

    public static function fromFile(string $path): self
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException(\sprintf('Unable to read fixture catalog at "%s".', $path));
        }

        /**
         * @var array{products: list<array{
         *     id: string,
         *     name: string,
         *     description: string|null,
         *     price: float|int,
         *     stock: int,
         *     url: string,
         *     categoryPath: list<string>,
         *     properties: array<string, list<string>>,
         *     variants: list<array{id: string, options: array<string, string>, price: float|int, stock: int}>,
         * }>} $decoded
         */
        $decoded = json_decode($json, associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);

        return new self(FixtureIndex::fromDecoded($decoded));
    }

    public function facets(CatalogScope $scope): FacetSet
    {
        $units = FixtureFilter::inScope($this->index->units(), $scope);

        return FixtureFilter::buildFacets($units);
    }

    public function search(ProductQuery $query, CatalogScope $scope): array
    {
        $units = FixtureFilter::inScope($this->index->units(), $scope);

        return FixtureFilter::applyQuery($units, $query);
    }

    public function product(string $productId): ?ProductCard
    {
        return $this->index->unit($productId);
    }

    public function resolveVariant(string $parentId, array $selections): ?ProductCard
    {
        $matches = array_values(array_filter(
            $this->index->unitsByParent($parentId),
            static fn(ProductCard $unit): bool => self::matchesAllSelections($unit, $selections),
        ));

        return \count($matches) === 1 ? $matches[0] : null;
    }

    /** @param list<VariantSelection> $selections */
    private static function matchesAllSelections(ProductCard $unit, array $selections): bool
    {
        foreach ($selections as $selection) {
            if (!self::matchesSelection($unit, $selection)) {
                return false;
            }
        }

        return true;
    }

    private static function matchesSelection(ProductCard $unit, VariantSelection $selection): bool
    {
        if ($selection->group !== null) {
            return ($unit->options[$selection->group] ?? null) === $selection->option;
        }

        return \in_array($selection->option, $unit->options, strict: true);
    }

    public function addToCart(string $variantId, int $quantity): CartSummary
    {
        $unit = $this->index->unit($variantId);
        if ($unit === null) {
            throw new \InvalidArgumentException(\sprintf('Unknown variant id "%s".', $variantId));
        }

        $existing = $this->cartLines[$variantId] ?? null;
        $newQuantity = ($existing === null ? 0 : $existing->quantity) + $quantity;

        $this->cartLines[$variantId] = new CartLine(
            lineId: $variantId,
            variantId: $variantId,
            name: $unit->name,
            quantity: $newQuantity,
            unitPrice: $unit->price,
            lineTotal: $newQuantity * $unit->price,
        );

        return $this->cart();
    }

    public function cart(): CartSummary
    {
        $lines = array_values($this->cartLines);

        $total = 0.0;
        $itemCount = 0;
        foreach ($lines as $line) {
            $total += $line->lineTotal;
            $itemCount += $line->quantity;
        }

        return new CartSummary(lineItems: $lines, total: $total, itemCount: $itemCount);
    }
}
