<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * Counts calls to each gateway method and forwards them untouched.
 *
 * Exists for one number the spec asks for that is a count rather than a duration: the cards endpoint
 * performs one catalogue lookup **per id**, which is why `CardIdList::MAX_IDS` is 12. Counting at the
 * seam measures the assistant's own round trips; counting SQL would need a DBAL middleware and would
 * mix in every query Shopware's framework makes for its own reasons.
 *
 * `addToCart()` is forwarded like everything else rather than blocked, because a decorator that
 * silently changes behaviour is a worse thing to own than a rule the caller keeps: the benchmark is
 * read-only because {@see BenchmarkRunner} never calls it, and
 * `BenchmarkRunnerTest::testItNeverWritesToTheShop` is what holds that.
 *
 * Ten methods, which is this project's lint ceiling — `too-many-methods` fires at eleven. If another
 * counter is ever needed it belongs in the runner, not here.
 */
final class CountingGateway implements CommerceGatewayInterface
{
    /** @var array<string, int> */
    private array $calls = [];

    public function __construct(
        private readonly CommerceGatewayInterface $inner,
    ) {}

    public function callsTo(string $method): int
    {
        return $this->calls[$method] ?? 0;
    }

    public function totalCalls(): int
    {
        return array_sum($this->calls);
    }

    public function facets(CatalogScope $scope): FacetSet
    {
        $this->count('facets');

        return $this->inner->facets($scope);
    }

    /** @return list<ProductCard> */
    public function search(ProductQuery $query, CatalogScope $scope): array
    {
        $this->count('search');

        return $this->inner->search($query, $scope);
    }

    public function product(string $productId, CatalogScope $scope): ?ProductCard
    {
        $this->count('product');

        return $this->inner->product($productId, $scope);
    }

    /** @param list<VariantSelection> $selections */
    public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
    {
        $this->count('resolveVariant');

        return $this->inner->resolveVariant($parentId, $selections, $scope);
    }

    public function addToCart(string $variantId, int $quantity): CartSummary
    {
        $this->count('addToCart');

        return $this->inner->addToCart($variantId, $quantity);
    }

    public function cart(): CartSummary
    {
        $this->count('cart');

        return $this->inner->cart();
    }

    private function count(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }
}
