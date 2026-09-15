<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * A gateway that answers by search term and remembers which terms it was asked for.
 *
 * Its own file rather than an anonymous class inside the test, for the reason
 * {@see CountingFacetGateway} is: a method returning `CommerceGatewayInterface` erases the double's
 * own properties, and `$gateway->termsSearched` then reads as an access on the interface. Which
 * terms were searched, and in what order, is the whole point of {@see RelaxedTermRetryStepsTest}.
 */
final class RecordingTermGateway implements CommerceGatewayInterface
{
    /** @var list<string> */
    public array $termsSearched = [];

    /**
     * @param array<string, list<ProductCard>> $byTerm what to answer for each exact term; any term
     *                                                 not listed answers with nothing
     */
    public function __construct(
        private readonly array $byTerm = [],
    ) {}

    /** @return list<ProductCard> */
    public function search(ProductQuery $query, CatalogScope $scope): array
    {
        $term = (string) $query->term;
        $this->termsSearched[] = $term;

        return $this->byTerm[$term] ?? [];
    }

    public function facets(CatalogScope $scope): FacetSet
    {
        return new FacetSet([]);
    }

    public function product(string $productId, CatalogScope $scope): ?ProductCard
    {
        return null;
    }

    /** @param list<VariantSelection> $selections */
    public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
    {
        return null;
    }

    public function addToCart(string $variantId, int $quantity): CartSummary
    {
        throw new \LogicException('RecordingTermGateway is a search double; the cart is not part of it.');
    }

    public function cart(): CartSummary
    {
        throw new \LogicException('RecordingTermGateway is a search double; the cart is not part of it.');
    }
}
