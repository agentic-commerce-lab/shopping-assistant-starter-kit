<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CartLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNotice;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureCategoryFilter;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureCategoryTree;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureFacetBuilder;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureIndex;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureQueryFilter;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureScopeFilter;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureVariantMatcher;

/**
 * In-memory {@see CommerceGatewayInterface} backed by a static JSON fixture.
 *
 * Lets every later task, and the eval suite, run against a deterministic catalog
 * with no shop and no database. Plan 2 later adds a Shopware DAL implementation
 * behind the same interface.
 */
// @mago-expect lint:too-many-methods
// Every public method here is mandated by an interface this class implements: six by
// CommerceGatewayInterface, one each by CategoryTreeReader, FamilyVariantLookup and MatchCountReader.
// The count is the sum of those obligations, not bloat, and four interfaces cannot be implemented in
// fewer methods. `DalCommerceGateway` carries the identical carve-out for the identical reason.
final class FixtureCommerceGateway implements
    CategoryTreeReader,
    CommerceGatewayInterface,
    FamilyVariantLookup,
    MatchCountReader
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
         *     variants: list<array{id: string, options: array<string, string>, price: float|int, stock: int, minPurchase?: int, purchaseSteps?: int}>,
         *     minPurchase?: int,
         *     purchaseSteps?: int,
         * }>} $decoded
         */
        $decoded = json_decode($json, associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);

        return new self(FixtureIndex::fromDecoded($decoded));
    }

    public function facets(CatalogScope $scope): FacetSet
    {
        $units = FixtureScopeFilter::apply($this->index->units(), $scope);

        return FixtureFacetBuilder::build($units);
    }

    public function search(ProductQuery $query, CatalogScope $scope): array
    {
        $units = FixtureScopeFilter::apply($this->index->units(), $scope);
        // After the scope, never before or instead of it: the shopper's location narrows what the
        // merchant already allowed, and cannot reach past it (P8).
        $units = FixtureCategoryFilter::apply($units, $query->categoryId);

        return FixtureQueryFilter::apply($units, $query);
    }

    /**
     * Everything the query matches, ignoring its limits.
     *
     * The same filters `search()` applies, in the same order — scope, then the shopper's aisle, then
     * the query's own clauses — with the limit removed. Reusing the query rather than re-deriving the
     * predicate is the point: a count that disagreed with the search it describes would be worse than
     * no count.
     */
    public function countMatches(ProductQuery $query, CatalogScope $scope): int
    {
        $units = FixtureScopeFilter::apply($this->index->units(), $scope);
        $units = FixtureCategoryFilter::apply($units, $query->categoryId);

        // A very large limit rather than a separate predicate: FixtureQueryFilter owns what "matches"
        // means, and a second implementation of that here is the copy that drifts.
        return \count(FixtureQueryFilter::apply($units, $query->withoutLimits()));
    }

    /**
     * The tree the scope allows, derived from the units it allows — scope first, exactly as
     * {@see self::search()} and {@see self::facets()} do it. A category whose only products are blocked
     * therefore disappears rather than appearing empty, which is the stricter and safer reading.
     *
     * @return list<CategoryNode>
     */
    public function categories(?string $parentId, CatalogScope $scope): array
    {
        return FixtureCategoryTree::childrenOf(FixtureScopeFilter::apply($this->index->units(), $scope), $parentId);
    }

    public function product(string $productId, CatalogScope $scope): ?ProductCard
    {
        $unit = $this->index->unit($productId);
        if ($unit === null) {
            return null;
        }

        // Reuses FixtureScopeFilter::apply() (already exercised by search()/facets())
        // rather than combining the null-check and the scope check into one inline
        // boolean expression here — that combined expression is what pushed this
        // class's aggregate cyclomatic complexity over its threshold.
        $inScope = FixtureScopeFilter::apply([$unit], $scope);

        return $inScope[0] ?? null;
    }

    /**
     * The whole family, scope-filtered — the same first step {@see self::resolveVariant()} takes
     * before it starts matching selections.
     *
     * @return list<ProductCard>
     */
    public function variantsOf(string $parentId, CatalogScope $scope): array
    {
        return FixtureScopeFilter::apply($this->index->unitsByParent($parentId), $scope);
    }

    public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
    {
        // Scope filtering and selection matching as two sequential, single-condition
        // filters rather than one combined boolean expression — same reasoning as
        // product() above. Selection matching itself lives in FixtureVariantMatcher,
        // not here — see that class's docblock.
        $inScope = FixtureScopeFilter::apply($this->index->unitsByParent($parentId), $scope);

        $matches = array_values(array_filter($inScope, static fn(ProductCard $unit): bool => FixtureVariantMatcher::matchesAll(
            $unit,
            $selections,
        )));

        return \count($matches) === 1 ? $matches[0] : null;
    }

    public function addToCart(string $variantId, int $quantity): CartSummary
    {
        $unit = $this->index->unit($variantId);
        if ($unit === null) {
            throw new \InvalidArgumentException(\sprintf('Unknown variant id "%s".', $variantId));
        }

        // Shopware does not refuse a quantity that breaks a product's purchase rules — it changes
        // it and records why (`ProductCartProcessor::validateStock()`). A fixture that stored the
        // requested quantity could not reproduce that, which is exactly why the tool's
        // misreporting survived every fixture test this project has.
        $corrected = self::fixQuantity($unit->minPurchase, $quantity, $unit->purchaseSteps);
        $notices = $corrected === $quantity
            ? []
            : [new CartNotice(
                $variantId,
                $quantity < $unit->minPurchase ? CartNoticeReason::MinimumQuantity : CartNoticeReason::PurchaseSteps,
            )];

        $existing = $this->cartLines[$variantId] ?? null;
        $newQuantity = ($existing === null ? 0 : $existing->quantity) + $corrected;

        $this->cartLines[$variantId] = new CartLine(
            lineId: $variantId,
            variantId: $variantId,
            name: $unit->name,
            quantity: $newQuantity,
            unitPrice: $unit->price,
            lineTotal: $newQuantity * $unit->price,
        );

        return $this->cart($notices);
    }

    /**
     * Shopware's own rounding, from `ProductCartProcessor::fixQuantity()`: raise to the minimum,
     * then step down to the nearest legal multiple above it.
     */
    private static function fixQuantity(int $min, int $quantity, int $steps): int
    {
        return (int) ($min + (floor(($quantity - $min) / $steps) * $steps));
    }

    /** @param list<CartNotice> $notices */
    public function cart(array $notices = []): CartSummary
    {
        $lines = array_values($this->cartLines);

        $total = 0.0;
        $itemCount = 0;
        foreach ($lines as $line) {
            $total += $line->lineTotal;
            $itemCount += $line->quantity;
        }

        return new CartSummary(lineItems: $lines, total: $total, itemCount: $itemCount, notices: $notices);
    }
}
